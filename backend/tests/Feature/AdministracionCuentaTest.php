<?php

namespace Tests\Feature;

use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class AdministracionCuentaTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_auth_'.strtolower(Str::random(16));
        $previousPath = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->beforeApplicationDestroyed(function () use ($schema, $previousPath): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $previousPath]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        });
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000004_create_especialidades',
            '000006_create_usuarios', '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes',
            '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes', '000015_create_verificaciones_correo', '000016_create_medicos',
            '000017_create_medico_especialidad', '000019_create_citas', '000022_create_consultas_medicas', '000025_create_solicitudes_laboratorio'] as $name) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php'))->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function administrador(): array
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();

        return [$admin, $cookies];
    }

    private function estado(array &$cookies, Usuario $usuario, string $estado = 'Desactivada', array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => $estado, ...$extra]);
    }

    private function rol(array &$cookies, Usuario $usuario, string $rol, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => $rol, ...$extra]);
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_deactivate_revokes_all_devices_and_reactivate_preserves_identity_password_block_and_professional_or_patient_state(string $rol): void
    {
        [$admin, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth($rol);
        $primero = $this->csrfAuth();
        $segundo = $this->csrfAuth();
        $this->loginAuth($primero, $usuario)->assertOk();
        $this->loginAuth($segundo, $usuario)->assertOk();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['intentos_fallidos' => 3, 'bloqueado_hasta' => now('UTC')->addMinutes(10)]);
        $antes = $usuario->fresh()->getAttributes();
        $vinculo = $usuario->medico?->getAttributes() ?? $usuario->paciente?->getAttributes();
        $this->estado($cookies, $usuario)->assertOk()->assertJsonPath('data.estado_cuenta', 'Desactivada');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->navegador($primero, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($segundo, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($primero, $usuario)->assertUnauthorized();
        $this->estado($cookies, $usuario, 'Activa')->assertOk()->assertJsonPath('data.estado_cuenta', 'Activa');
        $this->navegador($segundo, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($segundo, $usuario)->assertUnauthorized();
        $despues = $usuario->fresh()->getAttributes();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['actualizado_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $this->assertSame($vinculo, $usuario->fresh()->medico?->getAttributes() ?? $usuario->fresh()->paciente?->getAttributes());
        $eventos = DB::table('bitacora')->whereIn('tipo_evento', ['CUENTA_DESACTIVADA', 'CUENTA_REACTIVADA'])->get();
        $this->assertCount(2, $eventos);
        foreach ($eventos as $evento) {
            $this->assertSame((string) $admin->getKey(), (string) $evento->id_usuario_responsable);
            $this->assertSame((string) $usuario->getKey(), (string) $evento->id_usuario_objetivo);
            $this->assertSame($rol, $evento->rol_objetivo);
            $this->assertSame('EXITO', $evento->resultado);
        }
        foreach ([self::PASSWORD, $antes['password_hash'], $antes['correo_acceso']] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($eventos));
        }
        $this->travel(11)->minutes();
        $this->loginAuth($segundo, $usuario)->assertOk()->assertJsonPath('data.rol', $rol);
    }

    public function test_last_admin_cannot_disable_or_demote_self_and_pending_or_restricted_admins_do_not_count(): void
    {
        [$admin, $cookies] = $this->administrador();
        $this->usuarioAuth(datos: ['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null, 'password_hash' => null]);
        $this->usuarioAuth(datos: ['cambio_clave_obligatorio' => true]);
        $this->usuarioAuth(datos: ['estado_cuenta' => 'Desactivada']);
        $this->usuarioAuth('Recepcionista');
        $this->estado($cookies, $admin)->assertConflict()->assertJsonPath('error.code', 'ULTIMO_ADMINISTRADOR');
        $this->rol($cookies, $admin, 'Recepcionista')->assertConflict()->assertJsonPath('error.code', 'ULTIMO_ADMINISTRADOR');
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', 'Administrador');
        $this->assertSame('Activa', $admin->fresh()->estado_cuenta);
        $this->assertSame('Administrador', $admin->fresh()->rol->nombre);
        $this->assertSame(2, DB::table('bitacora')->where('causa_codigo', 'ULTIMO_ADMINISTRADOR')->where('resultado', 'DENEGADO')->count());
    }

    public function test_last_admin_protection_rolls_back_a_new_professional_link_when_self_assigning_doctor(): void
    {
        [$admin, $cookies] = $this->administrador();
        $this->usuarioAuth('Médico');
        $id = $this->medicoLibre();
        $this->rol($cookies, $admin, 'Médico', ['id_medico' => $id, 'identidad_verificada' => true])
            ->assertConflict()->assertJsonPath('error.code', 'ULTIMO_ADMINISTRADOR');
        $this->assertSame('Administrador', $admin->fresh()->rol->nombre);
        $this->assertNull(DB::table('medicos')->where('id_medico', $id)->value('id_usuario'));
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', 'Administrador');
    }

    public function test_temporarily_blocked_other_admin_still_counts_and_self_demotion_logs_out_all_sessions(): void
    {
        [$admin, $cookies] = $this->administrador();
        $this->usuarioAuth(datos: ['intentos_fallidos' => 3, 'bloqueado_hasta' => now('UTC')->addMinutes(10)]);
        $otra = $this->csrfAuth();
        $this->loginAuth($otra, $admin)->assertOk();
        $this->usuarioAuth('Recepcionista');
        $this->rol($cookies, $admin, 'Recepcionista')->assertOk()->assertJsonPath('data.rol', 'Recepcionista');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->getKey())->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($otra, 'GET', '/api/auth/me')->assertUnauthorized();
        $evento = DB::table('bitacora')->where('tipo_evento', 'CUENTA_ROL_CAMBIADO')->sole();
        $this->assertSame('Administrador', $evento->rol_responsable);
        $this->assertSame('Recepcionista', $evento->rol_objetivo);
        $cambios = json_decode($evento->cambios_seguros, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Administrador', $cambios['antes']['rol']);
        $this->assertSame('Recepcionista', $cambios['despues']['rol']);
        $this->loginAuth($cookies, $admin)->assertOk()->assertJsonPath('data.rol', 'Recepcionista');
    }

    public function test_self_deactivation_with_another_admin_closes_current_context_and_requires_reactivation_by_other_admin(): void
    {
        [$admin, $cookies] = $this->administrador();
        [$otro, $otras] = $this->administrador();
        $this->estado($cookies, $admin)->assertOk();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->estado($otras, $admin, 'Activa')->assertOk();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->assertSame('Activa', $otro->fresh()->estado_cuenta);
    }

    public function test_admin_and_receptionist_transitions_preserve_credential_identity_and_revoke_all_prior_sessions(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $antes = $usuario->getAttributes();
        $otra = $this->csrfAuth();
        $this->loginAuth($otra, $usuario)->assertOk();
        $this->rol($cookies, $usuario, 'Administrador')->assertOk();
        $this->navegador($otra, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($otra, $usuario)->assertOk()->assertJsonPath('data.rol', 'Administrador');
        $this->rol($cookies, $usuario, 'Recepcionista')->assertOk();
        $this->navegador($otra, 'GET', '/api/auth/me')->assertUnauthorized();
        $despues = $usuario->fresh()->getAttributes();
        foreach (['id_usuario', 'correo_acceso', 'password_hash', 'correo_verificado_en', 'estado_cuenta', 'nombres_personal', 'apellidos_personal'] as $campo) {
            $this->assertSame($antes[$campo], $despues[$campo]);
        }
    }

    private function medicoLibre(string $nombres = 'Persona', string $apellidos = 'Ficticia'): int
    {
        return DB::table('medicos')->insertGetId(['codigo_medico' => Str::random(16), 'registro_profesional' => 'REG-FICTICIO',
            'nombres' => $nombres, 'apellidos' => $apellidos, 'telefono' => '70000000', 'correo_contacto' => 'profesional@example.test',
            'activo' => false], 'id_medico');
    }

    public function test_entry_to_doctor_requires_explicit_identity_check_and_matching_identity_then_preserves_link_on_exit_and_reentry(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->usuarioAuth('Médico');
        $id = $this->medicoLibre();
        $antes = $usuario->getAttributes();
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $id])->assertConflict()->assertJsonPath('error.code', 'PERSONAL_IDENTIDAD_INCOMPATIBLE');
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $id, 'identidad_verificada' => true])->assertOk();
        $this->assertSame((string) $id, (string) $usuario->fresh()->medico->getKey());
        $this->assertFalse($usuario->fresh()->medico->activo);
        $this->assertSame($antes['password_hash'], $usuario->fresh()->password_hash);
        $this->rol($cookies, $usuario, 'Administrador')->assertOk();
        $this->assertSame((string) $id, (string) $usuario->fresh()->medico->getKey());
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $id])->assertOk();
        $this->assertSame((string) $id, (string) $usuario->fresh()->medico->getKey());
    }

    public function test_mismatched_professional_or_another_persons_link_cannot_be_assigned(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $medico = $this->usuarioAuth('Médico');
        $ajeno = $this->medicoLibre('Identidad', 'Distinta');
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $ajeno, 'identidad_verificada' => true])->assertConflict();
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $medico->medico->getKey(), 'identidad_verificada' => true])->assertConflict();
        $this->assertNull($usuario->fresh()->medico);
        $this->assertSame('Recepcionista', $usuario->fresh()->rol->nombre);
        $this->rol($cookies, $medico, 'Administrador')->assertOk();
        $this->rol($cookies, $medico, 'Médico', ['id_medico' => $ajeno, 'identidad_verificada' => true])->assertConflict();
        $this->assertSame((string) $medico->medico->getKey(), (string) $medico->fresh()->medico->getKey());
    }

    private function cita(Usuario $medico, string $estado): int
    {
        $paciente = $this->usuarioAuth('Paciente');
        $consultorio = DB::table('consultorios')->insertGetId(['codigo_consultorio' => Str::random(10), 'nombre' => 'Consultorio ficticio'], 'id_consultorio');
        $especialidad = DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $medico->medico->getKey(), 'id_especialidad' => $especialidad]);

        return DB::table('citas')->insertGetId(['codigo_cita' => Str::random(16), 'id_paciente' => $paciente->paciente->getKey(),
            'id_medico' => $medico->medico->getKey(), 'id_especialidad' => $especialidad, 'id_consultorio' => $consultorio,
            'creada_por' => $paciente->getKey(), 'inicio_at' => now()->subHour(), 'fin_at' => now()->subHour()->addMinutes(15),
            'duracion_minutos' => 15, 'estado' => $estado, 'sede_atencion' => 'Sede ficticia', 'consultorio_atencion' => 'Consultorio ficticio'], 'id_cita');
    }

    public static function obligacionesMedicas(): array
    {
        return [['Programada', false], ['Confirmada', false], ['Atendida', false], ['Atendida', true], ['Cancelada', true]];
    }

    #[DataProvider('obligacionesMedicas')]
    public function test_medical_role_cannot_be_removed_when_attendance_or_clinical_closure_is_unverifiable(string $estado, bool $consulta): void
    {
        [, $cookies] = $this->administrador();
        $medico = $this->usuarioAuth('Médico');
        $id = $this->cita($medico, $estado);
        if ($consulta) {
            $idConsulta = DB::table('consultas_medicas')->insertGetId(['codigo_consulta' => Str::random(16), 'id_cita' => $id, 'iniciada_en' => null], 'id_consulta');
            DB::table('solicitudes_laboratorio')->insert(['id_consulta' => $idConsulta, 'estudio_solicitado' => 'Estudio ficticio', 'solicitada_en' => now()]);
        }
        $antes = DB::table('citas')->get()->toJson();
        $this->rol($cookies, $medico, 'Administrador')->assertConflict()->assertJsonPath('error.code', 'MEDICO_OBLIGACIONES_NO_RESUELTAS');
        $this->assertSame('Médico', $medico->fresh()->rol->nombre);
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
        $this->assertSame($consulta ? 1 : 0, DB::table('consultas_medicas')->count());
    }

    public static function estadosSinAtencion(): array
    {
        return [['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('estadosSinAtencion')]
    public function test_active_professional_cannot_exit_role_and_cancelled_or_absent_appointments_without_consultation_preserve_history(string $estado): void
    {
        [, $cookies] = $this->administrador();
        $this->usuarioAuth('Recepcionista');
        $medico = $this->usuarioAuth('Médico');
        DB::table('medicos')->where('id_usuario', $medico->getKey())->update(['activo' => true]);
        $this->rol($cookies, $medico, 'Recepcionista')->assertConflict()->assertJsonPath('error.code', 'MEDICO_OBLIGACIONES_NO_RESUELTAS');
        DB::table('medicos')->where('id_usuario', $medico->getKey())->update(['activo' => false]);
        $this->cita($medico, $estado);
        $this->rol($cookies, $medico, 'Recepcionista')->assertOk();
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame($estado, DB::table('citas')->sole()->estado);
        $this->assertNotNull($medico->fresh()->medico);
    }

    public function test_patient_access_uses_minimal_projection_and_cannot_modify_identity_credentials_role_or_link(): void
    {
        [, $cookies] = $this->administrador();
        $paciente = $this->usuarioAuth('Paciente');
        $antes = $paciente->getAttributes();
        $this->navegador($cookies, 'GET', '/api/usuarios/'.$paciente->getKey())->assertOk()->assertExactJson(['data' => [
            'id_usuario' => (string) $paciente->getKey(), 'correo_acceso' => $paciente->correo_acceso, 'rol' => 'Paciente',
            'estado_cuenta' => 'Activa', 'correo_verificado_en' => $paciente->correo_verificado_en->toIso8601String(),
        ]]);
        $this->rol($cookies, $paciente, 'Administrador')->assertConflict();
        foreach (['password', 'correo_acceso', 'id_paciente', 'id_usuario', 'id_objetivo', 'intentos_fallidos', 'activo', 'cambio_clave_obligatorio'] as $campo) {
            $this->estado($cookies, $paciente, extra: [$campo => 'indebido'])->assertStatus(422);
        }
        $this->assertSame($antes, $paciente->fresh()->getAttributes());
    }

    public function test_unverified_or_unlinked_patients_are_not_exposed_or_activated_and_pending_staff_cannot_bypass_invitation(): void
    {
        [, $cookies] = $this->administrador();
        $pendiente = $this->usuarioAuth('Paciente', ['estado_cuenta' => 'Pendiente', 'password_hash' => null, 'correo_verificado_en' => null]);
        $sinVinculo = $this->usuarioAuth('Paciente', vincular: false);
        $personal = $this->usuarioAuth('Recepcionista', ['estado_cuenta' => 'Pendiente', 'password_hash' => null, 'correo_verificado_en' => null]);
        foreach ([$pendiente, $sinVinculo] as $usuario) {
            $this->navegador($cookies, 'GET', '/api/usuarios/'.$usuario->getKey())->assertNotFound();
            $this->estado($cookies, $usuario, 'Activa')->assertNotFound();
        }
        $this->estado($cookies, $personal, 'Activa')->assertConflict();
        $this->estado($cookies, $personal)->assertConflict();
        $this->navegador($cookies, 'GET', '/api/usuarios?rol=Paciente')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_is_paginated_filtered_deterministically_and_never_serializes_sensitive_or_clinical_fields(): void
    {
        [, $cookies] = $this->administrador();
        $this->usuarioAuth('Recepcionista');
        $ultimo = $this->usuarioAuth('Recepcionista');
        $this->usuarioAuth('Paciente');
        $response = $this->navegador($cookies, 'GET', '/api/usuarios?rol=Recepcionista&estado_cuenta=Activa&per_page=1&page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id_usuario', (string) $ultimo->getKey());
        foreach (['password_hash', 'intentos_fallidos', 'bloqueado_hasta', 'paciente', 'documento_identidad', 'alergias', 'tokens_acceso'] as $campo) {
            $this->assertStringNotContainsString('"'.$campo.'"', $response->getContent());
        }
        $this->navegador($cookies, 'GET', '/api/usuarios?correo_acceso='.urlencode(strtoupper($ultimo->correo_acceso)))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_usuario', (string) $ultimo->getKey());
        foreach (['per_page=101', 'page=-1', 'rol=Otro', 'estado_cuenta=Suspendida', 'correo_acceso=invalido'] as $query) {
            $this->navegador($cookies, 'GET', '/api/usuarios?'.$query)->assertStatus(422);
        }
    }

    public static function ajenos(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('ajenos')]
    public function test_only_admin_can_list_inspect_or_modify_access_and_roles(string $rol): void
    {
        $objetivo = $this->usuarioAuth();
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/usuarios')->assertUnauthorized();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->navegador($cookies, 'GET', '/api/usuarios')->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/usuarios/'.$objetivo->getKey())->assertForbidden();
        $this->estado($cookies, $objetivo)->assertForbidden();
        $this->rol($cookies, $objetivo, 'Recepcionista')->assertForbidden();
    }

    public function test_mutations_require_origin_csrf_valid_ids_and_approved_transitions(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $uri = '/api/usuarios/'.$usuario->getKey().'/estado';
        $this->navegador($cookies, 'PATCH', $uri, ['estado_cuenta' => 'Desactivada'], [], false)->assertStatus(419);
        $this->navegador($cookies, 'PATCH', $uri, ['estado_cuenta' => 'Desactivada'], ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->estado($cookies, $usuario, 'Activa')->assertConflict();
        $this->estado($cookies, $usuario, 'Suspendida')->assertStatus(422);
        $this->rol($cookies, $usuario, 'Recepcionista')->assertConflict();
        $this->rol($cookies, $usuario, 'Paciente')->assertStatus(422);
        $this->rol($cookies, $usuario, 'Médico')->assertStatus(422);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/9223372036854775808/estado', ['estado_cuenta' => 'Desactivada'])->assertStatus(422);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/999999/estado', ['estado_cuenta' => 'Desactivada'])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_role_change_and_revocation_without_reporting_success(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->usuarioAuth('Médico');
        $id = $this->medicoLibre();
        $otra = $this->csrfAuth();
        $this->loginAuth($otra, $usuario)->assertOk();
        $ids = DB::table('sessions')->where('user_id', $usuario->getKey())->pluck('id')->all();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->rol($cookies, $usuario, 'Médico', ['id_medico' => $id, 'identidad_verificada' => true])->assertStatus(500);
        $this->assertSame('Recepcionista', $usuario->fresh()->rol->nombre);
        $this->assertNull($usuario->fresh()->medico);
        $this->assertSame(count($ids), DB::table('sessions')->whereIn('id', $ids)->count());
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($otra, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', 'Recepcionista');
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CUENTA_ROL_CAMBIADO')->where('resultado', 'EXITO')->count());
    }

    public function test_database_failure_during_revocation_rolls_back_access_state_and_keeps_target_sessions(): void
    {
        [, $cookies] = $this->administrador();
        $usuario = $this->usuarioAuth('Paciente');
        $otra = $this->csrfAuth();
        $this->loginAuth($otra, $usuario)->assertOk();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prueba_impedir_revocacion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Fallo ficticio de revocación' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER prueba_revocacion_trg BEFORE DELETE ON sessions
                FOR EACH ROW EXECUTE FUNCTION prueba_impedir_revocacion();
            SQL);
        $this->estado($cookies, $usuario)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertSame('Activa', $usuario->fresh()->estado_cuenta);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CUENTA_DESACTIVADA')->count());
        DB::statement('DROP TRIGGER prueba_revocacion_trg ON sessions');
        $this->navegador($otra, 'GET', '/api/auth/me')->assertOk();
    }
}
