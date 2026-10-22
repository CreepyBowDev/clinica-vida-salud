<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\Consultorio;
use Clinica\data\repositories\consultorios\AsignacionConsultorioRepository;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class PlanificacionGarantiasTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-10-21T12:00:00Z');
    }

    private function contexto(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $base = '/api/medicos/'.$r['doctors'][0];
        $version = $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $version];

        return [$cookies, $r, $base, $propuesta, $actor];
    }

    private function estado(): array
    {
        $estado = [];
        foreach (['consultorios', 'medicos', 'horarios_atencion', 'configuracion_clinica', 'citas', 'cambios_cita', 'recordatorios'] as $tabla) {
            $estado[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $estado['eventos'] = DB::table('bitacora')->whereIn('modulo', ['CONSULTORIOS', 'HORARIOS'])->count();

        return $estado;
    }

    public static function controles(): array
    {
        return [['restringida'], ['desactivada'], ['sesion_revocada'], ['origen'], ['csrf']];
    }

    #[DataProvider('controles')]
    public function test_entire_planning_api_preserves_security_controls_and_never_mutates_on_denial(string $control): void
    {
        [$cookies, $r, $base, $propuesta, $actor] = $this->contexto();
        $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->json('data.huella_impacto');
        $sala = $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertOk()->json('data');
        $config = $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        $h = (string) DB::table('horarios_atencion')->value('id_horario');
        $version = ['actualizado_en_original' => $propuesta['actualizado_en_original']];
        $intervalo = [...$version, 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00'];
        $duracion = ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']];
        $operaciones = [
            ['GET', '/api/consultorios', []], ['POST', '/api/consultorios', ['codigo_consultorio' => 'NO', 'nombre' => 'No guardar']],
            ['GET', '/api/consultorios/'.$r['rooms'][0], []],
            ['PATCH', '/api/consultorios/'.$r['rooms'][0], ['nombre' => 'No guardar', 'actualizado_en_original' => $sala['actualizado_en']]],
            ['PATCH', '/api/consultorios/'.$r['rooms'][0].'/estado', ['activo' => false, 'actualizado_en_original' => $sala['actualizado_en']]],
            ['GET', $base.'/consultorio', []], ['POST', $base.'/consultorio/impacto', $propuesta],
            ['PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $huella, 'confirmar_traslados' => false]],
            ['GET', $base.'/horarios', []], ['POST', $base.'/horarios', $intervalo],
            ['PATCH', $base.'/horarios/'.$h, [...$version, 'hora_fin' => '13:00:00']],
            ['PATCH', $base.'/horarios/'.$h.'/estado', [...$version, 'activo' => false]],
            ['POST', $base.'/horarios/impacto', [...$intervalo, 'accion' => 'CREAR']],
            ['GET', '/api/configuracion-citas', []], ['POST', '/api/configuracion-citas/impacto', $duracion], ['PATCH', '/api/configuracion-citas', $duracion],
        ];
        if ($control === 'restringida') {
            $target = $this->usuarioAuth();
            $secret = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $target, $secret)->assertOk();
        } elseif ($control === 'desactivada') {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        } elseif ($control === 'sesion_revocada') {
            DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        }
        $antes = $this->estado();
        $cuenta = 0;
        foreach ($operaciones as [$metodo, $ruta, $datos]) {
            if ($control === 'csrf' && $metodo === 'GET') {
                continue;
            }
            $status = match ($control) {
                'restringida', 'origen' => 403, 'csrf' => 419, default => 401,
            };
            $respuesta = $this->navegador($cookies, $metodo, $ruta, $datos, csrf: $control !== 'csrf',
                headers: $control === 'origen' ? ['Origin' => 'https://ajeno.example.test'] : []);
            $respuesta->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('success', false);
            if ($control === 'restringida') {
                $respuesta->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
            }
            $cuenta++;
        }
        $this->assertSame($control === 'csrf' ? 11 : 16, $cuenta);
        $this->assertSame($antes, $this->estado());
    }

    public static function fallosPersistencia(): array
    {
        return [['exclusion'], ['inesperado']];
    }

    #[DataProvider('fallosPersistencia')]
    public function test_late_postgresql_errors_are_translated_selectively_and_rollback_transfer(string $tipo): void
    {
        [$cookies, $r, $base, $propuesta] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r));
        $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->json('data.huella_impacto');
        $antes = $this->estado();
        $repo = Mockery::mock(AsignacionConsultorioRepository::class)->makePartial();
        $repo->shouldReceive('trasladar')->once()->andReturnUsing(function (Cita $cita, Consultorio $destino, int|string $actor, CarbonImmutable $ahora) use ($tipo, $r): void {
            if ($tipo === 'exclusion') {
                // Inyección tardía de ocupación para ejercitar la barrera GiST real y su traducción.
                DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]));
            }
            (new AsignacionConsultorioRepository)->trasladar($cita, $destino, $actor, $ahora);
            if ($tipo === 'inesperado') {
                DB::statement('SELECT 1/0');
            }
        });
        $this->app->instance(AsignacionConsultorioRepository::class, $repo);
        $this->navegador($cookies, 'PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $huella, 'confirmar_traslados' => true])
            ->assertStatus($tipo === 'exclusion' ? 409 : 500)->assertJsonPath('error.code', $tipo === 'exclusion' ? 'PLANIFICACION_CONFLICTO' : 'ERROR_INTERNO')
            ->assertDontSee('SQLSTATE')->assertDontSee('SELECT 1/0')->assertDontSee('citas_consultorio_no_solapado_excl');
        $this->assertSame($antes, $this->estado());
    }

    public function test_projected_occupancy_sweep_keeps_all_conflicts_and_allows_contiguous_intervals(): void
    {
        [$cookies, $r, $base, $propuesta] = $this->contexto();
        foreach (['12:00:00', '12:30:00', '13:00:00'] as $hora) {
            $inicio = CarbonImmutable::parse('2026-11-01T'.$hora.'Z');
            DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15)]));
        }
        $contigua = null;
        foreach ([['11:59:00', 2], ['12:15:00', 15], ['12:35:00', 5], ['12:59:00', 2]] as [$hora, $duracion]) {
            $inicio = CarbonImmutable::parse('2026-11-01T'.$hora.'Z');
            $id = DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1],
                'inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes($duracion), 'duracion_minutos' => $duracion]), 'id_cita');
            if ($hora === '12:15:00') {
                $contigua = (string) $id;
            }
        }
        $impacto = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->assertJsonPath('data.puede_confirmar', false)->json('data');
        $pares = array_filter($impacto['elementos'], fn ($e) => isset($e['id_otra_cita']));
        $this->assertCount(3, $pares);
        $this->assertNotContains($contigua, array_column($pares, 'id_otra_cita'));
        $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', [...$propuesta, 'per_page' => 1, 'page' => 2])->assertOk()->assertJsonPath('data.huella_impacto', $impacto['huella_impacto']);
        $antes = $this->estado();
        $this->navegador($cookies, 'PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true])->assertConflict()->assertJsonPath('error.code', 'PLANIFICACION_CONFLICTO');
        $this->assertSame($antes, $this->estado());
    }

    public function test_complete_impact_has_constant_query_count_and_measures_actual_occupancy_plan(): void
    {
        [$cookies, $r, $base, $propuesta] = $this->contexto();
        $mediciones = [];
        foreach ([30, 300] as $cantidad) {
            $actual = DB::table('citas')->count();
            $filas = [];
            for ($i = $actual; $i < $cantidad; $i++) {
                $inicio = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addWeeks($i);
                $filas[] = $this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15), 'motivo_breve' => 'Contenido ficticio privado']);
            }
            DB::table('citas')->insert($filas);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $inicio = hrtime(true);
            try {
                $respuesta = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', [...$propuesta, 'per_page' => 1])->assertOk()
                    ->assertJsonPath('data.puede_confirmar', true)->assertJsonPath('data.totales.citas_trasladadas', $cantidad)->assertJsonCount(1, 'data.elementos');
                $consultas = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertStringNotContainsString('Contenido ficticio privado', $respuesta->getContent());
            $mediciones[$cantidad] = ['consultas' => count($consultas), 'ms_http' => round((hrtime(true) - $inicio) / 1000000, 2)];
            // El diagnóstico no hace una consulta clínica por cada cita.
            $this->assertCount(1, array_filter($consultas, fn ($q) => str_contains($q['query'], 'as "consulta_exists"')));
        }
        $this->assertSame($mediciones[30]['consultas'], $mediciones[300]['consultas']);
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id_cita FROM citas WHERE estado <> ? AND fin_at > ? AND (id_medico = ? OR id_consultorio IN (?, ?)) ORDER BY id_cita FOR UPDATE',
            ['Cancelada', '2026-10-21T12:00:00Z', $r['doctors'][0], ...$r['rooms']]);
        $json = json_decode($plan[0]->{'QUERY PLAN'}, true)[0];
        $this->assertSame(300, (int) $json['Plan']['Actual Rows']);
        $resumen = ['filas' => $json['Plan']['Actual Rows'], 'bloques_compartidos' => ($json['Plan']['Shared Hit Blocks'] ?? 0) + ($json['Plan']['Shared Read Blocks'] ?? 0),
            'ms_ejecucion' => $json['Execution Time'], 'impacto' => $mediciones];
        fwrite(STDOUT, "\nPlanificación: medición local ficticia: ".json_encode($resumen)."\n");
    }
}
