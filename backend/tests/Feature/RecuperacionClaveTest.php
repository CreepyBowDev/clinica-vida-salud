<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\CorreoSeguridadService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\utils\RecuperacionClaveMail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class RecuperacionClaveTest extends TestCase
{
    use AutenticacionFixtures;

    private const NUEVA = '  Nueva frase de recuperacion 456  ';

    private const RESPUESTA = ['data' => ['message' => 'Si corresponde, recibirás un enlace para recuperar tu contraseña.']];

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
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000006_create_usuarios',
            '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes',
            '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes', '000015_create_verificaciones_correo', '000016_create_medicos'] as $name) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php'))->up();
        }
        (require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php'))->up();
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function solicitar(array &$cookies, string $correo, array $headers = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $correo], $headers, $csrf);
    }

    private function token(?string $correo = null): string
    {
        $mail = Mail::sent(RecuperacionClaveMail::class, fn ($mail) => $correo === null || $mail->hasTo($correo))->last();
        $this->assertNotNull($mail);
        parse_str(parse_url($mail->enlace, PHP_URL_FRAGMENT), $fragmento);

        return $fragmento['token'];
    }

    private function restablecer(array &$cookies, string $token, array $extra = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/password/reset', [...[
            'token' => $token, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ], ...$extra], [], $csrf);
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_each_role_recovers_with_single_use_link_preserving_identity_block_and_inactive_records_and_revoking_all_devices(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol);
        $primero = $this->csrfAuth();
        $segundo = $this->csrfAuth();
        $this->loginAuth($primero, $usuario)->assertOk();
        $this->loginAuth($segundo, $usuario)->assertOk();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['intentos_fallidos' => 3, 'bloqueado_hasta' => now('UTC')->addMinutes(10)]);
        $antes = $usuario->fresh()->getAttributes();
        $registro = $usuario->medico?->getAttributes() ?? $usuario->paciente?->getAttributes();
        $publico = $this->csrfAuth();
        $this->solicitar($publico, ' '.strtoupper($usuario->correo_acceso).' ')->assertStatus(202)->assertExactJson(self::RESPUESTA)->assertHeader('Cache-Control', 'no-store, private');
        $token = $this->token();
        $row = DB::table('tokens_acceso')->sole();
        $this->assertSame('RecuperacionClave', $row->proposito);
        $this->assertSame(hash_hmac('sha256', $token, config('app.key')), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(3600.0, CarbonImmutable::parse($row->emitido_en)->diffInSeconds(CarbonImmutable::parse($row->expira_en)));
        $this->assertSame($antes['password_hash'], $usuario->fresh()->password_hash);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->restablecer($publico, $token, ['id_usuario' => '999999', 'rol' => 'Paciente', 'estado_cuenta' => 'Desactivada', 'cambio_clave_obligatorio' => true])
            ->assertNoContent()->assertContent('');
        $despues = $usuario->fresh();
        $this->assertTrue(Hash::check(self::NUEVA, $despues->password_hash));
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['password_hash', 'actualizado_en'], true)) {
                $this->assertSame($valor, $despues->getAttributes()[$campo], $campo);
            }
        }
        $this->assertSame($registro, $despues->medico?->getAttributes() ?? $despues->paciente?->getAttributes());
        $this->assertNotNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        foreach ([&$primero, &$segundo, &$publico] as &$cookies) {
            $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        }
        unset($cookies);
        $this->loginAuth($publico, $usuario, self::NUEVA)->assertUnauthorized();
        $this->restablecer($publico, $token)->assertStatus(422)->assertJsonPath('error.code', 'RECUPERACION_INVALIDA');
        $this->travel(11)->minutes();
        $this->loginAuth($publico, $usuario, self::NUEVA)->assertOk()->assertJsonPath('data.rol', $rol);
        $eventos = DB::table('bitacora')->where('tipo_evento', 'like', 'RECUPERACION_%')->get();
        foreach ([$token, $row->token_hash, self::NUEVA, $despues->password_hash, $usuario->correo_acceso] as $secreto) {
            $this->assertStringNotContainsString($secreto, $eventos->toJson());
        }
        $emitido = $eventos->firstWhere('tipo_evento', 'RECUPERACION_EMITIDA');
        $this->assertSame('ANONIMO', $emitido->tipo_actor);
        $this->assertNull($emitido->id_usuario_responsable);
    }

    public static function noElegibles(): array
    {
        return [['inexistente'], ['pendiente'], ['desactivada'], ['sin_verificacion'], ['sin_vinculo'], ['cambio_obligatorio']];
    }

    #[DataProvider('noElegibles')]
    public function test_ineligible_requests_have_same_public_response_and_do_not_issue_or_reactivate(string $caso): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        match ($caso) {
            'pendiente' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null, 'password_hash' => null]),
            'desactivada' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']),
            'sin_verificacion' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null]),
            'sin_vinculo' => DB::table('pacientes')->where('id_usuario', $usuario->getKey())->update(['id_usuario' => null]),
            'cambio_obligatorio' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['cambio_clave_obligatorio' => true]),
            default => null,
        };
        $antes = $usuario->fresh()->getAttributes();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $caso === 'inexistente' ? 'ausente@example.test' : $usuario->correo_acceso)->assertStatus(202)->assertExactJson(self::RESPUESTA);
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $this->assertSame($antes, $usuario->fresh()->getAttributes());
        Mail::assertNothingSent();
    }

    public function test_account_lookup_token_and_smtp_are_deferred_until_after_http_response_and_mail_waits_for_commit(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $request = Request::create('http://localhost:8000/api/auth/password/forgot', 'POST', [], $cookies, [], [
            'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '192.0.2.80', 'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
        ], json_encode(['correo_acceso' => $usuario->correo_acceso]));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertStringNotContainsString('"usuarios"', implode("\n", $queries));
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        Mail::assertNothingSent();
        $kernel->terminate($request, $response);
        Mail::assertSent(RecuperacionClaveMail::class, 1);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, DB::table('tokens_acceso')->count());
    }

    public function test_reissuance_invalidates_previous_link_and_reset_revokes_only_recovery_purpose(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $anterior = $this->token();
        $this->travel(1)->minutes();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $nuevo = $this->token();
        $this->assertNotSame($anterior, $nuevo);
        foreach (['InvitacionPersonal', 'CredencialTemporal'] as $proposito) {
            DB::table('tokens_acceso')->insert(['id_usuario' => $usuario->getKey(), 'proposito' => $proposito,
                'token_hash' => Str::random(64), 'emitido_en' => now('UTC'), 'expira_en' => now('UTC')->addDay()]);
        }
        $this->restablecer($cookies, $anterior)->assertStatus(422);
        $this->restablecer($cookies, $nuevo)->assertNoContent();
        $this->assertSame(2, DB::table('tokens_acceso')->where('proposito', '<>', 'RecuperacionClave')->whereNull('invalidado_en')->whereNull('consumido_en')->count());
    }

    public function test_real_http_client_finishes_response_while_simulated_smtp_is_still_waiting(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $schema = explode(',', DB::selectOne('SHOW search_path')->search_path)[0];
        $barrera = getenv('LOCALAPPDATA').'/Temp/opencode/recuperacion-http-'.Str::random(16);
        mkdir($barrera);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $puerto = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$puerto, base_path('tests/Fixtures/recuperacion-http.php')], base_path(), [
            'RECUPERACION_TEST_SCHEMA' => trim($schema, '" '), 'RECUPERACION_TEST_BARRERA' => $barrera,
        ]);
        $curl = curl_init('http://127.0.0.1:'.$puerto.'/api/auth/password/forgot');
        $multi = curl_multi_init();
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(fn ($type, $output) => str_contains($output, 'Development Server')));
            $cabeceras = [];
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_POSTFIELDS => json_encode(['correo_acceso' => $usuario->correo_acceso]),
                CURLOPT_COOKIE => implode('; ', array_map(fn ($nombre, $valor) => $nombre.'='.$valor, array_keys($cookies), $cookies)),
                CURLOPT_HTTPHEADER => ['Host: localhost:8000', 'Origin: http://localhost:5173', 'Accept: application/json',
                    'Content-Type: application/json', 'X-XSRF-TOKEN: '.$cookies['XSRF-TOKEN']],
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$cabeceras): int {
                    $cabeceras[] = $line;

                    return strlen($line);
                },
            ]);
            curl_multi_add_handle($multi, $curl);
            $limite = microtime(true) + 5;
            do {
                curl_multi_exec($multi, $pendiente);
                if (! $pendiente && is_file($barrera.'/envio')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $limite);
            $this->assertFileExists($barrera.'/envio');
            $this->assertSame(0, $pendiente, 'El cliente debe completar el cuerpo HTTP antes de liberar el transporte simulado.');
            $this->assertSame(202, curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
            $this->assertSame(self::RESPUESTA, json_decode(curl_multi_getcontent($curl), true));
            $this->assertStringContainsStringIgnoringCase('Content-Length:', implode('', $cabeceras));
            $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->count());
            file_put_contents($barrera.'/continuar', 'liberar');
            $limite = microtime(true) + 5;
            do {
                $envios = DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->count();
                if ($envios) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $limite);
            $this->assertSame(1, $envios);
        } finally {
            file_put_contents($barrera.'/continuar', 'liberar');
            $server->stop();
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
            foreach (glob($barrera.'/*') as $archivo) {
                unlink($archivo);
            }
            rmdir($barrera);
        }
    }

    public static function invalidos(): array
    {
        return [['inexistente'], ['vencido'], ['futuro'], ['invalidado'], ['consumido'], ['proposito'], ['desactivada'], ['sin_vinculo'], ['cambio_obligatorio']];
    }

    #[DataProvider('invalidos')]
    public function test_reset_revalidates_every_condition_and_invalid_tokens_never_change_password_or_session(string $caso): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        match ($caso) {
            'inexistente' => $token = str_repeat('a', 64),
            'vencido' => $this->travel(60)->minutes(),
            'futuro' => $this->travel(-1)->minutes(),
            'invalidado' => DB::table('tokens_acceso')->update(['invalidado_en' => now('UTC')]),
            'consumido' => DB::table('tokens_acceso')->update(['consumido_en' => now('UTC')]),
            'proposito' => DB::table('tokens_acceso')->update(['proposito' => 'InvitacionPersonal']),
            'desactivada' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']),
            'sin_vinculo' => DB::table('pacientes')->where('id_usuario', $usuario->getKey())->update(['id_usuario' => null]),
            'cambio_obligatorio' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['cambio_clave_obligatorio' => true]),
        };
        $antes = $usuario->fresh()->getAttributes();
        $this->restablecer($cookies, $token)->assertStatus(422)->assertExactJson(['success' => false, 'error' => [
            'code' => 'RECUPERACION_INVALIDA', 'message' => 'El enlace no es válido o ya no está disponible. Solicita uno nuevo.',
        ]]);
        $this->assertSame($antes, $usuario->fresh()->getAttributes());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public static function clavesInvalidas(): array
    {
        return [['corta', 'corta'], [str_repeat('a', 73), str_repeat('a', 73)], ["Clave\0ficticia 123", "Clave\0ficticia 123"], [self::NUEVA, 'otra confirmacion']];
    }

    #[DataProvider('clavesInvalidas')]
    public function test_invalid_password_is_rejected_without_consuming_a_valid_link(string $password, string $confirmacion): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $this->restablecer($cookies, $token, ['password' => $password, 'password_confirmation' => $confirmacion])->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION_ERROR');
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->restablecer($cookies, $token)->assertNoContent();
    }

    public function test_request_and_reset_require_csrf_origin_and_valid_input(): void
    {
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, 'valido@example.test', csrf: false)->assertStatus(419);
        $this->solicitar($cookies, 'valido@example.test', ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->solicitar($cookies, 'invalido')->assertStatus(422);
        $this->restablecer($cookies, str_repeat('a', 64), csrf: false)->assertStatus(419);
        $this->restablecer($cookies, 'invalido')->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_email_hour_limit_is_shared_across_origins_and_does_not_depend_on_existence_or_extend_window(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        $cookies = $this->csrfAuth();
        for ($i = 0; $i < 5; $i++) {
            $this->solicitar($cookies, 'ausente@example.test', ['X-Test-IP' => '192.0.2.'.($i + 20)])->assertStatus(202);
            $this->travel(1)->minutes();
        }
        $this->solicitar($cookies, ' AUSENTE@EXAMPLE.TEST ', ['X-Test-IP' => '192.0.2.90'])->assertStatus(429)->assertHeader('Retry-After', '3300');
        $this->travel(1)->minutes();
        $this->solicitar($cookies, 'ausente@example.test', ['X-Test-IP' => '192.0.2.91'])->assertStatus(429)->assertHeader('Retry-After', '3240');
        $this->travel(54)->minutes();
        $this->solicitar($cookies, 'ausente@example.test')->assertStatus(202);
        $controles = DB::table('controles_intentos')->get()->toJson();
        $this->assertStringNotContainsString('ausente@example.test', $controles);
        Mail::assertNothingSent();
    }

    public function test_ip_and_token_limits_are_persistent_and_independent_of_login_block(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        for ($i = 0; $i < 20; $i++) {
            $this->solicitar($cookies, 'ausente'.$i.'@example.test')->assertStatus(202);
        }
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(429);
        for ($i = 0; $i < 5; $i++) {
            $this->restablecer($cookies, str_repeat('f', 64))->assertStatus(422);
        }
        $this->restablecer($cookies, str_repeat('f', 64))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, $usuario->fresh()->intentos_fallidos);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
    }

    public function test_mail_failure_is_not_exposed_preserves_hash_and_allows_a_new_request(): void
    {
        $usuario = $this->usuarioAuth();
        $antes = $usuario->password_hash;
        $fake = Mockery::mock(CorreoSeguridadService::class);
        $fake->shouldReceive('enviarRecuperacion')->once()->andReturnUsing(function (): never {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Fallo de correo ficticio');
        });
        $this->app->instance(CorreoSeguridadService::class, $fake);
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202)->assertExactJson(self::RESPUESTA);
        $this->assertSame($antes, $usuario->fresh()->password_hash);
        $this->assertSame('FALLO', DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->sole()->resultado);
        $this->app->forgetInstance(CorreoSeguridadService::class);
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        Mail::assertSent(RecuperacionClaveMail::class, 1);
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('invalidado_en')->count());
    }

    public function test_issuance_audit_failure_rolls_back_token_invalidation_and_does_not_send(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202)->assertExactJson(self::RESPUESTA);
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->assertNull(DB::table('tokens_acceso')->sole()->invalidado_en);
        Mail::assertSent(RecuperacionClaveMail::class, 1);
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->restablecer($cookies, $token)->assertNoContent();
    }

    public function test_reset_audit_failure_rolls_back_hash_consumption_and_sessions_and_allows_retry(): void
    {
        $usuario = $this->usuarioAuth();
        $sesion = $this->csrfAuth();
        $this->loginAuth($sesion, $usuario)->assertOk();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $antes = $usuario->password_hash;
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->restablecer($cookies, $token)->assertStatus(500);
        $this->assertSame($antes, $usuario->fresh()->password_hash);
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->restablecer($cookies, $token)->assertNoContent();
    }

    public function test_normal_password_change_invalidates_previously_issued_recovery_links(): void
    {
        $usuario = $this->usuarioAuth();
        $sesion = $this->csrfAuth();
        $this->loginAuth($sesion, $usuario)->assertOk();
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $this->navegador($sesion, 'PUT', '/api/auth/password', ['current_password' => self::PASSWORD, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertNoContent();
        $this->restablecer($cookies, $token)->assertStatus(422);
        $this->navegador($sesion, 'GET', '/api/auth/me')->assertOk();
    }

    public function test_deactivation_reactivation_and_role_change_invalidate_old_links_without_altering_invitation_purpose(): void
    {
        $admin = $this->usuarioAuth();
        $sesion = $this->csrfAuth();
        $this->loginAuth($sesion, $admin)->assertOk();
        $usuario = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $this->navegador($sesion, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($sesion, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->restablecer($cookies, $token)->assertStatus(422);
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $token = $this->token();
        $this->navegador($sesion, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Administrador'])->assertOk();
        $this->restablecer($cookies, $token)->assertStatus(422);
    }

    public function test_authenticated_owner_is_logged_out_and_other_authenticated_account_is_not_replaced(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $this->restablecer($cookies, $this->token())->assertNoContent();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $otro = $this->usuarioAuth('Paciente');
        $this->loginAuth($cookies, $otro)->assertOk();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202);
        $this->restablecer($cookies, $this->token())->assertNoContent();
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id_usuario', (string) $otro->getKey());
    }

    public function test_template_uses_configured_frontend_fragment_and_brevo_mailer_without_network(): void
    {
        $usuario = $this->usuarioAuth();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => 'clave-ficticia-de-pruebas']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<recuperacion-ficticia>'], 201)]);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso, 'url' => 'https://ajeno.example.test'])
            ->assertStatus(202);
        Http::assertSentCount(1);
        $html = Http::recorded()->sole()[0]['htmlContent'];
        $this->assertStringContainsString('http://localhost:5173/recuperar-clave#token=', $html);
        $this->assertStringNotContainsString('https://ajeno.example.test', $html);
        $this->assertStringContainsString('60 minutos', $html);
        $this->assertStringNotContainsString(self::NUEVA, $html);
    }

    public function test_brevo_http_failure_keeps_response_generic_password_unchanged_and_reissuance_available(): void
    {
        $usuario = $this->usuarioAuth();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => 'clave-ficticia-de-pruebas']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        Http::fakeSequence()->push(['code' => 'invalid_parameter', 'message' => 'Remitente ficticio rechazado'], 400)
            ->push(['messageId' => '<recuperacion-reemitida>'], 201);
        $cookies = $this->csrfAuth();
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202)->assertExactJson(self::RESPUESTA);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame('FALLO', DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->sole()->resultado);
        $this->solicitar($cookies, $usuario->correo_acceso)->assertStatus(202)->assertExactJson(self::RESPUESTA);
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('invalidado_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->where('resultado', 'EXITO')->count());
    }
}
