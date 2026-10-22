<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReservaTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function contexto(string $rol = 'Recepcionista'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Paciente') {
            $r['patient'] = DB::table('pacientes')->where('id_usuario', $actor->getKey())->value('id_paciente');
            DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => true]);
        }
        foreach ($r['doctors'] as $id) {
            DB::table('horarios_atencion')->insert(['id_medico' => $id, 'dia_semana' => 7, 'hora_inicio' => '08:00:01', 'hora_fin' => '09:00:01']);
        }

        return [$cookies, $r, $actor];
    }

    private function seleccion(array &$cookies, array $r, bool $propia = false): array
    {
        $datos = ['id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'], 'fecha' => '2026-11-01',
            ...($propia ? [] : ['id_paciente' => (string) $r['patient']])];
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($datos))->assertOk()->json('data.turnos.0');

        return [...$datos, 'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']];
    }

    private function propuesta(array &$cookies, array $r, ?string $motivo = null, bool $propia = false): array
    {
        return $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$this->seleccion($cookies, $r, $propia), 'motivo_breve' => $motivo])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function snapshot(): array
    {
        $salida = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'bitacora', 'medicos', 'horarios_atencion', 'configuracion_clinica'] as $tabla) {
            $salida[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $salida;
    }

    public function test_reception_proposal_is_readonly_then_creates_one_programmed_appointment_and_safe_audit(): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $antes = $this->snapshot();
        $p = $this->propuesta($cookies, $r, 'Motivo privado ficticio');
        $this->assertSame($antes, $this->snapshot());
        $this->assertSame((string) $r['patient'], $p['paciente']['id_paciente']);
        $this->assertSame('2026-11-01T12:00:01.000000Z', $p['inicio_at']);
        $this->assertSame('2026-11-01T12:20:01.000000Z', $p['fin_at']);
        $this->assertSame(20, $p['duracion_minutos']);
        $this->assertTrue(Str::isUuid($p['id_propuesta']));
        $this->assertStringNotContainsString('Motivo privado ficticio', Crypt::decryptString($p['propuesta']));
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertExactJson(['data' => ['registrada' => false]]);
        $cita = $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta'], 'motivo_breve' => 'Motivo privado ficticio'])
            ->assertCreated()->assertJsonPath('meta.repetida', false)->assertJsonPath('data.estado', 'Programada')
            ->assertJsonPath('data.modalidad', 'Presencial')->assertJsonPath('data.version_programacion', 1)->json('data');
        $this->assertIsString($cita['id_cita']);
        $this->assertArrayNotHasKey('id_propuesta_reserva', $cita);
        $this->assertArrayNotHasKey('creada_por', $cita);
        $fila = DB::table('citas')->sole();
        $this->assertSame($actor->getKey(), $fila->creada_por);
        $this->assertSame($p['id_propuesta'], $fila->id_propuesta_reserva);
        $this->assertSame($p['consultorio']['codigo_consultorio'].' — '.$p['consultorio']['nombre'], $fila->consultorio_atencion);
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->assertSame(0, DB::table('recordatorios')->count());
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->sole();
        $this->assertSame('Cita', $evento->entidad_referida);
        $this->assertSame($cita['id_cita'], $evento->registro_referido);
        foreach (['Motivo privado ficticio', $p['propuesta'], $p['id_propuesta'], 'motivo_hash'] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($evento));
        }
    }

    public function test_patient_can_only_create_own_appointment_and_result_is_durable(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $p = $this->propuesta($cookies, $r, null, true);
        $datos = ['propuesta' => $p['propuesta']];
        $cita = $this->navegador($cookies, 'POST', '/api/citas', $datos)->assertCreated()->json('data');
        $this->assertSame((string) $r['patient'], $cita['id_paciente']);
        $antes = $this->snapshot();
        $this->navegador($cookies, 'POST', '/api/citas', $datos)->assertOk()->assertJsonPath('meta.repetida', true)->assertJsonPath('data.id_cita', $cita['id_cita']);
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', $datos)->assertOk()->assertJsonPath('data.registrada', true)->assertJsonPath('data.cita.id_cita', $cita['id_cita']);
        $this->assertSame($antes, $this->snapshot());
        $this->assertSame(1, DB::table('citas')->count());
    }

    public function test_replay_returns_current_state_even_if_resources_or_patient_are_no_longer_reservable(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        DB::table('citas')->update(['estado' => 'Cancelada']);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->update(['activo' => false]);
        DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 30]);
        CarbonImmutable::setTestNow('2026-11-01T13:00:00Z');
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.estado', 'Cancelada')->assertJsonPath('data.duracion_minutos', 20);
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.cita.estado', 'Cancelada');
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }

    public function test_audited_creation_is_queryable_only_by_administrator_with_safe_projections(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $p = $this->propuesta($cookies, $r, 'NO_PUBLICAR_MOTIVO', true);
        $id = $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta'], 'motivo_breve' => 'NO_PUBLICAR_MOTIVO'])->assertCreated()->json('data.id_cita');
        $this->navegador($cookies, 'GET', '/api/bitacora')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $admin = $this->usuarioAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $evento = $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=Cita&registro_referido='.$id)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento['id_evento'])->assertOk()
            ->assertJsonPath('data.cambios_seguros.origen', 'PROPIA')->assertJsonPath('data.cambios_seguros.estado', 'Programada')
            ->assertJsonPath('data.cambios_seguros.duracion_minutos', 20)->assertDontSee('NO_PUBLICAR_MOTIVO');
        foreach (['Paciente' => $r['patient'], 'Medico' => $r['doctors'][0], 'Especialidad' => $r['specialty'], 'Consultorio' => $r['rooms'][0]] as $entidad => $registro) {
            $this->navegador($cookies, 'GET', '/api/bitacora?tipo_evento=CITA_PROGRAMADA&entidad_referida='.$entidad.'&registro_referido='.$registro)->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public static function cambios(): array
    {
        return [['configuracion_clinica', 'duracion_turno_minutos', 15, 'CITA_PROPUESTA_OBSOLETA'],
            ['consultorios', 'nombre', 'Sala renombrada', 'CITA_PROPUESTA_OBSOLETA'],
            ['horarios_atencion', 'hora_inicio', '08:00:02', 'CITA_PROPUESTA_OBSOLETA'],
            ['horarios_atencion', 'activo', false, 'CITA_PROPUESTA_OBSOLETA'],
            ['medico_especialidad', 'habilitada', false, 'CITA_TURNO_NO_DISPONIBLE'],
            ['medicos', 'activo', false, 'CITA_TURNO_NO_DISPONIBLE'],
            ['especialidades', 'activa', false, 'CITA_TURNO_NO_DISPONIBLE'],
            ['pacientes', 'activo', false, 'CITA_PACIENTE_NO_DISPONIBLE'],
            ['pacientes', 'nombres', 'Identidad corregida', 'CITA_PROPUESTA_OBSOLETA']];
    }

    #[DataProvider('cambios')]
    public function test_changed_conditions_are_rejected_without_silent_replacement(string $tabla, string $campo, mixed $valor, string $codigo): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        DB::table($tabla)->update([$campo => $valor]);
        $antes = $this->snapshot();
        $response = $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertConflict()->assertJsonPath('error.code', $codigo);
        if ($campo === 'duracion_turno_minutos') {
            $response->assertJsonPath('error.details.cambios.0', 'DURACION');
        }
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_changed_consultorio_or_patient_between_search_and_review_is_not_adopted(): void
    {
        [$cookies, $r] = $this->contexto();
        $s = $this->seleccion($cookies, $r);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $r['rooms'][1]]);
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', $s)->assertConflict()->assertJsonPath('error.code', 'CITA_PROPUESTA_OBSOLETA');
        $this->assertSame(0, DB::table('citas')->count());
    }

    public function test_two_proposals_do_not_reserve_space_and_only_one_can_create_the_same_turn(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $q = $this->propuesta($cookies, $r);
        $this->assertNotSame($p['id_propuesta'], $q['id_propuesta']);
        $this->assertSame(0, DB::table('citas')->count());
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $q['propuesta']])->assertConflict();
        $this->assertSame(1, DB::table('citas')->count());
    }

    public function test_patient_occupied_in_different_resources_blocks_creation_but_consecutive_is_allowed(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1],
            'inicio_at' => '2026-11-01T12:10:01Z', 'fin_at' => '2026-11-01T12:25:01Z']), 'id_cita');
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertConflict();
        DB::table('citas')->where('id_cita', $id)->update(['inicio_at' => '2026-11-01T11:45:01Z', 'fin_at' => '2026-11-01T12:00:01Z']);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
    }

    public static function motivos(): array
    {
        return [[null, null], ['', null], ['   ', null], ['  Motivo breve  ', 'Motivo breve'], ['0', '0']];
    }

    #[DataProvider('motivos')]
    public function test_optional_reason_is_normalized_and_bound_to_review(?string $entrada, ?string $guardado): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r, $entrada);
        $this->assertSame($guardado, $p['motivo_breve']);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta'], 'motivo_breve' => $entrada])->assertCreated()->assertJsonPath('data.motivo_breve', $guardado);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta'], 'motivo_breve' => 'Otro motivo'])->assertUnprocessable();
        $this->assertSame(1, DB::table('citas')->count());
    }

    public function test_other_actor_cannot_confirm_or_query_a_copied_proposal_even_after_creation(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $other = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($cookies, $other)->assertOk();
        foreach (['/api/citas', '/api/reservas/resultado'] as $ruta) {
            $this->navegador($cookies, 'POST', $ruta, ['propuesta' => $p['propuesta']])->assertForbidden();
        }
        $this->assertSame(1, DB::table('citas')->count());
    }

    public function test_patient_cannot_select_another_chart_or_reuse_review_for_reception_patient(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $s = $this->seleccion($cookies, $r, true);
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$s, 'id_paciente' => '9223372036854775807'])->assertUnprocessable();
        $this->assertSame(0, DB::table('citas')->count());
    }

    public function test_revoked_account_cannot_reconcile_a_previously_saved_proposal(): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertUnauthorized();
    }

    public function test_started_turn_or_past_date_cannot_be_confirmed(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        CarbonImmutable::setTestNow('2026-11-01T12:00:01Z');
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertConflict();
        CarbonImmutable::setTestNow('2026-11-02T12:00:00Z');
        $cookies = $this->csrfAuth(); // Nueva sesión: la anterior caducó al avanzar el día.
        $actor = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($cookies, $actor)->assertOk();
        $s = ['id_paciente' => $r['patient'], 'id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty'],
            'fecha' => '2026-11-01', 'hora_inicio' => '08:00:01', 'revision_turno' => str_repeat('a', 64)];
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', $s)->assertConflict();
        $this->assertSame(0, DB::table('citas')->count());
    }

    public function test_rollback_after_audit_insert_keeps_proposal_recoverable_and_result_absent(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $antes = $this->snapshot();
        $this->app->instance(BitacoraRepository::class, Mockery::mock(BitacoraRepository::class)->makePartial()
            ->shouldReceive('citaProgramada')->once()->andReturnUsing(function ($cita, $actor, $contexto): void {
                (new BitacoraRepository)->citaProgramada($cita, $actor, $contexto);
                throw new RuntimeException('Fallo ficticio después del evento.');
            })->getMock());
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Fallo ficticio');
        $this->assertSame($antes, $this->snapshot());
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.registrada', false);
        $this->app->instance(BitacoraRepository::class, new BitacoraRepository);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
    }

    public static function camposProhibidos(): array
    {
        return [['estado', 'Confirmada'], ['id_paciente', '999'], ['duracion_minutos', 1], ['creada_por', '1'], ['id_propuesta_reserva', 'x'], ['consultorio_atencion', 'Otra sala']];
    }

    #[DataProvider('camposProhibidos')]
    public function test_confirmation_rejects_privileged_fields(string $campo, mixed $valor): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta'], $campo => $valor])->assertUnprocessable();
        $this->assertSame(0, DB::table('citas')->count());
    }

    public static function tokensInvalidos(): array
    {
        return [['alterado'], ['otro_proposito'], ['json_escalar'], ['json_invalido'], ['grande']];
    }

    #[DataProvider('tokensInvalidos')]
    public function test_tampered_or_wrong_purpose_proposals_have_safe_validation(string $tipo): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $token = match ($tipo) {
            'alterado' => 'x'.substr($p['propuesta'], 1),
            'otro_proposito' => Crypt::encryptString(json_encode(['proposito' => 'otro', 'version' => 1])),
            'json_escalar' => Crypt::encryptString('123'),
            'json_invalido' => Crypt::encryptString('{'),
            'grande' => str_repeat('a', 16385),
        };
        foreach (['/api/citas', '/api/reservas/resultado'] as $ruta) {
            $this->navegador($cookies, 'POST', $ruta, ['propuesta' => $token])->assertUnprocessable()->assertDontSee('DecryptException');
        }
    }

    public static function accesos(): array
    {
        return [['Administrador'], ['Médico']];
    }

    #[DataProvider('accesos')]
    public function test_other_roles_are_denied_before_payload_validation(string $rol): void
    {
        [$cookies] = $this->contexto($rol);
        foreach (['/api/citas', '/api/reservas/propuesta', '/api/reservas/resultado'] as $ruta) {
            $this->navegador($cookies, 'POST', $ruta, [])->assertForbidden();
        }
    }

    public function test_csrf_origin_session_and_result_input_are_protected(): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        foreach (['/api/citas', '/api/reservas/propuesta', '/api/reservas/resultado'] as $ruta) {
            $this->navegador($cookies, 'POST', $ruta, [], [], false)->assertStatus(419);
            $this->navegador($cookies, 'POST', $ruta, [], ['Origin' => 'https://ajeno.invalid'])->assertForbidden();
        }
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta'], 'motivo_breve' => 'No consultar por texto'])->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertUnauthorized();
    }

    public function test_migration_preserves_old_rows_accepts_nulls_and_enforces_unique_uuid(): void
    {
        [$cookies, $r] = $this->contexto();
        $migration = require base_path('src/data/database/migrations/2026_10_22_000034_add_id_propuesta_reserva_to_citas.php');
        $migration->down();
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $antes = (array) DB::table('citas')->where('id_cita', $id)->sole();
        $migration->up();
        $despues = (array) DB::table('citas')->where('id_cita', $id)->sole();
        $this->assertNull($despues['id_propuesta_reserva']);
        unset($despues['id_propuesta_reserva']);
        $this->assertSame($antes, $despues);
        $uuid = (string) Str::uuid();
        DB::table('citas')->where('id_cita', $id)->update(['id_propuesta_reserva' => $uuid]);
        try {
            DB::transaction(fn () => DB::table('citas')->insert($this->citaRow($r, ['id_propuesta_reserva' => $uuid,
                'inicio_at' => '2026-11-08T12:00:00Z', 'fin_at' => '2026-11-08T12:15:00Z'])));
            $this->fail('La unicidad debe rechazar la misma propuesta.');
        } catch (QueryException $error) {
            $this->assertSame('23505', $error->getCode());
            $this->assertStringContainsString('citas_propuesta_reserva_uq', $error->errorInfo[2]);
        }
        $this->assertArrayNotHasKey('id_propuesta_reserva', Cita::query()->findOrFail($id)->toArray());
    }

    public function test_reception_cannot_change_selected_patient_using_a_previous_review(): void
    {
        [$cookies, $r] = $this->contexto();
        $s = $this->seleccion($cookies, $r);
        $otro = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'OTRA-FICHA', 'nombres' => 'Ficticio', 'apellidos' => 'Otro',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$s, 'id_paciente' => $otro])->assertConflict();
        $this->assertSame(0, DB::table('citas')->count());
    }

    public function test_occupied_or_unanchored_turn_cannot_be_reviewed_with_a_valid_but_different_revision(): void
    {
        [$cookies, $r] = $this->contexto();
        $s = $this->seleccion($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$s, 'hora_inicio' => '08:01:01'])->assertConflict();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', $s)->assertConflict();
    }

    public function test_result_reconciliation_survives_new_login_by_same_actor_without_duplicating(): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.registrada', true);
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('meta.repetida', true);
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }

    public static function entradaRevision(): array
    {
        return [['motivo_breve', str_repeat('a', 501)], ['motivo_breve', ['no']], ['motivo_breve', "Texto\ncontrol"],
            ['id_medico', '9223372036854775808'], ['fecha', '2026-02-30'], ['hora_inicio', '08:00'],
            ['revision_turno', str_repeat('g', 64)], ['page', 1], ['estado', 'Programada']];
    }

    #[DataProvider('entradaRevision')]
    public function test_proposal_input_is_canonical_closed_and_reason_is_bounded(string $campo, mixed $valor): void
    {
        [$cookies, $r] = $this->contexto();
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$this->seleccion($cookies, $r), $campo => $valor])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [$campo]]]);
    }

    public static function fallosPersistencia(): array
    {
        return [['exclusion', 409], ['exclusion_paciente', 409], ['inesperado', 500]];
    }

    #[DataProvider('fallosPersistencia')]
    public function test_real_database_errors_are_translated_selectively_and_rolled_back(string $tipo, int $status): void
    {
        [$cookies, $r] = $this->contexto();
        $p = $this->propuesta($cookies, $r);
        $antes = $this->snapshot();
        $this->app->instance(CitaRepository::class, Mockery::mock(CitaRepository::class)->makePartial()
            ->shouldReceive('crear')->once()->andReturnUsing(function ($datos) use ($tipo, $r): Cita {
                if ($tipo === 'inesperado') {
                    DB::select('SELECT 1/0');
                }
                // Escritor tardío inyectado, sin acreditar un segundo endpoint ni carrera real.
                DB::table('citas')->insert($this->citaRow($r, $tipo === 'exclusion_paciente'
                    ? ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]] : []));

                return (new CitaRepository)->crear($datos);
            })->getMock());
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertStatus($status)
            ->assertJsonPath('error.code', match ($tipo) {
                'exclusion' => 'CITA_TURNO_NO_DISPONIBLE', 'exclusion_paciente' => 'CITA_PACIENTE_SOLAPADO', default => 'ERROR_INTERNO',
            })->assertDontSee('SQLSTATE');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_random_code_collision_is_retried_in_savepoint_without_duplicate_effects(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r, ['codigo_cita' => 'CODIGO-EXISTENTE',
            'inicio_at' => '2026-11-08T12:00:00Z', 'fin_at' => '2026-11-08T12:15:00Z']));
        $p = $this->propuesta($cookies, $r);
        $intentos = 0;
        $this->app->instance(CitaRepository::class, Mockery::mock(CitaRepository::class)->makePartial()
            ->shouldReceive('crear')->twice()->andReturnUsing(function ($datos) use (&$intentos): Cita {
                if ($intentos++ === 0) {
                    $datos['codigo_cita'] = 'CODIGO-EXISTENTE';
                }

                return (new CitaRepository)->crear($datos);
            })->getMock());
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $p['propuesta']])->assertCreated();
        $this->assertSame(2, $intentos);
        $this->assertSame(2, DB::table('citas')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }
}
