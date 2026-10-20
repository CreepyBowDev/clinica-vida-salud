<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\CorreoSeguridadService;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\CambioCorreoRepository;
use Clinica\data\repositories\TokenAccesoRepository;
use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\utils\CambioCorreoMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class CambioCorreoTest extends TestCase
{
    use AutenticacionFixtures;

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
            '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes',
            '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes',
            '000015_create_verificaciones_correo', '000016_create_medicos'] as $nombre) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$nombre.'_table.php'))->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function titular(): array
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();

        return [$usuario, $cookies];
    }

    private function solicitar(array &$cookies, string $destino = 'nuevo@example.test', string $password = self::PASSWORD): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => $destino, 'current_password' => $password]);
    }

    private function verificar(array &$cookies, string $codigo): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/verificar', compact('codigo'));
    }

    private function codigo(): string
    {
        return Mail::sent(CambioCorreoMail::class)->last()->codigo;
    }

    public function test_confirmation_preserves_fiche_and_password_revokes_all_sessions_and_recoveries_and_requires_new_login(): void
    {
        [$usuario, $cookies] = $this->titular();
        $otras = $this->csrfAuth();
        $this->loginAuth($otras, $usuario)->assertOk();
        $ficha = DB::table('pacientes')->sole();
        $hash = $usuario->password_hash;
        $this->app->make(TokenAccesoRepository::class)->emitirRecuperacion($usuario->getKey(), 'hash-ficticio', CarbonImmutable::now('UTC'));
        $this->solicitar($cookies, ' NUEVO@EXAMPLE.TEST ')->assertStatus(202)->assertJsonPath('data.envio', 'simulado');
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->value('correo_acceso'));
        $codigo = $this->codigo();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $codigo);
        $this->assertNull(DB::table('verificaciones_correo')->value('invalidado_en'));
        $this->assertNotSame($codigo, DB::table('verificaciones_correo')->value('codigo_hash'));
        $this->verificar($cookies, $codigo)->assertNoContent();
        $this->assertSame('nuevo@example.test', DB::table('usuarios')->value('correo_acceso'));
        $this->assertSame($hash, DB::table('usuarios')->value('password_hash'));
        $this->assertEquals($ficha, DB::table('pacientes')->sole());
        $this->assertNotNull(DB::table('verificaciones_correo')->value('consumido_en'));
        $this->assertNotNull(DB::table('tokens_acceso')->value('invalidado_en'));
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($otras, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($cookies, $usuario)->assertUnauthorized();
        $usuario->correo_acceso = 'nuevo@example.test';
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->verificar($cookies, $codigo)->assertUnprocessable();
        $eventos = DB::table('bitacora')->where('tipo_evento', 'like', 'CAMBIO_CORREO%')->get()->toJson();
        foreach ([$codigo, $hash, $usuario->correo_acceso, self::PASSWORD] as $secreto) {
            $this->assertStringNotContainsString($secreto, $eventos);
        }
    }

    public function test_wrong_password_and_unavailable_or_reserved_destination_do_not_replace_pending_request(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies, password: 'incorrecta')->assertUnprocessable()->assertJsonPath('error.code', 'AUTH_CLAVE_ACTUAL_INVALIDA');
        $this->solicitar($cookies, $usuario->correo_acceso)->assertConflict();
        $otro = $this->usuarioAuth('Recepcionista');
        $this->solicitar($cookies, $otro->correo_acceso)->assertConflict();
        DB::table('registros_paciente_pendientes')->insert(['tipo_registro' => 'Nuevo', 'documento_identidad' => 'CI-FICTICIO',
            'correo_propuesto' => 'reservado@example.test', 'password_hash_temporal' => 'ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addDay()]);
        $this->solicitar($cookies, 'reservado@example.test')->assertConflict();
        $this->assertSame(0, DB::table('verificaciones_correo')->count());
        Mail::assertNothingSent();
    }

    public function test_resend_waits_sixty_seconds_replaces_secret_and_is_limited_to_five_emissions_per_hour(): void
    {
        [, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $viejo = $this->codigo();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 4; $i++) {
            $this->travel(60)->seconds();
            $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertStatus(202)->assertJsonPath('data.envio', 'simulado');
        }
        $this->assertNotSame($viejo, $this->codigo());
        $this->travel(60)->seconds();
        $this->solicitar($cookies, 'sustituto@example.test')->assertStatus(429);
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
        $this->assertSame(1, DB::table('verificaciones_correo')->whereNull('invalidado_en')->count());
        $this->verificar($cookies, $viejo)->assertUnprocessable();
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_new_request_changes_destination_only_after_confirmation_and_logout_does_not_cancel(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $viejo = $this->codigo();
        $this->travel(60)->seconds();
        $this->solicitar($cookies, 'otro@example.test')->assertStatus(202);
        $nuevo = $this->codigo();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->verificar($cookies, $viejo)->assertUnprocessable();
        $this->verificar($cookies, $nuevo)->assertNoContent();
        $this->assertSame('otro@example.test', DB::table('usuarios')->value('correo_acceso'));
    }

    public function test_five_errors_exhaust_code_and_expiry_boundary_is_exclusive(): void
    {
        [, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $incorrecto = $codigo === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->verificar($cookies, $incorrecto)->assertUnprocessable();
        }
        $this->travel(60)->seconds();
        $this->verificar($cookies, $codigo)->assertUnprocessable();
        $this->assertSame(5, DB::table('verificaciones_correo')->value('intentos_fallidos'));
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertUnprocessable();
        $this->solicitar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $this->travel(10)->minutes();
        $this->verificar($cookies, $codigo)->assertUnprocessable();
        $this->assertNull(DB::table('verificaciones_correo')->orderByDesc('id_verificacion')->value('consumido_en'));
    }

    public function test_delivery_failure_never_enables_code_and_retry_counts_failed_emission(): void
    {
        [, $cookies] = $this->titular();
        $mock = Mockery::mock(CorreoSeguridadService::class);
        $mock->shouldReceive('enviarCambioCorreo')->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(CorreoSeguridadService::class, $mock);
        $this->solicitar($cookies)->assertStatus(202)->assertJsonPath('data.envio', 'fallido');
        $this->assertNotNull(DB::table('verificaciones_correo')->value('invalidado_en'));
        $this->verificar($cookies, '000000')->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertStatus(429);
        $this->app->forgetInstance(CorreoSeguridadService::class);
        $this->travel(60)->seconds();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertStatus(202)->assertJsonPath('data.envio', 'simulado');
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_destination_taken_after_issuance_preserves_original_account_and_recovery(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $this->usuarioAuth('Recepcionista', ['correo_acceso' => 'nuevo@example.test']);
        $this->verificar($cookies, $this->codigo())->assertConflict();
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->value('correo_acceso'));
        $this->assertNull(DB::table('verificaciones_correo')->value('consumido_en'));
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_password_change_cancels_pending_request_and_prevents_resend(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $this->navegador($cookies, 'PUT', '/api/auth/password', ['current_password' => self::PASSWORD,
            'password' => 'Otra frase segura ficticia 123', 'password_confirmation' => 'Otra frase segura ficticia 123'])->assertNoContent();
        $this->travel(60)->seconds();
        $this->verificar($cookies, $codigo)->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertUnprocessable();
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->value('correo_acceso'));
    }

    public function test_confirmation_audit_failure_rolls_back_email_code_and_session_revocation(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('autenticacion')->withArgs(fn ($tipo, $resultado, ...$resto) => $tipo === 'CAMBIO_CORREO_VERIFICADO' && $resultado === 'EXITO')
            ->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->verificar($cookies, $this->codigo())->assertStatus(500);
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->value('correo_acceso'));
        $this->assertNull(DB::table('verificaciones_correo')->value('consumido_en'));
        $this->assertSame(1, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_recovery_completion_cancels_email_change_but_requesting_recovery_does_not(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $secreto = bin2hex(random_bytes(32));
        $this->app->make(TokenAccesoRepository::class)->emitirRecuperacion($usuario->getKey(),
            hash_hmac('sha256', $secreto, (string) config('app.key')), CarbonImmutable::now('UTC'));
        $this->assertNull(DB::table('verificaciones_correo')->value('invalidado_en'));
        $anonimas = $this->csrfAuth();
        $this->navegador($anonimas, 'POST', '/api/auth/password/reset', ['token' => $secreto,
            'password' => 'Frase recuperada ficticia 123', 'password_confirmation' => 'Frase recuperada ficticia 123'])->assertNoContent();
        $this->assertNotNull(DB::table('verificaciones_correo')->value('invalidado_en'));
        $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->loginAuth($cookies, $usuario, 'Frase recuperada ficticia 123')->assertOk();
        $this->travel(60)->seconds();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertUnprocessable();
        $this->verificar($cookies, $this->codigo())->assertUnprocessable();
    }

    public function test_destination_rate_limit_is_shared_by_different_users_and_does_not_reserve_email_ownership(): void
    {
        [, $uno] = $this->titular();
        [, $dos] = $this->titular();
        $this->solicitar($uno)->assertStatus(202);
        $this->solicitar($dos)->assertStatus(429);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(60)->seconds();
            if ($i % 2 === 0) {
                $this->solicitar($dos)->assertStatus(202);
            } else {
                $this->solicitar($uno)->assertStatus(202);
            }
        }
        $this->travel(60)->seconds();
        $this->solicitar($dos)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
    }

    public function test_security_invalidation_includes_pending_transport_with_identical_clock(): void
    {
        [$usuario] = $this->titular();
        $ahora = CarbonImmutable::now('UTC')->setMicrosecond(999999);
        $repo = $this->app->make(CambioCorreoRepository::class);
        $codigo = $repo->crear($usuario->getKey(), 'nuevo@example.test', 'hash-ficticio', $ahora);
        DB::transaction(fn () => $repo->invalidar($usuario->getKey(), $ahora));
        $this->assertTrue($codigo->refresh()->invalidado_en->greaterThan($ahora));
        $this->assertTrue($codigo->invalidado_en->equalTo($ahora->addMicrosecond()));
    }

    public function test_failed_issuance_audit_does_not_send_or_leave_verification(): void
    {
        [, $cookies] = $this->titular();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('autenticacion')->withArgs(fn ($tipo, ...$resto) => $tipo === 'CAMBIO_CORREO_SOLICITADO')->once()
            ->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->solicitar($cookies)->assertStatus(500);
        $this->assertSame(0, DB::table('verificaciones_correo')->count());
        Mail::assertNothingSent();
    }

    public function test_origin_and_user_request_limits_apply_before_brute_forcing_current_password(): void
    {
        [, $cookies] = $this->titular();
        config(['seguridad.cambio_correo.solicitudes_por_usuario' => 2]);
        $this->solicitar($cookies, password: 'incorrecta')->assertUnprocessable();
        $this->solicitar($cookies, password: 'incorrecta')->assertUnprocessable();
        $this->solicitar($cookies)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, DB::table('verificaciones_correo')->count());
    }

    public function test_expiration_at_exact_boundary_and_code_from_activation_are_not_accepted(): void
    {
        [, $cookies] = $this->titular();
        $registro = DB::table('registros_paciente_pendientes')->insertGetId(['tipo_registro' => 'Nuevo', 'documento_identidad' => 'CI-FICTICIO',
            'correo_propuesto' => 'registro@example.test', 'password_hash_temporal' => 'ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addDay()], 'id_registro_pendiente');
        DB::table('verificaciones_correo')->insert(['id_registro_pendiente' => $registro, 'proposito' => 'ActivacionPaciente',
            'correo_destino' => 'registro@example.test', 'codigo_hash' => 'otro-proposito',
            'enviado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addMinutes(10)]);
        $this->verificar($cookies, '123456')->assertUnprocessable();
        $this->assertSame(0, DB::table('verificaciones_correo')->value('intentos_fallidos'));
        $this->solicitar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $fin = DB::table('verificaciones_correo')->where('proposito', 'CambioCorreo')->value('expira_en');
        $this->travelTo(CarbonImmutable::parse($fin));
        $this->verificar($cookies, $codigo)->assertUnprocessable();
        $this->assertSame(0, DB::table('verificaciones_correo')->whereNotNull('consumido_en')->count());
    }

    public function test_external_unique_collision_rolls_back_without_changing_fiche_or_consuming_code(): void
    {
        [$usuario, $cookies] = $this->titular();
        $this->solicitar($cookies)->assertStatus(202);
        $real = new UsuarioRepository;
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('guardar')->once()->andReturnUsing(function (Usuario $actual) use ($real): void {
            // Escritor ajeno al protocolo aparece después de comprobar disponibilidad.
            $this->usuarioAuth('Recepcionista', ['correo_acceso' => 'nuevo@example.test']);
            $real->guardar($actual);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->verificar($cookies, $this->codigo())->assertConflict()->assertJsonPath('error.code', 'CAMBIO_CORREO_NO_DISPONIBLE');
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->value('correo_acceso'));
        $this->assertNull(DB::table('verificaciones_correo')->where('proposito', 'CambioCorreo')->value('consumido_en'));
        $this->assertSame(1, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_role_change_invalidates_account_scoped_verification_without_enabling_personal_email_endpoint(): void
    {
        $personal = $this->usuarioAuth('Recepcionista');
        $repo = $this->app->make(CambioCorreoRepository::class);
        $codigo = $repo->crear($personal->getKey(), 'propuesto@example.test', 'hash-ficticio', CarbonImmutable::now('UTC'));
        $codigo->invalidado_en = null;
        $repo->guardar($codigo);
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$personal->getKey().'/rol', ['rol' => 'Administrador'])->assertOk();
        $this->assertNotNull($codigo->refresh()->invalidado_en);
        $this->assertSame($personal->correo_acceso, DB::table('usuarios')->where('id_usuario', $personal->getKey())->value('correo_acceso'));
    }

    public static function otrosRoles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    #[DataProvider('otrosRoles')]
    public function test_other_roles_cannot_use_any_email_change_endpoint(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->solicitar($cookies)->assertForbidden();
        $this->verificar($cookies, '000000')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar')->assertForbidden();
    }

    public function test_requires_csrf_session_and_strict_fields_and_code_purpose(): void
    {
        [, $cookies] = $this->titular();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'nuevo@example.test', 'current_password' => self::PASSWORD], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'nuevo@example.test', 'current_password' => self::PASSWORD, 'id_usuario' => 2])->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/verificar', ['codigo' => 123456])->assertUnprocessable();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/reenviar', ['correo_acceso' => 'otro@example.test'])->assertUnprocessable();
        $anonimas = $this->csrfAuth();
        $this->solicitar($anonimas)->assertUnauthorized();
        $this->verificar($cookies, '000000')->assertUnprocessable();
        Mail::assertNothingSent();
    }
}
