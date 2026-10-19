<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\shared\utils\CredencialTemporal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class CredencialTemporalTest extends TestCase
{
    use AutenticacionFixtures;

    private const NUEVA = '  Definitiva ficticia segura 456  ';

    private const RESTRINGIDA = ['data' => ['acceso' => 'cambio_clave', 'cambio_clave_obligatorio' => true]];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_auth_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->beforeApplicationDestroyed(function () use ($schema, $anterior): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $anterior]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        });
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000006_create_usuarios',
            '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes', '000016_create_medicos'] as $nombre) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$nombre.'_table.php'))->up();
        }
        (require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php'))->up();
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function administrador(): array
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();

        return [$admin, $cookies];
    }

    private function emitir(array &$cookies, int|string $id, array $extra = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/usuarios/'.$id.'/credencial-temporal',
            ['identidad_verificada' => true, ...$extra], [], $csrf);
    }

    private function completar(array &$cookies, array $extra = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'PUT', '/api/auth/password/temporal',
            ['password' => self::NUEVA, 'password_confirmation' => self::NUEVA, ...$extra], [], $csrf);
    }

    public static function personal(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    #[DataProvider('personal')]
    public function test_staff_reset_preserves_identity_delivers_secret_once_and_completes_only_with_new_normal_login(string $rol): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth($rol);
        $anterior = $this->csrfAuth();
        $this->loginAuth($anterior, $usuario)->assertOk();
        $antes = $usuario->getAttributes();
        $emision = $this->emitir($admin, $usuario->getKey())->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $secreto = $emision->json('data.credencial_temporal');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{20}$/D', $secreto);
        $row = DB::table('tokens_acceso')->sole();
        $this->assertSame('CredencialTemporal', $row->proposito);
        $this->assertSame(CredencialTemporal::resumen($secreto), $row->token_hash);
        $this->assertSame(1800.0, CarbonImmutable::parse($row->emitido_en)->diffInSeconds(CarbonImmutable::parse($row->expira_en)));
        $this->assertSame($antes['password_hash'], $usuario->fresh()->password_hash);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->navegador($anterior, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($admin, 'GET', '/api/usuarios/'.$usuario->getKey())->assertOk()
            ->assertJsonMissing(['credencial_temporal' => $secreto]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk()->assertExactJson(self::RESTRINGIDA);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertExactJson(self::RESTRINGIDA);
        $this->navegador($cookies, 'PUT', '/api/auth/password', ['current_password' => $secreto,
            'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($cookies, 'GET', '/api/usuarios')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/personal', [])->assertForbidden();
        $this->completar($cookies, ['id_usuario' => '999999', 'rol' => 'Paciente', 'estado_cuenta' => 'Desactivada'])->assertNoContent()->assertContent('');
        $despues = $usuario->fresh();
        $this->assertFalse($despues->cambio_clave_obligatorio);
        $this->assertTrue(Hash::check(self::NUEVA, $despues->password_hash));
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['password_hash', 'actualizado_en'], true)) {
                $this->assertSame($valor, $despues->getAttributes()[$campo], $campo);
            }
        }
        $this->assertNotNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, self::NUEVA)->assertOk()->assertJsonPath('data.rol', $rol);
        $eventos = DB::table('bitacora')->get()->toJson();
        foreach ([$secreto, $row->token_hash, self::NUEVA, $despues->password_hash] as $privado) {
            $this->assertStringNotContainsString($privado, $eventos);
        }
        Mail::assertNothingSent();
    }

    public static function otrosRoles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('otrosRoles')]
    public function test_only_normal_administrator_can_issue_and_never_for_a_patient(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $objetivo = $this->usuarioAuth('Recepcionista');
        $this->emitir($cookies, $objetivo->getKey())->assertForbidden();
        [, $admin] = $this->administrador();
        $paciente = $this->usuarioAuth('Paciente');
        $this->emitir($admin, $paciente->getKey())->assertConflict();
        $this->assertFalse($paciente->fresh()->cambio_clave_obligatorio);
        $this->assertSame(0, DB::table('tokens_acceso')->count());
    }

    public function test_requires_real_csrf_origin_identity_attestation_and_route_id_without_extra_fields(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->emitir($admin, $usuario->getKey(), csrf: false)->assertStatus(419);
        $this->emitir($admin, $usuario->getKey(), ['identidad_verificada' => false])->assertStatus(422);
        $this->emitir($admin, $usuario->getKey(), ['id_usuario' => 999999])->assertStatus(422);
        $this->emitir($admin, '9223372036854775808')->assertStatus(422);
        $this->emitir($admin, 999999)->assertNotFound();
        $this->travel(1)->minutes();
        $this->navegador($admin, 'POST', '/api/usuarios/'.$usuario->getKey().'/credencial-temporal', ['identidad_verificada' => true],
            ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->assertSame(0, DB::table('tokens_acceso')->count());
    }

    public function test_pending_unverified_or_deactivated_staff_do_not_bypass_invitation_or_access_state(): void
    {
        [, $admin] = $this->administrador();
        foreach ([['estado_cuenta' => 'Pendiente', 'password_hash' => null, 'correo_verificado_en' => null],
            ['estado_cuenta' => 'Desactivada']] as $datos) {
            $usuario = $this->usuarioAuth('Recepcionista', $datos);
            $this->emitir($admin, $usuario->getKey())->assertConflict();
            $this->assertFalse($usuario->fresh()->cambio_clave_obligatorio);
        }
        $this->assertSame(0, DB::table('tokens_acceso')->count());
    }

    public function test_reissue_wait_and_hour_limit_and_old_links_and_restricted_sessions_are_invalidated(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->emitir($admin, $usuario->getKey())->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(30)->seconds();
        $this->emitir($admin, $usuario->getKey())->assertStatus(429)->assertHeader('Retry-After', '30');
        $this->travel(30)->seconds();
        $nuevo = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $this->assertNotSame($secreto, $nuevo);
        $this->completar($cookies)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, $nuevo)->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(1)->minutes();
            $this->emitir($admin, $usuario->getKey())->assertCreated();
        }
        $this->travel(1)->minutes();
        $this->emitir($admin, $usuario->getKey())->assertStatus(429)->assertHeader('Retry-After', '3300');
        $this->assertSame(5, DB::table('tokens_acceso')->count());
        $this->assertSame(1, DB::table('tokens_acceso')->whereNull('invalidado_en')->count());
    }

    public function test_expiry_is_not_extended_by_login_or_refresh_and_old_password_never_returns(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $expira = DB::table('tokens_acceso')->sole()->expira_en;
        $this->travel(29)->minutes();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertExactJson(self::RESTRINGIDA);
        $this->travel(1)->minutes();
        $this->completar($cookies)->assertUnauthorized();
        $this->assertSame($expira, DB::table('tokens_acceso')->sole()->expira_en);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
    }

    public function test_explicit_restricted_logout_requires_reissue_and_normal_session_cannot_complete(): void
    {
        [, $admin] = $this->administrador();
        $this->completar($admin)->assertForbidden();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->assertNotNull(DB::table('tokens_acceso')->sole()->invalidado_en);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
    }

    public function test_reentry_keeps_only_one_restricted_device_until_final_consumption(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $primero = $this->csrfAuth();
        $segundo = $this->csrfAuth();
        $this->loginAuth($primero, $usuario, $secreto)->assertOk();
        $this->loginAuth($segundo, $usuario, $secreto)->assertOk();
        $this->completar($primero)->assertStatus(419);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->completar($segundo)->assertNoContent();
    }

    public function test_three_incorrect_temporary_passwords_block_ten_minutes_without_extension_and_success_resets(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        for ($i = 0; $i < 3; $i++) {
            $this->loginAuth($cookies, $usuario, 'INCORRECTA')->assertUnauthorized();
        }
        $bloqueo = $usuario->fresh()->bloqueado_hasta;
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
        $this->assertTrue($bloqueo->equalTo($usuario->fresh()->bloqueado_hasta));
        $this->travel(11)->minutes();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
    }

    public function test_issuance_preserves_existing_lock_and_invalidates_recovery_without_enabling_forgot(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista', ['intentos_fallidos' => 3, 'bloqueado_hasta' => now('UTC')->addMinutes(10)]);
        DB::table('tokens_acceso')->insert(['id_usuario' => $usuario->getKey(), 'proposito' => 'RecuperacionClave',
            'token_hash' => Str::random(64), 'emitido_en' => now('UTC'), 'expira_en' => now('UTC')->addHour()]);
        $bloqueo = $usuario->bloqueado_hasta;
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $this->assertTrue($bloqueo->equalTo($usuario->fresh()->bloqueado_hasta));
        $this->assertSame(3, $usuario->fresh()->intentos_fallidos);
        $this->assertNotNull(DB::table('tokens_acceso')->where('proposito', 'RecuperacionClave')->sole()->invalidado_en);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        Mail::assertNothingSent();
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
    }

    public function test_last_administrator_reset_rolls_back_and_self_reset_with_another_admin_logs_out(): void
    {
        [$usuario, $cookies] = $this->administrador();
        $this->emitir($cookies, $usuario->getKey())->assertConflict()->assertJsonPath('error.code', 'ULTIMO_ADMINISTRADOR');
        $this->assertFalse($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
        $this->usuarioAuth();
        $this->emitir($cookies, $usuario->getKey())->assertCreated();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_deactivation_reactivation_and_role_change_do_not_resurrect_a_temporary_secret(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertUnauthorized();
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->travel(1)->minutes();
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Administrador'])->assertOk();
        $this->loginAuth($cookies, $usuario->fresh(), $secreto)->assertUnauthorized();
    }

    public function test_invalid_password_and_csrf_do_not_consume_restricted_credential(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->completar($cookies, csrf: false)->assertStatus(419);
        $this->completar($cookies, ['password' => 'corta'])->assertStatus(422);
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->completar($cookies)->assertNoContent();
    }

    public function test_issuance_and_completion_roll_back_when_required_audit_fails(): void
    {
        [$actor, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoria'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->emitir($admin, $usuario->getKey())->assertStatus(500);
        $this->assertFalse($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($admin, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->loginAuth($admin, $actor)->assertOk();
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->completar($cookies)->assertStatus(500);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->app->forgetInstance(BitacoraRepository::class);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->completar($cookies)->assertNoContent();
    }

    public function test_revoked_device_logout_does_not_invalidate_a_newer_restricted_session(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $anterior = $this->csrfAuth();
        $actual = $this->csrfAuth();
        $this->loginAuth($anterior, $usuario, $secreto)->assertOk();
        $this->loginAuth($actual, $usuario, $secreto)->assertOk();
        $this->navegador($anterior, 'POST', '/api/auth/logout')->assertStatus(419);
        $this->navegador($actual, 'GET', '/api/auth/me')->assertOk()->assertExactJson(self::RESTRINGIDA);
        $this->assertNull(DB::table('tokens_acceso')->sole()->invalidado_en);
        $this->completar($actual)->assertNoContent();
    }

    public function test_technical_limit_for_restricted_changes_does_not_consume_secret_or_block_login(): void
    {
        [, $admin] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $secreto = $this->emitir($admin, $usuario->getKey())->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->completar($cookies, ['password' => 'corta'])->assertStatus(422);
        }
        $this->completar($cookies)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
        $this->travel(1)->minutes();
        $this->completar($cookies)->assertNoContent();
    }

    public function test_target_hourly_limit_is_shared_by_different_administrators_and_ips_and_recovers_at_boundary(): void
    {
        [, $primero] = $this->administrador();
        [, $segundo] = $this->administrador();
        $usuario = $this->usuarioAuth('Recepcionista');
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        for ($i = 0; $i < 5; $i++) {
            $this->navegador($primero, 'POST', '/api/usuarios/'.$usuario->getKey().'/credencial-temporal', ['identidad_verificada' => true],
                ['X-Test-IP' => '192.0.2.40'])->assertCreated();
            $this->travel(1)->minutes();
        }
        $this->navegador($segundo, 'POST', '/api/usuarios/'.$usuario->getKey().'/credencial-temporal', ['identidad_verificada' => true],
            ['X-Test-IP' => '192.0.2.41'])->assertStatus(429)->assertHeader('Retry-After', '3300');
        $this->travel(55)->minutes();
        $this->emitir($segundo, $usuario->getKey())->assertCreated();
        $this->assertSame(6, DB::table('tokens_acceso')->count());
    }
}
