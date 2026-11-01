<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\shared\utils\TransportePush;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReportesIntegracionTest extends TestCase
{
    use AutenticacionFixtures { navegador as private navegadorBase; }
    use CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $identity = DB::selectOne('SELECT current_database() AS db, current_user AS usuario');
        $this->assertSame('clinica_vida_salud_test', $identity->db);
        $this->assertSame('clinica_vida_salud_test', $identity->usuario);
        $schemas = DB::select('SELECT nspname FROM pg_namespace ORDER BY nspname');
        $previous = DB::selectOne('SHOW search_path')->search_path;
        $schema = 'prueba_reportes_integracion_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        $this->beforeApplicationDestroyed(function () use ($schema, $previous, $schemas): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $previous]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            $this->assertEquals($schemas, DB::select('SELECT nspname FROM pg_namespace ORDER BY nspname'));
            Http::assertNothingSent();
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
        });
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'push.habilitado' => false,
            'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        $transport = Mockery::mock(TransportePush::class);
        $transport->shouldNotReceive('enviar');
        $this->app->instance(TransportePush::class, $transport);
        $this->reloj('2026-11-01T11:00:00.123456Z');
    }

    private function navegador(array &$cookies, string $metodo, string $uri, array $datos = [], array $headers = [], bool $csrf = true): TestResponse
    {
        Cookie::unqueue(config('push.cookie'), '/');

        return $this->navegadorBase($cookies, $metodo, $uri, $datos, $headers, $csrf);
    }

    private function reloj(string $ahora): void
    {
        Carbon::setTestNow($ahora);
        CarbonImmutable::setTestNow($ahora);
    }

    private function preparar(): array
    {
        // Catálogos, horarios e identidad previamente activada son fixtures, no flujos de alta de personal/CU-04.
        $admin = $this->usuarioAuth();
        $r = $this->citaResources();
        $r['old_patient'] = $r['patient'];
        DB::table('pacientes')->where('id_paciente', $r['old_patient'])->update(['creado_en' => '2020-01-01T12:00:00Z']);
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia de reportes']);
        foreach ([7, 1] as $dia) {
            DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => $dia,
                'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $r['patient'] = $this->navegador($cookies, 'POST', '/api/pacientes', ['nombres' => 'Paciente',
            'apellidos' => 'Reportes Integración Ficticia', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
            'posibles_duplicados_revisados' => true])->assertCreated()->json('data.id_paciente');
        $patient = DB::table('pacientes')->where('id_paciente', $r['patient'])->sole();
        $this->assertNull($patient->documento_identidad);
        $this->assertNull($patient->id_usuario);
        $this->assertSame(0, DB::transactionLevel());

        return [$cookies, $admin, $r];
    }

    private function reservar(array &$cookies, array $r): array
    {
        $criterios = ['id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'],
            'id_paciente' => (string) $r['patient'], 'fecha' => '2026-11-01'];
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($criterios))
            ->assertOk()->json('data.turnos.0');
        $this->assertIsArray($turno);
        $propuesta = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$criterios,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => 'MOTIVO-REP5-PRIVADO'])
            ->assertOk()->json('data.propuesta');
        $cita = $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $propuesta, 'motivo_breve' => 'MOTIVO-REP5-PRIVADO'])
            ->assertCreated()->assertJsonPath('data.estado', 'Programada')->json('data');
        $this->assertSame(0, DB::transactionLevel());

        return $cita;
    }

    private function originales(array &$cookies, string $id, string $vista = 'asistencia'): array
    {
        $data = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/'.$vista)->assertOk()->json('data');

        return ['version_programacion_original' => $data['version_programacion'], 'actualizada_en_original' => $data['actualizada_en']];
    }

    private function cancelar(array &$cookies, string $id): void
    {
        $originales = $this->originales($cookies, $id, 'gestion');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', [...$originales, 'motivo' => 'Cancelación ficticia de integración'])
            ->assertOk()->assertJsonPath('data.estado', 'Cancelada');
    }

    private function consultas(array $r, string $fecha = '2026-11-01'): array
    {
        return ['citas/programadas' => ['fecha' => $fecha],
            'citas/por-medico' => ['fecha' => $fecha, 'id_medico' => (string) $r['doctors'][0]],
            'citas/por-especialidad' => ['fecha' => $fecha, 'id_especialidad' => (string) $r['specialty']],
            'citas/por-estado' => ['fecha' => $fecha, 'estado' => 'Cancelada'],
            'pacientes/registrados' => ['fecha' => $fecha], 'citas/cantidades' => ['fecha' => $fecha],
            'agenda-diaria' => ['fecha' => $fecha, 'id_medico' => (string) $r['doctors'][0]]];
    }

    private function snapshot(): array
    {
        $data = [];
        foreach (['citas', 'pacientes', 'medicos', 'especialidades', 'consultorios', 'horarios_atencion', 'cambios_cita', 'recordatorios', 'envios_recordatorio_push', 'bitacora'] as $tabla) {
            $data[$tabla] = DB::table($tabla)->orderByRaw('1')->get()->toJson();
        }

        return $data;
    }

    private function comprobarSiete(array &$cookies, array $r, array $totales, array $cantidades, string $fecha = '2026-11-01'): void
    {
        $snapshot = $this->snapshot();
        foreach ($this->consultas($r, $fecha) as $reporte => $filtros) {
            $response = $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query($filtros))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('meta.reporte', $reporte)
                ->assertJsonPath('meta.zona_horaria', 'America/La_Paz')->assertJsonPath('meta.periodo.desde', $fecha)
                ->assertJsonPath('meta.periodo.hasta', $fecha);
            if ($reporte === 'citas/cantidades') {
                $response->assertJsonPath('data', $cantidades)->assertJsonMissingPath('links')->assertJsonMissingPath('meta.current_page');
                foreach ($cantidades as $campo => $cantidad) {
                    $this->assertIsInt($response->json('data.'.$campo));
                }
            } else {
                $response->assertJsonPath('meta.total', $totales[$reporte])->assertJsonCount($totales[$reporte], 'data');
                if ($reporte === 'pacientes/registrados' && $totales[$reporte] > 0) {
                    $this->assertEqualsCanonicalizing(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos', 'activo', 'creado_en'], array_keys($response->json('data.0')));
                    $response->assertJsonPath('data.0.id_paciente', (string) $r['patient']);
                }
                if ($reporte === 'agenda-diaria') {
                    $response->assertJsonPath('meta.medico.id_medico', (string) $r['doctors'][0])->assertJsonPath('meta.periodo.criterio', 'interseccion_diaria');
                }
            }
            foreach (['MOTIVO-REP5-PRIVADO', 'CI-REP5-PRIVADO', 'motivo_breve', 'documento_identidad', 'password_hash', 'correo_contacto', 'fecha_nacimiento'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $response->getContent());
            }
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    private function totales(int $programadas, int $citas, int $canceladas, int $pacientes = 1): array
    {
        return ['citas/programadas' => $programadas, 'citas/por-medico' => $citas, 'citas/por-especialidad' => $citas,
            'citas/por-estado' => $canceladas, 'pacientes/registrados' => $pacientes, 'agenda-diaria' => $citas];
    }

    public function test_real_reservations_and_terminal_transitions_are_consistent_across_seven_reports_and_new_login(): void
    {
        [$cookies, $admin, $r] = $this->preparar();
        $citas = [];
        for ($i = 0; $i < 4; $i++) {
            $citas[] = $this->reservar($cookies, $r);
        }
        $this->assertSame([(string) $admin->getKey()], DB::table('citas')->distinct()->pluck('creada_por')->map(fn ($id) => (string) $id)->all());
        $this->comprobarSiete($cookies, $r, $this->totales(4, 4, 0), ['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0]);
        $page = $this->navegador($cookies, 'GET', '/api/reportes/citas/por-medico?'.http_build_query([
            'id_medico' => $r['doctors'][0], 'fecha' => '2026-11-01', 'per_page' => 1]))->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.id_cita', $citas[0]['id_cita']);
        $this->navegador($cookies, 'GET', $page->json('links.next'))->assertOk()->assertJsonPath('data.0.id_cita', $citas[1]['id_cita']);
        $this->navegador($cookies, 'GET', '/api/reportes/citas/por-medico?'.http_build_query([
            'id_medico' => $r['doctors'][0], 'fecha' => '2026-11-01', 'per_page' => 1, 'page' => 5]))->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonCount(0, 'data');
        $this->cancelar($cookies, $citas[1]['id_cita']);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$citas[3]['id_cita'].'/llegada', [
            ...$this->originales($cookies, $citas[3]['id_cita']), 'presencia_verificada' => true])->assertOk()->assertJsonPath('data.estado', 'Confirmada');
        $this->reloj($citas[0]['inicio_at']);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$citas[0]['id_cita'].'/atencion', [
            ...$this->originales($cookies, $citas[0]['id_cita']), 'atencion_realizada' => true])->assertOk()->assertJsonPath('data.estado', 'Atendida');
        $this->reloj($citas[2]['fin_at']);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$citas[2]['id_cita'].'/inasistencia', [
            ...$this->originales($cookies, $citas[2]['id_cita']), 'ausencia_verificada' => true])->assertOk()->assertJsonPath('data.estado', 'Inasistencia');
        $totales = $this->totales(0, 4, 1);
        $cantidades = ['atendidas' => 1, 'canceladas' => 1, 'inasistencias' => 1, 'total' => 3];
        $this->comprobarSiete($cookies, $r, $totales, $cantidades);
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        foreach ($this->consultas($r) as $reporte => $filtros) {
            $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query($filtros))->assertUnauthorized()
                ->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO')->assertJsonMissingPath('data');
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->comprobarSiete($cookies, $r, $totales, $cantidades);
    }

    public function test_real_reprogramming_and_cancellation_move_period_counts_not_event_dates_or_patient_registration(): void
    {
        [$cookies, $admin, $r] = $this->preparar();
        $cita = $this->reservar($cookies, $r);
        $view = $this->navegador($cookies, 'GET', '/api/citas/'.$cita['id_cita'].'/disponibilidad-reprogramacion?fecha=2026-11-02')
            ->assertOk()->json('data');
        $turno = $view['turnos'][0];
        $changed = $this->navegador($cookies, 'PATCH', '/api/citas/'.$cita['id_cita'].'/reprogramacion', [
            'version_programacion_original' => $view['cita_original']['version_programacion'],
            'actualizada_en_original' => $view['cita_original']['actualizada_en'], 'fecha' => '2026-11-02',
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']])->assertOk()
            ->assertJsonPath('data.estado', 'Programada')->assertJsonPath('data.version_programacion', 2)->json('data');
        $this->assertSame($cita['id_cita'], $changed['id_cita']);
        $zero = ['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0];
        $this->comprobarSiete($cookies, $r, $this->totales(0, 0, 0), $zero);
        $this->comprobarSiete($cookies, $r, $this->totales(1, 1, 0, 0), $zero, '2026-11-02');
        $this->cancelar($cookies, $cita['id_cita']); // Se cancela el día 1 una cita programada para el día 2.
        $this->comprobarSiete($cookies, $r, $this->totales(0, 0, 0), $zero);
        $this->comprobarSiete($cookies, $r, $this->totales(0, 1, 1, 0), ['atendidas' => 0, 'canceladas' => 1, 'inasistencias' => 0, 'total' => 1], '2026-11-02');
        $this->navegador($cookies, 'GET', '/api/reportes/citas/cantidades?desde=2026-11-01&hasta=2026-11-02')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('meta.periodo.inicio_at', '2026-11-01T04:00:00.000000Z')
            ->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-03T04:00:00.000000Z');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_real_ci_and_activity_updates_preserve_registration_date_and_seven_reports_remain_admin_only(): void
    {
        [$cookies, $admin, $r] = $this->preparar();
        $this->reservar($cookies, $r);
        $this->reloj('2026-11-02T11:00:00.123456Z');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $ficha = $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['patient'])->assertOk()->json('data');
        $updated = $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$r['patient'], [
            'actualizado_en_original' => $ficha['actualizado_en'], 'documento_identidad' => 'CI-REP5-PRIVADO',
            'identidad_verificada' => true, 'motivo' => 'Identidad ficticia comprobada'])->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$r['patient'].'/estado', [
            'actualizado_en_original' => $updated['actualizado_en'], 'activo' => false, 'motivo' => 'Inactividad ficticia de prueba'])->assertOk();
        $zero = ['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0];
        $this->comprobarSiete($cookies, $r, $this->totales(1, 1, 0), $zero);
        $this->navegador($cookies, 'GET', '/api/reportes/pacientes/registrados?fecha=2026-11-02')->assertOk()->assertJsonPath('meta.total', 0);
        $this->navegador($cookies, 'GET', '/api/reportes/pacientes/registrados?fecha=2026-11-01&activo=0')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.activo', false);
        foreach (['Recepcionista', 'Médico', 'Paciente'] as $rol) {
            // Cuentas ya activadas/vinculadas como fixture; no se acredita invitación/registro nuevo.
            $actor = $this->usuarioAuth($rol);
            $other = $this->csrfAuth();
            $this->loginAuth($other, $actor)->assertOk();
            $snapshot = $this->snapshot();
            foreach ($this->consultas($r) as $reporte => $filtros) {
                $this->navegador($other, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query($filtros))->assertForbidden()
                    ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('error.code', 'ACCESO_DENEGADO')->assertJsonMissingPath('data');
            }
            $this->assertSame($snapshot, $this->snapshot());
            $operativa = match ($rol) {
                'Recepcionista' => '/api/agenda?id_medico='.$r['doctors'][0].'&fecha=2026-11-01',
                'Médico' => '/api/agenda?fecha=2026-11-01',
                'Paciente' => '/api/mis-citas?periodo=todas',
            };
            $this->navegador($other, 'GET', $operativa)->assertOk();
        }
    }

    public function test_seven_empty_reports_and_invalid_filters_have_consistent_safe_contracts_without_writes(): void
    {
        [$cookies, $admin, $r] = $this->preparar();
        $zero = ['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0];
        $this->comprobarSiete($cookies, $r, $this->totales(0, 0, 0, 0), $zero, '2027-01-01');
        $snapshot = $this->snapshot();
        foreach ($this->consultas($r) as $reporte => $filtros) {
            foreach ([['formato' => 'csv'], ['fecha' => '2026-02-29']] as $extra) {
                $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query([...$filtros, ...$extra]))
                    ->assertUnprocessable()->assertJsonPath('success', false)->assertJsonPath('error.code', 'VALIDACION_ERROR')
                    ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data');
            }
        }
        foreach (['citas/programadas', 'citas/por-medico', 'citas/cantidades', 'agenda-diaria'] as $reporte) {
            $filtros = [...$this->consultas($r)[$reporte], 'id_medico' => '9223372036854775807'];
            $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query($filtros))->assertNotFound()
                ->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO')->assertJsonMissingPath('data');
        }
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }
}
