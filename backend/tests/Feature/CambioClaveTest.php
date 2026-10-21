<?php

namespace Tests\Feature;

use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class CambioClaveTest extends TestCase
{
    use AutenticacionFixtures;

    private const NUEVA = '  Nueva frase ficticia 456  ';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function cambio(array &$cookies, array $datos = [], array $headers = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'PUT', '/api/auth/password', array_replace([
            'current_password' => self::PASSWORD, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ], $datos), $headers, $csrf);
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_each_role_changes_own_password_renews_current_session_and_revokes_previous_devices(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol);
        $antes = $usuario->getAttributes();
        $cookies = $this->csrfAuth();
        $otro = $this->csrfAuth();
        $tercero = $this->csrfAuth();
        foreach ([&$cookies, &$otro, &$tercero] as &$dispositivo) {
            $this->loginAuth($dispositivo, $usuario)->assertOk();
        }
        unset($dispositivo);
        $previas = $cookies;
        $ids = DB::table('sessions')->where('user_id', $usuario->getKey())->pluck('id')->all();
        $this->cambio($cookies)->assertNoContent()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame($previas['clinica_vida_salud_session'], $cookies['clinica_vida_salud_session']);
        $this->assertNotSame($previas['XSRF-TOKEN'], $cookies['XSRF-TOKEN']);
        $this->assertSame(0, DB::table('sessions')->whereIn('id', $ids)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $nuevo = $usuario->fresh();
        $this->assertTrue(Hash::check(self::NUEVA, $nuevo->password_hash));
        $this->assertFalse(Hash::check(self::PASSWORD, $nuevo->password_hash));
        $this->assertFalse(Hash::check(trim(self::NUEVA), $nuevo->password_hash));
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['password_hash', 'actualizado_en'], true)) {
                $this->assertSame($valor, $nuevo->getRawOriginal($campo), $campo);
            }
        }
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id_usuario', (string) $usuario->getKey());
        foreach ([&$previas, &$otro, &$tercero] as &$anterior) {
            $this->navegador($anterior, 'GET', '/api/auth/me')->assertUnauthorized();
        }
        unset($anterior);
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
        $this->loginAuth($cookies, $usuario, self::NUEVA)->assertOk();
        $evento = DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_CAMBIO_CLAVE')->sole();
        $this->assertSame('EXITO', $evento->resultado);
        $this->assertSame((string) $usuario->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        foreach ([self::PASSWORD, self::NUEVA, $nuevo->password_hash, $nuevo->correo_acceso, ...$ids] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($evento));
        }
    }

    public function test_wrong_current_password_commits_denied_audit_without_mutating_credentials_sessions_or_login_block(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $antes = DB::table('sessions')->where('user_id', $usuario->getKey())->value('id');
        $this->cambio($cookies, ['current_password' => trim(self::PASSWORD)])->assertStatus(422)->assertExactJson([
            'success' => false, 'error' => ['code' => 'AUTH_CLAVE_ACTUAL_INVALIDA', 'message' => 'La contraseña actual no es correcta.'],
        ]);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
        $this->assertTrue(DB::table('sessions')->where('id', $antes)->exists());
        $this->assertSame('DENEGADO', DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_CAMBIO_CLAVE')->sole()->resultado);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public static function invalidos(): array
    {
        return [
            'actual ausente' => [['current_password' => null], 'current_password'],
            'actual array' => [['current_password' => []], 'current_password'],
            'actual excesiva' => [['current_password' => str_repeat('a', 1025)], 'current_password'],
            'minimo' => [['password' => '12345678901', 'password_confirmation' => '12345678901'], 'password'],
            'confirmacion' => [['password_confirmation' => 'otra clave distinta'], 'password'],
            'confirmacion ausente' => [['password_confirmation' => null], 'password_confirmation'],
            'nueva array' => [['password' => []], 'password'],
            'nueva ausente' => [['password' => null], 'password'],
            'bytes bcrypt' => [['password' => str_repeat('á', 37), 'password_confirmation' => str_repeat('á', 37)], 'password'],
            'caracter nulo' => [['password' => "Clave ficticia\0larga", 'password_confirmation' => "Clave ficticia\0larga"], 'password'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_invalid_inputs_do_not_change_hash_or_revoke_session(array $datos, string $campo): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->cambio($cookies, $datos)->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION_ERROR')->assertJsonValidationErrors($campo, 'error.details');
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_CAMBIO_CLAVE')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public static function limitesValidos(): array
    {
        return [[str_repeat('a', 12)], [str_repeat('a', 72)], [str_repeat('á', 12)]];
    }

    #[DataProvider('limitesValidos')]
    public function test_minimum_unicode_and_bcrypt_boundary_are_accepted(string $nueva): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->cambio($cookies, ['password' => $nueva, 'password_confirmation' => $nueva])->assertNoContent();
        $this->assertTrue(Hash::check($nueva, $usuario->fresh()->password_hash));
    }

    public function test_target_and_privileged_fields_cannot_change_another_account(): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $ajeno = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->cambio($cookies, ['id_usuario' => $ajeno->getKey(), 'rol' => 'Administrador', 'correo_acceso' => $ajeno->correo_acceso, 'estado_cuenta' => 'Desactivada'])->assertNoContent();
        $this->assertSame($ajeno->password_hash, $ajeno->fresh()->password_hash);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', 'Paciente')->assertJsonPath('data.id_usuario', (string) $usuario->getKey());
    }

    public static function sesionesInvalidas(): array
    {
        return [['anonima'], ['pendiente'], ['desactivada'], ['obligatoria'], ['revocada'], ['expirada']];
    }

    #[DataProvider('sesionesInvalidas')]
    public function test_only_an_active_normal_session_can_change_password(string $caso): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        if ($caso !== 'anonima') {
            $this->loginAuth($cookies, $usuario)->assertOk();
            match ($caso) {
                'pendiente' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null]),
                'desactivada' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'obligatoria' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['cambio_clave_obligatorio' => true]),
                'revocada' => DB::table('sessions')->where('user_id', $usuario->getKey())->delete(),
                'expirada' => DB::table('sessions')->where('user_id', $usuario->getKey())->update(['last_activity' => now()->subMinutes(121)->timestamp]),
            };
        }
        // GET no mutante obtiene el CSRF anónimo tras perder una sesión revocada/expirada.
        if (in_array($caso, ['revocada', 'expirada'], true)) {
            $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        }
        $this->cambio($cookies)->assertUnauthorized();
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
    }

    public function test_csrf_origin_and_old_csrf_after_renewal_are_rejected(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->cambio($cookies, [], [], false)->assertStatus(419);
        $this->cambio($cookies, [], ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $previo = $cookies['XSRF-TOKEN'];
        $this->cambio($cookies)->assertNoContent();
        $this->cambio($cookies, [], ['X-XSRF-TOKEN' => $previo], false)->assertStatus(419);
    }

    public function test_change_limit_is_shared_by_devices_and_ips_and_does_not_extend_or_block_login(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $otro = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->loginAuth($otro, $usuario)->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->cambio($cookies, ['current_password' => 'incorrecta'])->assertStatus(422);
        }
        $this->cambio($otro, [], ['X-Test-IP' => '192.0.2.80'])->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(20)->seconds();
        $this->cambio($otro)->assertStatus(429)->assertHeader('Retry-After', '40');
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
        $this->navegador($otro, 'GET', '/api/auth/me')->assertOk();
        $this->travel(40)->seconds();
        $this->cambio($otro)->assertNoContent();
    }

    public static function fallos(): array
    {
        return [['auditoria'], ['hash'], ['sesion']];
    }

    #[DataProvider('fallos')]
    public function test_required_write_failure_rolls_back_hash_all_sessions_and_success_audit(string $fallo): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $otro = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->loginAuth($otro, $usuario)->assertOk();
        $ids = DB::table('sessions')->where('user_id', $usuario->getKey())->pluck('id')->all();
        if ($fallo === 'auditoria') {
            $fake = Mockery::mock(BitacoraRepository::class);
            $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio sin secretos'));
            $this->app->instance(BitacoraRepository::class, $fake);
        } else {
            $tabla = $fallo === 'hash' ? 'usuarios' : 'sessions';
            $evento = $fallo === 'hash' ? 'UPDATE OF password_hash' : 'INSERT';
            $condicion = $fallo === 'hash' ? 'true' : 'NEW.user_id IS NOT NULL';
            DB::unprepared(<<<SQL
                CREATE FUNCTION prueba_fallo_cambio() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF {$condicion} THEN
                        RAISE EXCEPTION 'fallo ficticio' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER prueba_cambio_trg BEFORE {$evento} ON {$tabla}
                    FOR EACH ROW EXECUTE FUNCTION prueba_fallo_cambio();
                SQL);
        }
        $this->cambio($cookies)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame(2, DB::table('sessions')->whereIn('id', $ids)->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_CAMBIO_CLAVE')->where('resultado', 'EXITO')->count());
        $this->assertFalse($this->app['auth']->guard('web')->check());
        $this->navegador($otro, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_an_existing_login_block_is_preserved_by_an_ordinary_change(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $fin = now()->addMinutes(10)->startOfSecond()->toImmutable();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['intentos_fallidos' => 3, 'bloqueado_hasta' => $fin]);
        $persistido = $usuario->fresh()->bloqueado_hasta;
        $this->cambio($cookies)->assertNoContent();
        $this->assertSame(3, $usuario->fresh()->intentos_fallidos);
        $this->assertTrue($persistido->equalTo($usuario->fresh()->bloqueado_hasta));
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }
}
