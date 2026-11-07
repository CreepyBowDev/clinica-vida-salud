<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\business\services\reportes\ReporteService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\data\repositories\reportes\ReporteRepository;
use Clinica\presentation\dtos\reportes\ReportePeriodoRequest;
use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// PHPUnit puro: sin kernel Laravel, credenciales, conexión SQL ni migraciones.
class ReportesFechasHistoricasTest extends TestCase
{
    public static function dias(): array
    {
        return [
            ['0001-01-01', '0001-01-01T04:32:36.000000Z', '0001-01-02T04:32:36.000000Z'],
            ['1900-01-01', '1900-01-01T04:32:36.000000Z', '1900-01-02T04:32:36.000000Z'],
            ['1931-10-15', '1931-10-15T04:32:36.000000Z', '1931-10-16T03:32:36.000000Z'],
            ['1932-03-20', '1932-03-20T03:32:36.000000Z', '1932-03-21T04:00:00.000000Z'],
            ['9999-12-30', '9999-12-30T04:00:00.000000Z', '9999-12-31T04:00:00.000000Z'],
        ];
    }

    #[DataProvider('dias')]
    public function test_period_and_daily_series_use_real_local_boundaries(string $fecha, string $inicio, string $fin): void
    {
        $clase = new ReflectionClass(ReporteService::class);
        $servicio = $clase->newInstanceWithoutConstructor();
        $periodo = $clase->getMethod('periodo')->invoke($servicio, ['fecha' => $fecha], 'citas.inicio_at', 'inicio_en_periodo');
        $this->assertSame($inicio, $periodo['contexto']['periodo']['inicio_at']);
        $this->assertSame($fin, $periodo['contexto']['periodo']['fin_exclusivo_at']);
        $serie = $clase->getMethod('serieTemporal')->invoke($servicio, $periodo['contexto']['periodo'], []);
        $this->assertSame('diaria', $serie['agrupacion']);
        $this->assertCount(1, $serie['categorias']);
        $this->assertSame(['desde' => $fecha, 'hasta' => $fecha, 'inicio_at' => $inicio,
            'fin_exclusivo_at' => $fin, 'parcial' => false], $serie['categorias'][0]['intervalo']);
    }

    public function test_daily_series_does_not_carry_the_missing_midnight_hour_to_later_days(): void
    {
        $clase = new ReflectionClass(ReporteService::class);
        $serie = $clase->getMethod('serieTemporal')->invoke($clase->newInstanceWithoutConstructor(),
            ['desde' => '1931-10-14', 'hasta' => '1931-10-16'], []);
        $intervalos = array_column($serie['categorias'], 'intervalo');
        $this->assertSame(['1931-10-14', '1931-10-15', '1931-10-16'], array_column($intervalos, 'desde'));
        $this->assertSame('1931-10-16T03:32:36.000000Z', $intervalos[1]['fin_exclusivo_at']);
        $this->assertSame($intervalos[1]['fin_exclusivo_at'], $intervalos[2]['inicio_at']);
        $this->assertSame('1931-10-17T03:32:36.000000Z', $intervalos[2]['fin_exclusivo_at']);
    }

    public static function agrupaciones(): array
    {
        return [['1931-09-14', '1931-10-14', 'diaria', 31],
            ['1931-09-14', '1931-10-15', 'mensual', 2],
            ['0004-02-01', '0004-03-03', 'mensual', 2],
            ['0099-12-20', '0100-01-20', 'mensual', 2]];
    }

    #[DataProvider('agrupaciones')]
    public function test_series_and_repository_agree_on_civil_day_grouping(string $desde, string $hasta, string $agrupacion, int $cantidad): void
    {
        $clase = new ReflectionClass(ReporteService::class);
        $servicio = $clase->newInstanceWithoutConstructor();
        $periodo = $clase->getMethod('periodo')->invoke($servicio, ['desde' => $desde, 'hasta' => $hasta], 'pacientes.creado_en', 'registro_en_periodo');
        $serie = $clase->getMethod('serieTemporal')->invoke($servicio, $periodo['contexto']['periodo'], []);
        $this->assertSame($agrupacion, $serie['agrupacion']);
        $this->assertCount($cantidad, $serie['categorias']);
        $this->assertSame($hasta, $serie['categorias'][$cantidad - 1]['intervalo']['hasta']);

        $db = Mockery::mock();
        $formato = $agrupacion === 'diaria' ? 'YYYY-MM-DD' : 'YYYY-MM';
        $db->shouldReceive('selectOne')->once()->withArgs(function (string $sql, array $bindings) use ($formato): bool {
            $this->assertStringContainsString("'$formato'", $sql);
            $this->assertSame([20, 0], $bindings);

            return true;
        })->andReturn((object) ['total_reporte' => '0', 'minutos' => null, 'filas' => '[]', 'grupos' => '[]', 'actividad' => '[]']);
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('toSql')->once()->andReturn('SELECT id_paciente, creado_en, activo FROM pacientes');
        $query->shouldReceive('getBindings')->once()->andReturn([]);
        $this->sinConexion($db, function () use ($query, $periodo): void {
            $repo = new ReflectionClass(ReporteRepository::class);
            $resultado = $repo->getMethod('paginar')->invoke($repo->newInstanceWithoutConstructor(), $query, [],
                'creado_en ASC, id_paciente ASC', 'pacientes/registrados', $periodo['inicio'], $periodo['fin']);
            $this->assertSame(0, $resultado['pagina']->total());
        });
    }

    #[DataProvider('dias')]
    public function test_operational_and_report_agenda_use_the_same_correct_day(string $fecha, string $inicio, string $fin): void
    {
        $actor = (new Usuario)->forceFill(['id_usuario' => 1]);
        $actor->setRelation('rol', (new Rol)->forceFill(['nombre' => 'Administrador']));
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('exigirSesionNormal')->twice()->with(true)->andReturn($actor);
        $repo = Mockery::mock(AgendaRepository::class);
        $repo->shouldReceive('medico')->twice()->with('2')->andReturn(['id_medico' => 2,
            'codigo_medico' => 'MED-FICTICIO', 'nombres' => 'Persona', 'apellidos' => 'Ficticia']);
        $pagina = new LengthAwarePaginator([], 0, 20);
        foreach (['citas', 'citasConEstadisticas'] as $metodo) {
            $repo->shouldReceive($metodo)->once()->withArgs(function (string $id, CarbonImmutable $desde, CarbonImmutable $hasta, array $filtros) use ($inicio, $fin): bool {
                $this->assertSame('2', $id);
                $this->assertSame($inicio, $desde->utc()->format('Y-m-d\TH:i:s.u\Z'));
                $this->assertSame($fin, $hasta->utc()->format('Y-m-d\TH:i:s.u\Z'));

                return true;
            })->andReturn($metodo === 'citas' ? $pagina : ['pagina' => $pagina, 'agregados' => []]);
        }
        $db = Mockery::mock();
        $db->shouldReceive('transaction')->twice()->andReturnUsing(fn (callable $operacion) => $operacion());
        $this->sinConexion($db, function () use ($auth, $repo, $fecha): void {
            $servicio = new AgendaService($auth, $repo);
            $this->assertSame(0, $servicio->consultar(['id_medico' => '2', 'fecha' => $fecha])['pagina']->total());
            $this->assertSame(0, $servicio->consultarParaReporte(['id_medico' => '2', 'fecha' => $fecha])['pagina']->total());
        });
    }

    public static function limites(): array
    {
        return [['1930-10-15', '1931-10-15', true], ['1930-10-14', '1931-10-15', false]];
    }

    #[DataProvider('limites')]
    public function test_maximum_period_counts_civil_dates_even_across_a_midnight_jump(string $desde, string $hasta, bool $valido): void
    {
        $request = new class extends ReportePeriodoRequest
        {
            public function rules(): array
            {
                return ['desde' => ['date_format:Y-m-d'], 'hasta' => ['date_format:Y-m-d']];
            }
        };
        $request->initialize(['desde' => $desde, 'hasta' => $hasta]);
        $factory = new Factory(new Translator(new ArrayLoader, 'es'));
        $validator = $factory->make($request->all(), $request->rules());
        $request->withValidator($validator);
        $this->assertSame($valido, $validator->passes());
    }

    private function sinConexion(object $db, callable $operacion): void
    {
        $anterior = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('db', $db); // Solo dobles explícitos; no DatabaseManager ni PDO.
        Facade::setFacadeApplication($container);
        DB::clearResolvedInstance('db');
        try {
            $operacion();
        } finally {
            DB::clearResolvedInstance('db');
            Facade::setFacadeApplication($anterior);
            Mockery::close();
        }
    }
}
