<?php

namespace Tests\Feature;

use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        // Ejercitar CSRF real conservando la base aislada validada por TestCase.
        $this->app['env'] = 'local';
    }

    #[DataProvider('roles')]
    public function test_real_login_restores_each_role_from_database_and_cookie_and_logout_revokes_it(string $rol, string $nombre): void
    {
        $usuario = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $anonimas = $cookies;
        $this->loginAuth($cookies, $usuario)->assertOk()->assertExactJson(['data' => [
            'id_usuario' => (string) $usuario->getKey(), 'correo_acceso' => $usuario->correo_acceso,
            'nombre_visible' => $nombre, 'rol' => $rol,
        ]])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame($anonimas['clinica_vida_salud_session'], $cookies['clinica_vida_salud_session']);
        $sesion = DB::table('sessions')->where('user_id', $usuario->getKey())->sole();
        $this->assertStringNotContainsString($usuario->correo_acceso, $sesion->payload);
        $this->assertStringNotContainsString('password_hash_web', base64_decode($sesion->payload));
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', $rol);
        $this->navegador($anonimas, 'GET', '/api/auth/me')->assertUnauthorized();
        $anteriores = $cookies;
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent()->assertContent('');
        $this->assertFalse(DB::table('sessions')->where('id', $sesion->id)->exists());
        $this->assertNotSame($anteriores['XSRF-TOKEN'], $cookies['XSRF-TOKEN']);
        $this->navegador($anteriores, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_LOGOUT')->count());
    }

    public static function roles(): array
    {
        return [['Administrador', 'Persona Ficticia'], ['Recepcionista', 'Persona Ficticia'], ['Médico', 'Medico Ficticio'], ['Paciente', 'Paciente Ficticio']];
    }

    public function test_login_normalizes_email_without_trimming_or_changing_password_and_ignores_privileged_fields(): void
    {
        $usuario = $this->usuarioAuth('Recepcionista', ['correo_acceso' => ' PERSONA@EXAMPLE.TEST ']);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/login', [
            'correo_acceso' => '  Persona@Example.Test  ', 'password' => self::PASSWORD,
            'rol' => 'Administrador', 'id_usuario' => 999999, 'estado_cuenta' => 'Activa', 'remember' => true,
        ])->assertOk()->assertJsonPath('data.rol', 'Recepcionista');
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertCount(2, $cookies);
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'otra@example.test', 'password' => 'otra'])->assertConflict();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $usuario, trim(self::PASSWORD))->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, strtolower(self::PASSWORD))->assertUnauthorized();
    }

    #[DataProvider('denegaciones')]
    public function test_login_denials_do_not_reveal_account_state(array $cambios, bool $existe, string $password): void
    {
        $usuario = $this->usuarioAuth('Administrador', $cambios);
        $correo = $existe ? $usuario->correo_acceso : 'inexistente@example.test';
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => $correo, 'password' => $password])
            ->assertUnauthorized()->assertExactJson(['success' => false, 'error' => [
                'code' => 'AUTH_CREDENCIALES_INVALIDAS',
                'message' => 'No se pudo iniciar sesión. Verifica tus datos o inténtalo más tarde.',
            ]]);
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $evento = DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_LOGIN')->sole();
        $this->assertSame('ANONIMO', $evento->tipo_actor);
        $this->assertNull($evento->id_usuario_responsable);
    }

    public static function denegaciones(): array
    {
        return [
            'correo inexistente' => [[], false, self::PASSWORD],
            'password incorrecto' => [[], true, 'incorrecta'],
            'pendiente sin hash' => [['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null, 'password_hash' => null], true, self::PASSWORD],
            'desactivada' => [['estado_cuenta' => 'Desactivada'], true, self::PASSWORD],
            'bloqueada' => [['bloqueado_hasta' => '2099-01-01T12:00:00Z', 'intentos_fallidos' => 3], true, self::PASSWORD],
            'cambio obligatorio' => [['cambio_clave_obligatorio' => true], true, self::PASSWORD],
        ];
    }

    #[DataProvider('rolesSinVinculo')]
    public function test_professional_or_patient_role_requires_its_own_identity_link(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol, [], false);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
    }

    public static function rolesSinVinculo(): array
    {
        return [['Paciente'], ['Médico']];
    }

    public function test_three_errors_commit_block_and_attempts_do_not_extend_it_then_expiry_and_success_reset_counter(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        for ($i = 1; $i <= 3; $i++) {
            $this->loginAuth($cookies, $usuario, 'incorrecta')->assertUnauthorized();
            $this->assertSame($i, $usuario->fresh()->intentos_fallidos);
        }
        $fin = $usuario->fresh()->bloqueado_hasta;
        $this->assertTrue($fin->equalTo(now()->addMinutes(10)));
        $this->travel(1)->minutes();
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
        $this->assertTrue($fin->equalTo($usuario->fresh()->bloqueado_hasta));
        $this->assertSame(3, $usuario->fresh()->intentos_fallidos);
        $this->travelTo($fin);
        $this->loginAuth($cookies, $usuario, 'incorrecta')->assertUnauthorized();
        $this->assertSame(1, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertSame(5, DB::table('bitacora')->where('resultado', 'DENEGADO')->count());
    }

    public function test_request_limits_apply_to_unknown_accounts_and_do_not_extend_window(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        $cookies = $this->csrfAuth();
        for ($i = 0; $i < 5; $i++) {
            $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'ausente@example.test', 'password' => 'incorrecta'])->assertUnauthorized();
        }
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'ausente@example.test', 'password' => 'incorrecta'])
            ->assertStatus(429)->assertHeader('Retry-After', '60')->assertHeader('Access-Control-Expose-Headers', 'Retry-After');
        $this->travel(20)->seconds();
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'ausente@example.test', 'password' => 'incorrecta'])
            ->assertStatus(429)->assertHeader('Retry-After', '40');
        foreach (DB::table('controles_intentos')->get() as $control) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $control->clave_ambito_hash);
        }
        $this->travel(40)->seconds();
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'ausente@example.test', 'password' => 'incorrecta'])->assertUnauthorized();
    }

    public function test_ip_limit_cannot_be_bypassed_by_switching_email_or_untrusted_forwarded_header(): void
    {
        $cookies = $this->csrfAuth();
        for ($i = 0; $i < 20; $i++) {
            $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => "ausente{$i}@example.test", 'password' => 'incorrecta'], ['X-Forwarded-For' => '192.0.2.'.($i + 30)])
                ->assertUnauthorized();
        }
        $this->navegador($cookies, 'POST', '/api/auth/login', ['correo_acceso' => 'otro@example.test', 'password' => 'incorrecta'])->assertStatus(429);
    }

    #[DataProvider('invalidInputs')]
    public function test_structural_validation_has_safe_field_errors(array $datos): void
    {
        $cookies = $this->csrfAuth();
        $response = $this->navegador($cookies, 'POST', '/api/auth/login', $datos)->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION_ERROR');
        $this->assertArrayHasKey('details', $response->json('error'));
        $this->assertSame(0, DB::table('usuarios')->count());
    }

    public static function invalidInputs(): array
    {
        return [[[]], [['username' => 'persona', 'password' => 'ficticia']], [['correo_acceso' => 'no-email', 'password' => 'x']], [['correo_acceso' => ['a'], 'password' => ['b']]], [['correo_acceso' => 'persona@example.test', 'password' => str_repeat('x', 1025)]]];
    }

    public function test_csrf_and_origin_are_enforced_before_credentials(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $datos = ['correo_acceso' => $usuario->correo_acceso, 'password' => self::PASSWORD];
        $this->navegador($cookies, 'POST', '/api/auth/login', $datos, [], false)->assertStatus(419)->assertJsonPath('error.code', 'CSRF_INVALIDO');
        foreach ([['Origin' => 'https://ajeno.example.test'], ['Origin' => null], ['Origin' => 'http://localhost:5173', 'Referer' => 'https://ajeno.example.test/'], ['Origin' => 'http://localhost:5173.evil.test'], ['Origin' => 'http://localhost:5173/path']] as $headers) {
            $this->navegador($cookies, 'POST', '/api/auth/login', $datos, $headers)->assertForbidden()->assertJsonPath('error.code', 'ORIGEN_NO_PERMITIDO');
        }
        $this->assertSame(0, DB::table('bitacora')->count());
        $this->navegador($cookies, 'POST', '/api/auth/login', $datos, ['Origin' => null, 'Referer' => 'http://localhost:5173/login'])->assertOk();
    }

    #[DataProvider('revocaciones')]
    public function test_existing_sessions_reject_current_account_changes(string $cambio): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $sesion = DB::table('sessions')->whereNotNull('user_id')->sole();
        match ($cambio) {
            'estado' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']),
            'password' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['password_hash' => Hash::make('Nueva frase ficticia 456')]),
            'rol' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['id_rol' => DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol')]),
            'obligatorio' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['cambio_clave_obligatorio' => true]),
            'expirada' => DB::table('sessions')->where('id', $sesion->id)->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]),
            'revocada' => DB::transaction(function () use ($usuario): void {
                DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
                $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey());
            }),
        };
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO');
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['password'], ['rol'], ['obligatorio'], ['expirada'], ['revocada']];
    }

    public function test_logout_does_not_revoke_other_devices_but_coordinated_account_revocation_does(): void
    {
        $usuario = $this->usuarioAuth();
        $primero = $this->csrfAuth();
        $segundo = $this->csrfAuth();
        $this->loginAuth($primero, $usuario)->assertOk();
        $this->loginAuth($segundo, $usuario)->assertOk();
        $this->navegador($primero, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($segundo, 'GET', '/api/auth/me')->assertOk();
        $tercero = $this->csrfAuth();
        $this->loginAuth($tercero, $usuario)->assertOk();
        $this->assertSame(2, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        DB::transaction(function () use ($usuario): void {
            DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
            DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']);
            $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey());
        });
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Activa']);
        $this->navegador($segundo, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($tercero, 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_temporary_login_block_does_not_revoke_an_existing_normal_session(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['intentos_fallidos' => 3, 'bloqueado_hasta' => now()->addMinutes(10)]);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_role_middleware_distinguishes_anonymous_and_authenticated_forbidden_actor(): void
    {
        Route::middleware(['api', 'auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class, ExigirRol::class.':Administrador'])
            ->get('/api/prueba-rol', fn () => response()->noContent());
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/prueba-rol')->assertUnauthorized();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->navegador($cookies, 'GET', '/api/prueba-rol')->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_audit_failure_rolls_back_login_session_and_never_exposes_the_exception(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Secreto ficticio SQL password_hash'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->loginAuth($cookies, $usuario)->assertStatus(500)->assertExactJson(['success' => false, 'error' => [
            'code' => 'ERROR_INTERNO', 'message' => 'No se pudo completar la operación.',
        ]]);
        $this->assertFalse($this->app['auth']->guard('web')->check());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_audit_contains_no_password_hash_cookie_email_or_session_identifier(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $id = DB::table('sessions')->whereNotNull('user_id')->value('id');
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $eventos = json_encode(DB::table('bitacora')->get());
        foreach ([self::PASSWORD, $usuario->password_hash, $usuario->correo_acceso, $id] as $secreto) {
            $this->assertStringNotContainsString($secreto, $eventos);
        }
    }

    public function test_session_insert_failure_rolls_back_success_and_cleans_the_guard(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prueba_rechazar_sesion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.user_id IS NOT NULL THEN
                    RAISE EXCEPTION 'fallo ficticio de persistencia de sesión' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER prueba_sesion_trg BEFORE INSERT ON sessions
                FOR EACH ROW EXECUTE FUNCTION prueba_rechazar_sesion();
            SQL);
        $this->loginAuth($cookies, $usuario)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->assertSame(0, DB::table('bitacora')->where('resultado', 'EXITO')->count());
        $this->assertFalse($this->app['auth']->guard('web')->check());
    }

    public function test_logout_audit_failure_does_not_claim_a_confirmed_logout(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $id = DB::table('sessions')->whereNotNull('user_id')->value('id');
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertStatus(500);
        $this->assertTrue(DB::table('sessions')->where('id', $id)->exists());
        $this->assertFalse($this->app['auth']->guard('web')->check());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_LOGOUT')->count());
    }

    public function test_removing_patient_identity_or_session_context_revokes_access(): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->navegador($cookies, 'GET', '/api/auth/me?id_usuario=999999')->assertOk()->assertJsonPath('data.id_usuario', (string) $usuario->getKey());
        DB::table('pacientes')->where('id_usuario', $usuario->getKey())->update(['id_usuario' => null]);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_a_historical_professional_link_does_not_change_the_current_administrative_role(): void
    {
        $usuario = $this->usuarioAuth('Médico');
        $admin = DB::table('roles')->insertGetId(['nombre' => 'Administrador'], 'id_rol');
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['id_rol' => $admin]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk()->assertJsonPath('data.rol', 'Administrador')->assertJsonPath('data.nombre_visible', 'Persona Ficticia');
    }

    #[DataProvider('contextoAusente')]
    public function test_session_without_initial_security_context_cannot_be_upgraded_on_its_first_request(string $clave): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $row = DB::table('sessions')->whereNotNull('user_id')->sole();
        $payload = json_decode($this->app['encrypter']->decrypt(base64_decode($row->payload)), true, 512, JSON_THROW_ON_ERROR);
        unset($payload[$clave]);
        DB::table('sessions')->where('id', $row->id)->update([
            'payload' => base64_encode($this->app['encrypter']->encrypt(json_encode($payload))),
        ]);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public static function contextoAusente(): array
    {
        return [['password_hash_web'], ['contexto_autenticacion']];
    }
}
