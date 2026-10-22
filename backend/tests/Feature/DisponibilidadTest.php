<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class DisponibilidadTest extends TestCase
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
        DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 20]);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-10-22T12:00:00Z');
    }

    private function contexto(string $rol = 'Recepcionista'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Paciente') {
            $r['patient'] = DB::table('pacientes')->where('id_usuario', $actor->getKey())->value('id_paciente');
        }
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:01', 'hora_fin' => '09:00:01']);

        return [$cookies, $r, $actor];
    }

    private function turnos(array &$cookies, array $r, array $extra = [], bool $propia = false): TestResponse
    {
        $datos = ['id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'],
            'fecha' => '2026-11-01', ...($propia ? [] : ['id_paciente' => (string) $r['patient']]), ...$extra];

        return $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($datos));
    }

    private function snapshot(): array
    {
        $resultado = [];
        foreach (['citas', 'medicos', 'horarios_atencion', 'configuracion_clinica', 'pacientes', 'bitacora', 'cambios_cita', 'recordatorios'] as $tabla) {
            $resultado[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $resultado;
    }

    public function test_anchored_slots_are_paginated_private_readonly_and_revisions_stable(): void
    {
        [$cookies, $r] = $this->contexto();
        $antes = $this->snapshot();
        $all = $this->turnos($cookies, $r)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.zona_horaria', 'America/La_Paz')->assertJsonPath('data.paciente_reservable', true)
            ->assertJsonPath('data.duracion_minutos', 20)->assertJsonPath('meta.total', 3)->json('data.turnos');
        $this->assertSame(['08:00:01', '08:20:01', '08:40:01'], array_column($all, 'hora_inicio'));
        $this->assertSame('2026-11-01T12:00:01.000000Z', $all[0]['inicio_at']);
        $this->assertSame('2026-11-01T12:20:01.000000Z', $all[0]['fin_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $all[0]['revision_turno']);
        $page = $this->turnos($cookies, $r, ['page' => 2, 'per_page' => 1])->assertOk()->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(1, 'data.turnos')->json();
        $this->assertSame($all[1], $page['data']['turnos'][0]);
        $this->assertStringContainsString('fecha=2026-11-01', $page['links']['next']);
        $this->assertStringNotContainsString('revision_turno', $page['links']['next']);
        CarbonImmutable::setTestNow('2026-10-22T12:00:01Z');
        $this->assertSame($all, $this->turnos($cookies, $r)->assertOk()->json('data.turnos'));
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_separate_intervals_keep_their_anchor_and_discard_remainder(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('horarios_atencion')->update(['hora_fin' => '08:50:01']);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:50:01', 'hora_fin' => '09:31:00']);
        $this->assertSame(['08:00:01', '08:20:01', '08:50:01', '09:10:01'],
            array_column($this->turnos($cookies, $r)->assertOk()->json('data.turnos'), 'hora_inicio'));
    }

    public function test_historical_duration_only_removes_intersected_candidates_and_cancellation_does_not_reanchor(): void
    {
        [$cookies, $r] = $this->contexto();
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-11-01T12:10:01Z', 'fin_at' => '2026-11-01T12:25:01Z']), 'id_cita');
        $this->assertSame(['08:40:01'], array_column($this->turnos($cookies, $r)->assertOk()->json('data.turnos'), 'hora_inicio'));
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Cancelada']);
        $this->assertSame(['08:00:01', '08:20:01', '08:40:01'], array_column($this->turnos($cookies, $r)->assertOk()->json('data.turnos'), 'hora_inicio'));
    }

    public static function ocupantes(): array
    {
        return [['medico', 'Programada', 2], ['sala', 'Confirmada', 2], ['paciente', 'Programada', 2],
            ['paciente', 'Confirmada', 2], ['paciente', 'Atendida', 3], ['paciente', 'Inasistencia', 3],
            ['paciente', 'Cancelada', 3], ['sala', 'Atendida', 2], ['sala', 'Inasistencia', 2], ['medico', 'Cancelada', 3]];
    }

    #[DataProvider('ocupantes')]
    public function test_occupation_uses_actual_resources_and_patient_states(string $recurso, string $estado, int $total): void
    {
        [$cookies, $r] = $this->contexto();
        $otraFicha = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'OTRO', 'nombres' => 'No revelar', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        DB::table('citas')->insert($this->citaRow($r, [
            'id_medico' => $recurso === 'medico' ? $r['doctors'][0] : $r['doctors'][1],
            'id_consultorio' => $recurso === 'sala' ? $r['rooms'][0] : $r['rooms'][1],
            'id_paciente' => $recurso === 'paciente' ? $r['patient'] : $otraFicha,
            'inicio_at' => '2026-11-01T12:20:01Z', 'fin_at' => '2026-11-01T12:40:01Z', 'duracion_minutos' => 20,
            'estado' => $estado, 'motivo_breve' => 'CLINICA_SECRETA']));
        $response = $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', $total);
        $this->assertStringNotContainsString('CLINICA_SECRETA', $response->getContent());
        $this->assertStringNotContainsString('No revelar', $response->getContent());
        $this->assertStringNotContainsString('id_cita', $response->getContent());
    }

    public function test_multiday_occupation_is_found_even_if_it_started_on_another_date(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => '2026-10-31T12:20:01Z', 'fin_at' => '2026-11-01T12:20:01Z', 'duracion_minutos' => 1440]));
        $this->assertSame(['08:20:01', '08:40:01'], array_column($this->turnos($cookies, $r)->assertOk()->json('data.turnos'), 'hora_inicio'));
    }

    public function test_inactive_other_doctor_still_reserves_active_shared_schedule_but_inactive_schedule_does_not(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0], 'activo' => false]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7, 'hora_inicio' => '08:20:01', 'hora_fin' => '08:40:01']);
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 2);
        DB::table('horarios_atencion')->where('id_medico', $r['doctors'][1])->update(['activo' => false]);
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 3);
    }

    public static function instantes(): array
    {
        return [['2026-11-01T12:00:00.999999Z', 3], ['2026-11-01T12:00:01.000000Z', 2],
            ['2026-11-01T12:20:01.000000Z', 1], ['2026-11-01T12:59:00Z', 0]];
    }

    #[DataProvider('instantes')]
    public function test_today_requires_strictly_future_start_with_microseconds(string $ahora, int $total): void
    {
        CarbonImmutable::setTestNow($ahora);
        [$cookies, $r] = $this->contexto();
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', $total);
    }

    public function test_local_date_not_utc_controls_past_date_and_no_arbitrary_horizon(): void
    {
        CarbonImmutable::setTestNow('2026-11-02T02:00:00Z'); // Domingo 22:00 en la sede, lunes UTC.
        [$cookies, $r] = $this->contexto();
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0);
        $this->turnos($cookies, $r, ['fecha' => '2026-10-31'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fecha']]]);
        $this->turnos($cookies, $r, ['fecha' => '2099-01-01'])->assertOk();
    }

    public function test_patient_is_derived_from_session_and_inactive_chart_can_only_consult(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $this->turnos($cookies, $r, [], true)->assertOk()->assertJsonPath('data.paciente_reservable', false);
        $this->turnos($cookies, $r)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['id_paciente']]]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => true]);
        $this->turnos($cookies, $r, [], true)->assertOk()->assertJsonPath('data.paciente_reservable', true);
    }

    public function test_reception_requires_patient_and_can_consult_chart_without_ci_or_account(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->turnos($cookies, $r, [], true)->assertUnprocessable();
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('data.paciente_reservable', true);
        $this->turnos($cookies, $r, ['id_paciente' => '9223372036854775807'])->assertNotFound();
    }

    public static function indisponibles(): array
    {
        return [['medicos', 'activo', false], ['especialidades', 'activa', false], ['consultorios', 'activo', false], ['medico_especialidad', 'habilitada', false]];
    }

    #[DataProvider('indisponibles')]
    public function test_unavailable_selection_is_not_silently_replaced(string $tabla, string $campo, bool $valor): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table($tabla)->update([$campo => $valor]);
        $this->turnos($cookies, $r)->assertConflict()->assertJsonPath('error.code', 'CITA_TURNO_NO_DISPONIBLE');
        $this->navegador($cookies, 'GET', '/api/reservas/medicos?id_especialidad='.$r['specialty'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_no_schedule_inactive_schedule_wrong_day_and_unfit_duration_produce_empty_slots(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('horarios_atencion')->update(['activo' => false]);
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0);
        DB::table('horarios_atencion')->update(['activo' => true, 'dia_semana' => 1]);
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0);
        DB::table('horarios_atencion')->update(['dia_semana' => 7]);
        DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 2147483647]);
        $this->turnos($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_catalogs_only_project_safe_fields_and_support_literal_filters_and_pagination(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('especialidades')->insert(['nombre' => 'Otra % especialidad']);
        $data = $this->navegador($cookies, 'GET', '/api/reservas/especialidades?nombre=%25&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['id_especialidad', 'nombre'], array_keys($data));
        $this->assertIsString($data['id_especialidad']);
        $data = $this->navegador($cookies, 'GET', '/api/reservas/medicos?'.http_build_query(['id_especialidad' => $r['specialty'], 'consultorio' => 'ficticio 0', 'nombre' => 'MÉDICO']))
            ->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['id_medico', 'codigo_medico', 'nombres', 'apellidos', 'consultorio'], array_keys($data));
        $this->assertIsString($data['id_medico']);
        $this->navegador($cookies, 'GET', '/api/reservas/medicos?id_especialidad='.$r['specialty'].'&consultorio=%25')->assertOk()->assertJsonCount(0, 'data');
        DB::table('especialidades')->update(['activa' => false]);
        $this->navegador($cookies, 'GET', '/api/reservas/especialidades')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revision_changes_with_relevant_resources_actor_or_patient_but_not_unrelated_occupation(): void
    {
        [$cookies, $r] = $this->contexto();
        $before = $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno');
        DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => '2026-11-01T12:40:01Z', 'fin_at' => '2026-11-01T13:00:01Z', 'duracion_minutos' => 20]));
        $this->assertSame($before, $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno'));
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'Nombre nuevo']);
        $after = $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno');
        $this->assertNotSame($before, $after);
        $otro = $this->usuarioAuth('Recepcionista');
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $otro)->assertOk();
        $this->assertNotSame($after, $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno'));
    }

    public static function invalidos(): array
    {
        return [['fecha', '2026-02-30'], ['fecha', '2026-2-01'], ['fecha', '0000-01-01'], ['fecha', '2026-11-01T00:00:00Z'],
            ['id_medico', '0'], ['id_medico', '9223372036854775808'], ['id_medico', '1e1'], ['id_medico', '01'], ['id_paciente', 'otro'],
            ['page', 0], ['per_page', 101], ['estado', 'Programada'], ['duracion_minutos', 10]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_validation_rejects_invalid_input(string $campo, mixed $valor): void
    {
        [$cookies, $r] = $this->contexto();
        $this->turnos($cookies, $r, [$campo => $valor])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [$campo]]]);
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Médico']];
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_access_any_reservation_catalog(string $rol): void
    {
        [$cookies, $r] = $this->contexto($rol);
        foreach (['especialidades', 'medicos', 'disponibilidad'] as $ruta) {
            $this->navegador($cookies, 'GET', '/api/reservas/'.$ruta)->assertForbidden();
        }
    }

    public function test_anonymous_revoked_session_and_foreign_origin_are_rejected_without_leaking_data(): void
    {
        $cookies = [];
        $this->navegador($cookies, 'GET', '/api/reservas/especialidades')->assertUnauthorized();
        [$cookies, $r, $actor] = $this->contexto();
        $this->navegador($cookies, 'GET', '/api/reservas/especialidades', [], ['Origin' => 'https://ajeno.invalid'])->assertForbidden();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->turnos($cookies, $r)->assertUnauthorized();
    }

    public function test_new_actors_still_cannot_use_administrative_catalogs_or_write_without_proposal(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        foreach (['medicos', 'especialidades', 'consultorios', 'configuracion-citas'] as $ruta) {
            $this->navegador($cookies, 'GET', '/api/'.$ruta)->assertForbidden();
        }
        $this->navegador($cookies, 'POST', '/api/citas', [])->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [])->assertUnprocessable();
    }

    public static function revisiones(): array
    {
        return [['configuracion_clinica', 'duracion_turno_minutos', 15], ['medicos', 'nombres', 'Nombre diferente'],
            ['especialidades', 'nombre', 'Especialidad distinta'], ['horarios_atencion', 'hora_inicio', '08:00:02']];
    }

    #[DataProvider('revisiones')]
    public function test_revision_binds_duration_professional_specialty_and_schedule(string $tabla, string $campo, mixed $valor): void
    {
        [$cookies, $r] = $this->contexto();
        $antes = $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno');
        DB::table($tabla)->update([$campo => $valor]);
        $this->assertNotSame($antes, $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno'));
    }

    public function test_revision_is_bound_to_patient_and_reservable_state(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $antes = $this->turnos($cookies, $r, [], true)->assertOk()->json('data.turnos.0.revision_turno');
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => true]);
        $this->assertNotSame($antes, $this->turnos($cookies, $r, [], true)->assertOk()->json('data.turnos.0.revision_turno'));
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $actor = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($cookies, $actor)->assertOk();
        $antes = $this->turnos($cookies, $r)->assertOk()->json('data.turnos.0.revision_turno');
        $otraFicha = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'DISTINTO', 'nombres' => 'Ficticio', 'apellidos' => 'Otro',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $this->assertNotSame($antes, $this->turnos($cookies, $r, ['id_paciente' => $otraFicha])->assertOk()->json('data.turnos.0.revision_turno'));
    }

    public static function revocaciones(): array
    {
        return [['estado_cuenta', 'Desactivada'], ['cambio_clave_obligatorio', true]];
    }

    #[DataProvider('revocaciones')]
    public function test_account_changes_invalidate_access_to_all_three_operations(string $campo, mixed $valor): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update([$campo => $valor]);
        foreach (['especialidades', 'medicos', 'disponibilidad'] as $ruta) {
            $this->navegador($cookies, 'GET', '/api/reservas/'.$ruta)->assertUnauthorized();
        }
    }

    public static function esperas(): array
    {
        return [['sesion', 401], ['rol', 401]]; // El cambio de rol invalida la identidad de la sesión existente.
    }

    #[DataProvider('esperas')]
    public function test_service_rechecks_session_and_role_after_planning_wait(string $tipo, int $status): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $this->app->instance(PlanificacionRepository::class, Mockery::mock(PlanificacionRepository::class, function ($mock) use ($tipo, $actor): void {
            $mock->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($tipo, $actor): void {
                // Inyección dentro de la misma conexión: no acredita una carrera entre procesos.
                if ($tipo === 'sesion') {
                    DB::table('sessions')->where('user_id', $actor->getKey())->delete();
                } else {
                    DB::table('roles')->insertOrIgnore(['nombre' => 'Administrador']);
                    $rol = DB::table('roles')->where('nombre', 'Administrador')->value('id_rol');
                    DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['id_rol' => $rol]);
                }
            });
        }));
        $this->turnos($cookies, $r)->assertStatus($status);
    }

    public function test_unknown_resources_and_unassigned_doctor_have_safe_errors(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->turnos($cookies, $r, ['id_medico' => '9223372036854775807'])->assertNotFound();
        $this->turnos($cookies, $r, ['id_especialidad' => '9223372036854775807'])->assertNotFound();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => null]);
        $this->turnos($cookies, $r)->assertConflict()->assertJsonPath('error.code', 'CITA_TURNO_NO_DISPONIBLE');
        $this->navegador($cookies, 'GET', '/api/reservas/medicos?id_especialidad='.$r['specialty'])->assertOk()->assertJsonCount(0, 'data');
    }
}
