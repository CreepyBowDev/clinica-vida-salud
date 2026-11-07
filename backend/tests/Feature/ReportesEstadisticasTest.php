<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\data\repositories\reportes\ReporteRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\Fixtures\ReportesAisladosFixtures;
use Tests\TestCase;

class ReportesEstadisticasTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures, ReportesAisladosFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aislarReportes();
        CarbonImmutable::setTestNow('2026-11-02T02:00:00.123456Z');
    }

    private function contexto(string $rol = 'Administrador'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['creado_en' => '2026-11-01T12:00:00Z']);

        return [$cookies, $r];
    }

    private function filtros(string $tipo, array $r): array
    {
        return ['fecha' => '2026-11-01', ...match ($tipo) {
            'citas/por-medico', 'agenda-diaria' => ['id_medico' => (string) $r['doctors'][0]],
            'citas/por-especialidad' => ['id_especialidad' => (string) $r['specialty']],
            'citas/por-estado' => ['estado' => 'Cancelada'],
            default => [],
        }];
    }

    private function consultar(array &$cookies, string $tipo, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/reportes/'.$tipo.'?'.http_build_query($datos));
    }

    private function cita(array $r, string $estado, int $hora = 8, int $minutos = 15, array $extra = []): string
    {
        $inicio = CarbonImmutable::parse('2026-11-01T04:00:00Z')->addHours($hora);

        return (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado,
            'inicio_at' => $inicio->format('Y-m-d H:i:s.uP'), 'fin_at' => $inicio->addMinutes($minutos)->format('Y-m-d H:i:s.uP'),
            'duracion_minutos' => $minutos, ...$extra]), 'id_cita');
    }

    private function conciliar(array $e, int $total, ?int $minutos): void
    {
        $this->assertSame(['version', 'unidad', 'estados_incluidos', 'resumen', 'series'], array_keys($e));
        $this->assertSame(1, $e['version']);
        $this->assertSame(['cantidad_total' => $total, 'duracion_total_minutos' => $minutos], $e['resumen']);
        foreach ($e['series'] as $serie) {
            $this->assertSame(['id', 'dimension', 'agrupacion', 'porcentajes', 'grafico', 'categorias'], array_keys($serie));
            $this->assertSame($total, array_sum(array_column($serie['categorias'], 'cantidad')));
            if ($minutos !== null) {
                $this->assertSame($minutos, array_sum(array_column($serie['categorias'], 'duracion_minutos')));
            }
            $positivas = count(array_filter($serie['categorias'], fn ($c) => $c['cantidad'] > 0));
            $this->assertSame(['tipo' => 'barras_horizontales', 'visible' => $positivas >= 2,
                'motivo_omision' => $positivas >= 2 ? null : ($positivas ? 'unica_categoria_con_datos' : 'sin_datos'),
                'maximo' => $serie['categorias'] ? max(array_column($serie['categorias'], 'cantidad')) : 0], $serie['grafico']);
            foreach ($serie['categorias'] as $c) {
                $this->assertSame(['id', 'etiqueta', 'cantidad', 'duracion_minutos', 'porcentaje', match ($serie['dimension']) {
                    'medico' => 'medico', 'estado' => 'estado', 'actividad' => 'activo', 'periodo' => 'intervalo',
                }], array_keys($c));
                $this->assertIsString($c['id']);
                $this->assertIsInt($c['cantidad']);
                $this->assertGreaterThanOrEqual(0, $c['cantidad']);
                if ($minutos === null) {
                    $this->assertNull($c['duracion_minutos']);
                }
            }
        }
    }

    public static function tipos(): array
    {
        return [['citas/programadas', 1, null], ['citas/por-medico', 5, 130], ['citas/por-especialidad', 6, null],
            ['citas/por-estado', 2, null], ['pacientes/registrados', 3, null], ['citas/cantidades', 4, null], ['agenda-diaria', 5, 130]];
    }

    #[DataProvider('tipos')]
    public function test_complete_statistics_survive_pages_and_single_sql_envelope(string $tipo, int $total, ?int $minutos): void
    {
        [$cookies, $r] = $this->contexto();
        foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia', 'Cancelada'] as $i => $estado) {
            $this->cita($r, $estado, 2 * $i, [40, 15, 20, 25, 30, 35][$i], $i === 5 ? ['id_medico' => $r['doctors'][1]] : []);
        }
        foreach ([true, false] as $activo) {
            DB::table('pacientes')->insert(['codigo_paciente' => 'EST-'.($activo ? 'A' : 'I'), 'nombres' => 'Ficha ficticia',
                'apellidos' => 'Estadística', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
                'creado_en' => '2026-11-01T15:00:00Z', 'activo' => $activo]);
        }
        $originales = null;
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'WITH filtradas') || str_contains($q->sql, 'COUNT(*) FILTER')) {
                $queries[] = $q->sql;
            }
        });
        foreach ($tipo === 'citas/cantidades' ? [1] : [1, 2, 1000] as $page) {
            $queries = [];
            $datos = [...$this->filtros($tipo, $r), ...($tipo === 'citas/cantidades' ? [] : ['per_page' => $page === 2 ? 2 : 1, 'page' => $page])];
            $res = $this->consultar($cookies, $tipo, $datos)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $e = $res->json('meta.estadisticas');
            $this->conciliar($e, $total, $minutos);
            $this->assertSame($tipo === 'pacientes/registrados' ? 'fichas' : 'citas', $e['unidad']);
            $this->assertSame($total, $res->json($tipo === 'citas/cantidades' ? 'data.total' : 'meta.total'));
            if ($originales !== null) {
                $this->assertSame($originales, $e);
            }
            $originales = $e;
            $this->assertCount(1, $queries); // Estructural: no carrera multiproceso.
            if ($tipo !== 'citas/cantidades') {
                $this->assertStringContainsString('AS MATERIALIZED', $queries[0]);
                $this->assertStringContainsString('jsonb_agg', $queries[0]);
                $this->assertMatchesRegularExpression('/FROM filtradas\s+GROUP BY/', $queries[0]);
            }
            if ($page === 1000) {
                $res->assertJsonCount(0, 'data');
            }
        }
    }

    #[DataProvider('tipos')]
    public function test_empty_contract_keeps_zeros_and_closed_variants(string $tipo, int $unused, ?int $minutos): void
    {
        [$cookies, $r] = $this->contexto();
        $e = $this->consultar($cookies, $tipo, [...$this->filtros($tipo, $r), 'fecha' => '2026-11-03'])
            ->assertOk()->json('meta.estadisticas');
        $this->conciliar($e, 0, $minutos === null ? null : 0);
        $this->assertSame(match ($tipo) {
            'citas/programadas', 'citas/por-especialidad' => ['por_medico'],
            'citas/por-estado' => ['por_periodo'], 'pacientes/registrados' => ['por_actividad', 'por_periodo'],
            default => ['por_estado'],
        }, array_column($e['series'], 'id'));
        $this->assertSame(match ($tipo) {
            'citas/programadas' => ['Programada'], 'citas/por-estado' => ['Cancelada'], 'pacientes/registrados' => [],
            'citas/cantidades' => ['Atendida', 'Cancelada', 'Inasistencia'],
            default => ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'],
        }, $e['estados_incluidos']);
        $this->assertCount(match ($tipo) {
            'citas/programadas', 'citas/por-especialidad' => 0, 'citas/por-estado' => 1,
            'pacientes/registrados' => 2, 'citas/cantidades' => 3, default => 5,
        }, $e['series'][0]['categorias']);
    }

    public static function temporales(): array
    {
        $casos = [];
        foreach (['citas/por-estado', 'pacientes/registrados'] as $tipo) {
            foreach ([['2026-01-01', '2026-01-01', 'diaria', 1], ['2026-01-20', '2026-02-19', 'diaria', 31],
                ['2026-01-20', '2026-02-20', 'mensual', 2], ['2023-12-20', '2024-12-19', 'mensual', 13],
                ['2024-02-28', '2024-03-01', 'diaria', 3], ['2026-12-31', '2027-01-01', 'diaria', 2]] as $caso) {
                $casos[] = [$tipo, ...$caso];
            }
        }

        return $casos;
    }

    #[DataProvider('temporales')]
    public function test_temporal_boundaries_zero_intervals_and_partial_months(string $tipo, string $desde, string $hasta, string $agrupacion, int $n): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['creado_en' => '2000-01-01T00:00:00Z']);
        $inicio = CarbonImmutable::parse($desde, 'America/La_Paz')->utc();
        $fin = CarbonImmutable::parse($hasta, 'America/La_Paz')->addDay()->utc();
        foreach ([$inicio->subMicrosecond(), $inicio, $fin->subMicrosecond(), $fin] as $i => $fecha) {
            $at = $fecha->format('Y-m-d H:i:s.uP');
            if ($tipo === 'citas/por-estado') {
                $this->cita($r, 'Cancelada', extra: ['inicio_at' => $at, 'fin_at' => $fecha->addMinutes(15)->format('Y-m-d H:i:s.uP')]);
            } else {
                DB::table('pacientes')->insert(['codigo_paciente' => 'TEMP-'.$i, 'nombres' => 'Temporal ficticio', 'apellidos' => 'Prueba',
                    'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'creado_en' => $at, 'activo' => $i !== 2]);
            }
        }
        $datos = $this->filtros($tipo, $r);
        unset($datos['fecha']);
        $res = $this->consultar($cookies, $tipo, [...$datos, 'desde' => $desde, 'hasta' => $hasta])->assertOk();
        $e = $res->json('meta.estadisticas');
        $this->conciliar($e, 2, null);
        $serie = $e['series'][$tipo === 'pacientes/registrados' ? 1 : 0];
        $this->assertSame($agrupacion, $serie['agrupacion']);
        $this->assertCount($n, $serie['categorias']);
        $anterior = $res->json('meta.periodo.inicio_at');
        foreach ($serie['categorias'] as $c) {
            $intervalo = $c['intervalo'];
            $this->assertSame($anterior, $intervalo['inicio_at']);
            $anterior = $intervalo['fin_exclusivo_at'];
            $a = CarbonImmutable::parse($intervalo['desde']);
            $b = CarbonImmutable::parse($intervalo['hasta']);
            $this->assertSame($agrupacion === 'mensual' && ($a->day !== 1 || $b->day !== $b->daysInMonth), $intervalo['parcial']);
            $this->assertSame(($agrupacion === 'diaria' ? 'dia:' : 'mes:').$a->format($agrupacion === 'diaria' ? 'Y-m-d' : 'Y-m'), $c['id']);
            $this->assertSame($a->format($agrupacion === 'diaria' ? 'Y-m-d' : 'Y-m').($intervalo['parcial'] ? ' (parcial)' : ''), $c['etiqueta']);
        }
        $this->assertSame($res->json('meta.periodo.fin_exclusivo_at'), $anterior);
        if ($n > 2) {
            $this->assertSame(0, $serie['categorias'][1]['cantidad']);
        }
        if ($tipo === 'pacientes/registrados') {
            $res = $this->consultar($cookies, $tipo, [...$datos, 'desde' => $desde, 'hasta' => $hasta, 'activo' => '0'])->assertOk();
            $this->conciliar($res->json('meta.estadisticas'), 1, null);
            $this->assertSame([0, 1], array_column($res->json('meta.estadisticas.series.0.categorias'), 'cantidad'));
        }
    }

    public static function porcentajes(): array
    {
        return [[[0, 0, 0], [null, null, null]], [[1, 1, 1], ['33.3', '33.3', '33.3']],
            [[1, 0, 15], ['6.3', '0.0', '93.8']], [[1, 1, 4], ['16.7', '16.7', '66.7']]];
    }

    #[DataProvider('porcentajes')]
    public function test_exact_final_state_percentages_and_no_adjustment(array $cantidades, array $esperados): void
    {
        [$cookies, $r] = $this->contexto();
        $hora = 0;
        foreach (['Atendida', 'Cancelada', 'Inasistencia'] as $i => $estado) {
            for ($j = 0; $j < $cantidades[$i]; $j++) {
                $this->cita($r, $estado, $hora++);
            }
        }
        $this->cita($r, 'Programada', 20);
        $this->cita($r, 'Confirmada', 21);
        $e = $this->consultar($cookies, 'citas/cantidades', ['fecha' => '2026-11-01'])->assertOk()->json('meta.estadisticas');
        $this->conciliar($e, array_sum($cantidades), null);
        $this->assertSame(['denominador' => array_sum($cantidades), 'decimales' => 1, 'redondeo' => 'HALF_UP'], $e['series'][0]['porcentajes']);
        $this->assertSame($esperados, array_column($e['series'][0]['categorias'], 'porcentaje'));
    }

    public function test_doctor_identity_numeric_tie_order_historical_specialty_and_bigint_rows(): void
    {
        [$cookies, $r] = $this->contexto();
        $doctor = (array) DB::table('medicos')->where('id_medico', $r['doctors'][0])->first();
        foreach ([10, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29] as $id) {
            DB::table('medicos')->insert([...$doctor, 'id_medico' => $id, 'codigo_medico' => 'EST-MED-'.$id]);
            DB::table('medico_especialidad')->insert(['id_medico' => $id, 'id_especialidad' => $r['specialty'], 'habilitada' => false]);
        }
        DB::table('medicos')->update(['activo' => false]);
        foreach ([2, 10, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29] as $i => $id) {
            $this->cita($r, 'Cancelada', extra: ['id_medico' => $id, 'id_cita' => (string) (9007199254740993 + $i)]);
        }
        $res = $this->consultar($cookies, 'citas/por-especialidad', $this->filtros('citas/por-especialidad', $r))->assertOk();
        $e = $res->json('meta.estadisticas');
        $this->conciliar($e, 12, null);
        $this->assertSame(['2', '10', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29'],
            array_column(array_column($e['series'][0]['categorias'], 'medico'), 'id_medico'));
        $this->assertSame('9007199254740993', $res->json('data.0.id_cita'));
        $c = $e['series'][0]['categorias'][1];
        $this->assertSame($doctor['apellidos'].', '.$doctor['nombres'].' — EST-MED-10', $c['etiqueta']);
    }

    public function test_agenda_uses_intersection_full_historical_duration_and_canonical_zero_states(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, 'Cancelada', extra: ['inicio_at' => '2026-11-01T03:50:00.000001Z',
            'fin_at' => '2026-11-01T04:30:00.000001Z', 'duracion_minutos' => 40]);
        $this->cita($r, 'Cancelada', extra: ['inicio_at' => '2026-11-01T03:45:00Z', 'fin_at' => '2026-11-01T04:00:00Z']);
        $this->cita($r, 'Cancelada', extra: ['inicio_at' => '2026-11-02T04:00:00Z', 'fin_at' => '2026-11-02T04:15:00Z']);
        $datos = [...$this->filtros('agenda-diaria', $r), 'estado' => 'Cancelada'];
        $e = $this->consultar($cookies, 'agenda-diaria', $datos)->assertOk()->json('meta.estadisticas');
        $this->conciliar($e, 1, 40);
        $this->assertSame(['Cancelada'], $e['estados_incluidos']);
        $this->assertSame([0, 0, 0, 1, 0], array_column($e['series'][0]['categorias'], 'cantidad'));
        $this->assertSame([0, 0, 0, 40, 0], array_column($e['series'][0]['categorias'], 'duracion_minutos'));
        $operativa = $this->navegador($cookies, 'GET', '/api/agenda?'.http_build_query($datos))->assertOk();
        $operativa->assertJsonMissingPath('meta.estadisticas');
        $this->assertSame(1, $operativa->json('meta.total'));
        $e = $this->consultar($cookies, 'citas/por-medico', $datos)->assertOk()->json('meta.estadisticas');
        $this->conciliar($e, 0, 0); // Inicio fuera del día aunque intersecta la agenda.
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_internal_report_entry_does_not_extend_operational_permissions(string $rol): void
    {
        [$cookies, $r] = $this->contexto($rol);
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No tienes permiso');
        app(AgendaService::class)->consultarParaReporte($this->filtros('agenda-diaria', $r));
    }

    public function test_unrepresentable_aggregate_is_a_safe_internal_error(): void
    {
        [$cookies] = $this->contexto();
        $repo = Mockery::mock(ReporteRepository::class)->makePartial();
        $repo->shouldReceive('cantidades')->once()->andReturn(['atendidas' => '9007199254740992', 'canceladas' => '0',
            'inasistencias' => '0', 'total' => '9007199254740992']);
        $this->app->instance(ReporteRepository::class, $repo);
        $res = $this->consultar($cookies, 'citas/cantidades', ['fecha' => '2026-11-01'])->assertStatus(500)
            ->assertJsonPath('error.code', 'ERROR_INTERNO')->assertJsonMissingPath('data');
        $this->assertStringNotContainsString('9007199254740992', $res->getContent());
    }
}
