<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\DisponibilidadService;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class DisponibilidadReprogramacionTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
    }

    private function contexto(string $rol = 'Administrador', array $campos = []): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:01', 'hora_fin' => '09:00:01']);
        // Duración histórica distinta de la vigente; su ocupación cruza dos candidatos.
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [...[
            'inicio_at' => '2026-11-01T12:10:01Z', 'fin_at' => '2026-11-01T12:25:01Z',
            'motivo_breve' => 'MOTIVO-NO-EXPOSICION'], ...$campos]), 'id_cita');

        return [$cookies, $r, $id, $actor];
    }

    private function turnos(array &$cookies, string $id, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?'.http_build_query([
            'fecha' => '2026-11-01', ...$extra]));
    }

    private function snapshot(): array
    {
        $data = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'bitacora', 'pacientes', 'medicos', 'consultorios',
            'horarios_atencion', 'configuracion_clinica'] as $table) {
            $data[$table] = DB::table($table)->get()->toJson();
        }

        return $data;
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_original_occupation_is_excluded_for_both_operators_without_writing(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        $before = $this->snapshot();
        $response = $this->turnos($cookies, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.cita_original.id_cita', $id)->assertJsonPath('data.cita_original.duracion_minutos', 15)
            ->assertJsonPath('data.cita_original.version_programacion', 1)->assertJsonPath('data.duracion_minutos', 20)
            ->assertJsonPath('data.paciente.id_paciente', (string) $r['patient'])->assertJsonPath('meta.total', 3);
        $this->assertSame(['08:00:01', '08:20:01', '08:40:01'], array_column($response->json('data.turnos'), 'hora_inicio'));
        $this->assertSame('2026-11-01T12:00:01.000000Z', $response->json('data.turnos.0.inicio_at'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $response->json('data.turnos.0.revision_turno'));
        $this->assertArrayNotHasKey('paciente_reservable', $response->json('data'));
        $this->assertStringNotContainsString('MOTIVO-NO-EXPOSICION', $response->getContent());
        foreach (['motivo_breve', 'documento_identidad', 'telefono', 'creada_por', 'id_propuesta_reserva', 'password_hash'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_only_identical_start_is_removed_and_old_interval_can_be_partially_reused(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-11-01T12:00:01Z',
            'fin_at' => '2026-11-01T12:45:01Z', 'duracion_minutos' => 45, 'estado' => 'Confirmada']);
        $this->assertSame(['08:20:01', '08:40:01'], array_column($this->turnos($cookies, $id)->assertOk()->json('data.turnos'), 'hora_inicio'));
        $this->assertSame('Confirmada', DB::table('citas')->where('id_cita', $id)->value('estado'));
    }

    public function test_inactive_chart_without_ci_or_account_is_accepted_only_for_this_cita(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        $this->turnos($cookies, $id)->assertOk()->assertJsonPath('meta.total', 3);
        $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query([
            'id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty'], 'id_paciente' => $r['patient'], 'fecha' => '2026-11-01']))
            ->assertOk()->assertJsonPath('data.paciente_reservable', false)->assertJsonPath('meta.total', 1);
    }

    public static function ocupantes(): array
    {
        return [['medico', 'Programada', 2], ['sala', 'Confirmada', 2], ['paciente', 'Programada', 2],
            ['paciente', 'Confirmada', 2], ['paciente', 'Atendida', 3], ['paciente', 'Cancelada', 3],
            ['sala', 'Atendida', 2], ['sala', 'Inasistencia', 2], ['medico', 'Cancelada', 3]];
    }

    #[DataProvider('ocupantes')]
    public function test_other_occupation_is_not_excluded(string $resource, string $estado, int $total): void
    {
        // Original otro día para que las barreras SQL permitan el ocupante del día consultado.
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-11-02T12:10:01Z', 'fin_at' => '2026-11-02T12:25:01Z']);
        $other = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'OTRA-FICHA', 'nombres' => 'No revelar',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $resource === 'medico' ? $r['doctors'][0] : $r['doctors'][1],
            'id_consultorio' => $resource === 'sala' ? $r['rooms'][0] : $r['rooms'][1],
            'id_paciente' => $resource === 'paciente' ? $r['patient'] : $other,
            'inicio_at' => '2026-11-01T12:20:01Z', 'fin_at' => '2026-11-01T12:40:01Z', 'duracion_minutos' => 20,
            'estado' => $estado, 'motivo_breve' => 'SECRETO-OCUPANTE']));
        $response = $this->turnos($cookies, $id)->assertOk()->assertJsonPath('meta.total', $total);
        $this->assertStringNotContainsString('SECRETO-OCUPANTE', $response->getContent());
        $this->assertStringNotContainsString('No revelar', $response->getContent());
    }

    public static function estadosFinales(): array
    {
        return [['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('estadosFinales')]
    public function test_final_states_cannot_consult_reprogramming(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => $estado]);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_GESTION_NO_PERMITIDA');
    }

    public static function instantes(): array
    {
        return [['2026-11-01T12:10:00.999999Z', 200], ['2026-11-01T12:10:01.000000Z', 409], ['2026-11-01T12:10:01.000001Z', 409]];
    }

    #[DataProvider('instantes')]
    public function test_original_start_is_a_strict_deadline(string $now, int $status): void
    {
        CarbonImmutable::setTestNow($now);
        [$cookies, $r, $id] = $this->contexto();
        $this->turnos($cookies, $id)->assertStatus($status);
    }

    public static function consultas(): array
    {
        return [[null], ['2026-10-26T12:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_any_consultation_blocks_without_leaking_clinical_content(?string $inicio): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA', 'iniciada_en' => $inicio]);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA')
            ->assertJsonPath('error.message', 'La cita no admite esta operación.');
    }

    public static function indisponibles(): array
    {
        return [['medicos', 'activo'], ['especialidades', 'activa'], ['consultorios', 'activo'], ['medico_especialidad', 'habilitada']];
    }

    #[DataProvider('indisponibles')]
    public function test_destination_resources_must_remain_available(string $table, string $field): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table($table)->update([$field => false]);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_TURNO_NO_DISPONIBLE');
    }

    public static function invalidos(): array
    {
        return [[['id_medico' => '1']], [['id_especialidad' => '1']], [['id_paciente' => '1']], [['exceptoCita' => '1']],
            [['id_objetivo' => '1']], [['rol' => 'Administrador']], [['fecha' => '2026-02-30']], [['fecha' => '0000-01-01']],
            [['page' => 0]], [['per_page' => 101]], [['fecha' => '2026-10-25']]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_inputs_cannot_replace_resources(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $this->turnos($cookies, $id, $extra)->assertUnprocessable();
    }

    public function test_canonical_route_id_empty_date_and_get_body_are_rejected(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $bad) {
            $this->turnos($cookies, $bad)->assertUnprocessable();
        }
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01', ['fecha' => '2026-11-01'])->assertUnprocessable();
        $this->turnos($cookies, '9223372036854775807')->assertNotFound();
    }

    public static function noOperadores(): array
    {
        return [['Paciente'], ['Médico']];
    }

    #[DataProvider('noOperadores')]
    public function test_other_roles_are_denied_over_http_and_direct_service(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        $this->turnos($cookies, $id)->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(DisponibilidadService::class)->turnosReprogramacion($id, ['fecha' => '2026-11-01']);
    }

    public function test_anonymous_and_revoked_sessions_do_not_read_cita(): void
    {
        $cookies = $this->csrfAuth();
        $this->turnos($cookies, '1')->assertUnauthorized();
        [$cookies, $r, $id, $actor] = $this->contexto();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->turnos($cookies, $id)->assertUnauthorized();
    }

    public function test_pagination_links_stability_empty_pages_and_head(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $all = $this->turnos($cookies, $id)->assertOk()->json('data.turnos');
        $page = $this->turnos($cookies, $id, ['page' => 2, 'per_page' => 1])->assertOk()->assertJsonPath('meta.total', 3)->json();
        $this->assertSame($all[1], $page['data']['turnos'][0]);
        $this->assertStringContainsString('/citas/'.$id.'/disponibilidad-reprogramacion?', $page['links']['next']);
        $this->assertStringNotContainsString('id_medico', $page['links']['next']);
        $this->turnos($cookies, $id, ['page' => 20])->assertOk()->assertJsonCount(0, 'data.turnos')->assertJsonPath('data.cita_original.id_cita', $id);
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->assertContent('');
    }

    public function test_revision_binds_actor_cita_version_state_original_location_and_resource_configuration(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $hash = function () use (&$cookies, $id) {
            return $this->turnos($cookies, $id)->assertOk()->json('data.turnos.0.revision_turno');
        };
        $a = $hash();
        $this->assertSame($a, $hash());
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Confirmada']);
        $b = $hash();
        $this->assertNotSame($a, $b);
        DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-10-26T12:00:00.123456Z', 'consultorio_atencion' => 'Otra etiqueta histórica']);
        $c = $hash();
        $this->assertNotSame($b, $c);
        DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2]);
        $d = $hash();
        $this->assertNotSame($c, $d);
        DB::table('configuracion_clinica')->update(['nombre_sede' => 'Otra sede ficticia']);
        $e = $hash();
        $this->assertNotSame($d, $e);
        $new = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($cookies, $new)->assertConflict();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $new)->assertOk();
        $this->assertNotSame($e, $hash());
    }

    public function test_after_lock_wait_rechecks_original_deadline(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:01Z', 'fin_at' => '2026-10-26T12:25:01Z']);
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function (): void {
            CarbonImmutable::setTestNow('2026-10-26T12:10:01Z');
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_PLAZO_GESTION_FINALIZADO');
    }

    public function test_shared_schedule_of_inactive_doctor_still_blocks_and_no_schedule_is_empty(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0], 'activo' => false]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7,
            'hora_inicio' => '08:20:01', 'hora_fin' => '08:40:01']);
        $this->assertSame(['08:00:01', '08:40:01'], array_column($this->turnos($cookies, $id)->assertOk()->json('data.turnos'), 'hora_inicio'));
        DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        $this->turnos($cookies, $id)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_revocation_while_waiting_is_rechecked_before_returning_slots(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($actor): void {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $this->turnos($cookies, $id)->assertUnauthorized()->assertJsonMissingPath('data');
    }

    public function test_consultation_created_after_initial_read_blocks_even_without_started_timestamp(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($id): void {
            DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-POSTERIOR']);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
    }

    public function test_revision_is_separate_from_new_booking_and_other_cita_context(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-11-02T12:10:01Z', 'fin_at' => '2026-11-02T12:25:01Z']);
        $revision = $this->turnos($cookies, $id)->assertOk()->json('data.turnos.0.revision_turno');
        $reserva = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query([
            'id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty'], 'id_paciente' => $r['patient'], 'fecha' => '2026-11-01']))
            ->assertOk()->json('data.turnos.0.revision_turno');
        $this->assertNotSame($revision, $reserva);
        $other = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'inicio_at' => '2026-11-03T12:10:01Z', 'fin_at' => '2026-11-03T12:25:01Z']), 'id_cita');
        $this->assertNotSame($revision, $this->turnos($cookies, $other)->assertOk()->json('data.turnos.0.revision_turno'));
    }

    public function test_internal_service_also_rejects_resource_replacement(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $this->expectException(ValidationException::class);
        $this->app->make(DisponibilidadService::class)->turnosReprogramacion($id, [
            'fecha' => '2026-11-01', 'id_paciente' => (string) $r['patient']]);
    }

    public function test_reread_detects_change_after_initial_resource_derivation(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porId')->with($id)->once()->andReturnUsing(function () use ($id) {
            $cita = (new CitaRepository)->porId($id);
            DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-10-26T12:00:00.123456Z']);

            return $cita;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $this->turnos($cookies, $id)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
    }
}
