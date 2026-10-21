<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\CorreoSeguridadService;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\utils\RegistroPacienteContexto;
use Clinica\shared\utils\VerificacionPacienteMail;
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
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class RegistroPacienteTest extends TestCase
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
        DB::table('roles')->insert(['nombre' => 'Paciente']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function datos(array $extra = []): array
    {
        return [...['documento_identidad' => '  000123-A lp  ', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'sexo' => null,
            'correo_acceso' => ' NUEVO@EXAMPLE.TEST ', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], ...$extra];
    }

    private function iniciar(array &$cookies, array $extra = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/registro', $this->datos($extra), [], $csrf);
    }

    private function codigo(): string
    {
        $mensaje = Mail::sent(VerificacionPacienteMail::class)->last();
        $this->assertNotNull($mensaje);

        return $mensaje->codigo;
    }

    private function verificar(array &$cookies, string $codigo, array $extra = [], bool $csrf = true): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/registro/verificar', ['codigo' => $codigo, ...$extra], [], $csrf);
    }

    private function reenviar(array &$cookies): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar');
    }

    public function test_new_patient_is_only_created_after_verification_with_single_account_and_new_login(): void
    {
        $cookies = $this->csrfAuth();
        $inicio = $this->iniciar($cookies)->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $cookie = collect($inicio->headers->getCookies())->first(fn ($c) => $c->getName() === RegistroPacienteContexto::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertFalse(RegistroPacienteContexto::valido($cookie->getValue()));
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $cookie->getExpiresTime(), 2);
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('pacientes')->count());
        $registro = DB::table('registros_paciente_pendientes')->sole();
        $this->assertSame('Nuevo', $registro->tipo_registro);
        $this->assertSame('000123-A lp', $registro->documento_identidad);
        $this->assertSame('nuevo@example.test', $registro->correo_propuesto);
        $this->assertTrue(Hash::check(self::PASSWORD, $registro->password_hash_temporal));
        $this->assertSame(86400.0, CarbonImmutable::parse($registro->iniciado_en)->diffInSeconds(CarbonImmutable::parse($registro->expira_en)));
        $codigo = $this->codigo();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $codigo);
        $verificacion = DB::table('verificaciones_correo')->sole();
        $this->assertSame(RegistroPacienteContexto::codigo($registro->id_registro_pendiente, $codigo), $verificacion->codigo_hash);
        $this->assertSame(600.0, CarbonImmutable::parse($verificacion->enviado_en)->diffInSeconds(CarbonImmutable::parse($verificacion->expira_en)));
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->verificar($cookies, $codigo, ['rol' => 'Administrador', 'id_usuario' => 999, 'activo' => false])->assertNoContent()->assertContent('');
        $usuario = DB::table('usuarios')->sole();
        $paciente = DB::table('pacientes')->sole();
        $this->assertSame('Paciente', DB::table('roles')->where('id_rol', $usuario->id_rol)->value('nombre'));
        $this->assertSame('Activa', $usuario->estado_cuenta);
        $this->assertNotNull($usuario->correo_verificado_en);
        $this->assertFalse($usuario->cambio_clave_obligatorio);
        $this->assertSame($registro->password_hash_temporal, $usuario->password_hash);
        $this->assertSame($usuario->id_usuario, $paciente->id_usuario);
        $this->assertSame('000123-A lp', $paciente->documento_identidad);
        $this->assertNull($paciente->correo_contacto);
        $this->assertTrue($paciente->activo);
        $this->assertStringStartsWith('PAC-', $paciente->codigo_paciente);
        $cerrado = DB::table('registros_paciente_pendientes')->sole();
        $this->assertNotNull($cerrado->completado_en);
        $this->assertNull($cerrado->password_hash_temporal);
        $this->assertNull($cerrado->datos_registro);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->verificar($cookies, $codigo)->assertStatus(422);
        $this->loginAuth($cookies, Usuario::query()->sole())->assertOk()->assertJsonPath('data.rol', 'Paciente');
        $bitacora = DB::table('bitacora')->get()->toJson();
        foreach ([$codigo, self::PASSWORD, $usuario->password_hash, $verificacion->codigo_hash, 'nuevo@example.test', '000123-A lp'] as $privado) {
            $this->assertStringNotContainsString($privado, $bitacora);
        }
    }

    public static function invalidos(): array
    {
        return [
            [['documento_identidad' => null]], [['documento_identidad' => '   ']], [['documento_identidad' => "\t123"]],
            [['documento_identidad' => str_repeat('A', 51)]], [['correo_acceso' => 'sin-correo']],
            [['password' => 'corta']], [['password_confirmation' => 'distinta']], [['fecha_nacimiento' => '2999-01-01']],
            [['fecha_nacimiento' => '2026-02-30']], [['nombres' => '']], [['telefono' => null]],
            [['rol' => 'Administrador']], [['id_usuario' => 1]], [['datos_registro' => ['_contexto_hash' => 'falso']]],
            [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)]],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_server_validates_registration_and_rejects_privileged_fields_without_creating_pending_data(array $extra): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $extra)->assertStatus(422);
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
        Mail::assertNothingSent();
    }

    public function test_existing_email_or_document_have_the_same_public_response_and_do_not_duplicate_or_link(): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $paciente = $usuario->paciente;
        DB::table('pacientes')->where('id_paciente', $paciente->getKey())->update(['documento_identidad' => '000123-A lp']);
        $cookies = $this->csrfAuth();
        $previo = $this->iniciar($cookies)->assertStatus(202)->json();
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
        $this->iniciar($cookies, ['documento_identidad' => 'DIFERENTE', 'correo_acceso' => $usuario->correo_acceso])
            ->assertStatus(202)->assertExactJson($previo);
        Mail::assertNothingSent();
        $nuevo = $this->csrfAuth();
        $this->iniciar($nuevo, ['documento_identidad' => 'CI-NUEVO', 'correo_acceso' => 'otro@example.test'])->assertStatus(202)->assertExactJson($previo);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertSame($usuario->getKey(), $paciente->fresh()->id_usuario);
    }

    public function test_retries_reuse_protected_process_without_changing_original_data_password_or_deadline(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $antes = DB::table('registros_paciente_pendientes')->sole();
        $this->travel(1)->minutes();
        $this->iniciar($cookies, ['nombres' => 'Intento cambiado', 'password' => 'Otra frase segura nueva 456',
            'password_confirmation' => 'Otra frase segura nueva 456'])->assertStatus(202);
        $this->assertEquals($antes, DB::table('registros_paciente_pendientes')->sole());
        $ajeno = $this->csrfAuth();
        $this->iniciar($ajeno)->assertStatus(202);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->verificar($ajeno, $this->codigo())->assertStatus(422);
        $this->verificar($cookies, $this->codigo())->assertNoContent();
        $this->assertSame('Paciente', DB::table('pacientes')->sole()->nombres);
    }

    public function test_code_expires_at_ten_minutes_and_resend_invalidates_previous_without_extending_registration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $primero = $this->codigo();
        $fin = DB::table('registros_paciente_pendientes')->sole()->expira_en;
        $this->travel(10)->minutes();
        $this->verificar($cookies, $primero)->assertStatus(422);
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(2, DB::table('verificaciones_correo')->count());
        $this->assertSame($fin, DB::table('registros_paciente_pendientes')->sole()->expira_en);
        $this->assertNotNull(DB::table('verificaciones_correo')->orderBy('id_verificacion')->first()->invalidado_en);
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_five_wrong_codes_exhaust_only_that_code_and_allow_controlled_reissue(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $incorrecto = $codigo === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->verificar($cookies, $incorrecto)->assertStatus(422);
        }
        $this->assertSame(5, DB::table('verificaciones_correo')->sole()->intentos_fallidos);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->invalidado_en);
        $this->verificar($cookies, $codigo)->assertStatus(429);
        $this->travel(1)->minutes();
        $this->verificar($cookies, $codigo)->assertStatus(422);
        $this->reenviar($cookies)->assertStatus(202);
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_resend_wait_hourly_cap_and_context_surviving_login_session_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            $this->reenviar($cookies)->assertStatus(202);
        }
        $this->travel(1)->minutes();
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
        $this->travel(3)->hours();
        $cookies = array_replace($cookies, $this->csrfAuth());
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(6, DB::table('verificaciones_correo')->count());
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_pending_expiry_clears_secrets_and_allows_fresh_start_without_definitive_duplicates(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $this->travel(24)->hours();
        $cookies = array_replace($cookies, $this->csrfAuth());
        $this->verificar($cookies, $codigo)->assertStatus(422);
        $cerrado = DB::table('registros_paciente_pendientes')->sole();
        $this->assertNotNull($cerrado->invalidado_en);
        $this->assertNull($cerrado->datos_registro);
        $this->assertNull($cerrado->password_hash_temporal);
        $this->iniciar($cookies)->assertStatus(202);
        $this->assertSame(2, DB::table('registros_paciente_pendientes')->count());
        $this->verificar($cookies, $this->codigo())->assertNoContent();
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertSame(1, DB::table('pacientes')->count());
    }

    public function test_context_tampering_csrf_origin_and_authenticated_requests_cannot_activate(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'POST', '/api/auth/registro', $this->datos(), ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->iniciar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $falso = $cookies;
        $falso[RegistroPacienteContexto::COOKIE] = str_repeat('a', 64);
        $this->verificar($falso, $codigo)->assertStatus(422);
        $this->verificar($cookies, $codigo, csrf: false)->assertStatus(419);
        $admin = $this->usuarioAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->verificar($cookies, $codigo)->assertConflict();
        $this->iniciar($cookies)->assertConflict();
        $this->reenviar($cookies)->assertConflict();
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('pacientes')->count());
    }

    public function test_conflicting_patient_or_email_created_after_start_cannot_be_overwritten_or_linked(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $usuario = $this->usuarioAuth('Recepcionista', ['correo_acceso' => 'nuevo@example.test']);
        $this->verificar($cookies, $this->codigo())->assertStatus(422);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame(0, DB::table('pacientes')->count());
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertNotNull(DB::table('registros_paciente_pendientes')->sole()->invalidado_en);
    }

    public function test_failed_mail_does_not_announce_delivery_or_rollback_pending_and_can_be_reissued(): void
    {
        $correo = Mockery::mock(CorreoSeguridadService::class);
        $correo->shouldReceive('enviarActivacionPaciente')->once()->andThrow(new RuntimeException('Error ficticio'));
        $this->app->instance(CorreoSeguridadService::class, $correo);
        $cookies = $this->csrfAuth();
        $respuesta = $this->iniciar($cookies)->assertStatus(202)->json();
        $this->assertStringContainsString('no confirma el envío', $respuesta['data']['message']);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'REGISTRO_CODIGO_ENVIO')->where('resultado', 'FALLO')->count());
        $this->app->forgetInstance(CorreoSeguridadService::class);
        $this->travel(1)->minutes();
        $this->reenviar($cookies)->assertStatus(202)->assertExactJson($respuesta);
        $this->verificar($cookies, $this->codigo())->assertNoContent();
    }

    public function test_audit_failure_rolls_back_activation_and_preserves_a_recoverable_pending_process(): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $codigo = $this->codigo();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Error ficticio'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->verificar($cookies, $codigo)->assertStatus(500);
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('pacientes')->count());
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->completado_en);
        $this->assertNotNull(DB::table('registros_paciente_pendientes')->sole()->password_hash_temporal);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->verificar($cookies, $codigo)->assertNoContent();
    }

    public function test_no_reservation_or_mail_runs_until_after_the_generic_http_response(): void
    {
        $cookies = $this->csrfAuth();
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $request = Request::create('http://localhost:8000/api/auth/registro', 'POST', [], $cookies, [], [
            'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'], 'REMOTE_ADDR' => '192.0.2.20',
        ], json_encode($this->datos()));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
        Mail::assertNothingSent();
        $kernel->terminate($request, $response);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        Mail::assertSent(VerificacionPacienteMail::class);
    }

    public function test_brevo_http_receives_only_security_code_and_rejection_is_recoverable(): void
    {
        Mail::swap(Mail::getFacadeRoot()->manager);
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => 'clave-ficticia-test',
            'mail.from.address' => 'seguridad@example.test', 'mail.from.name' => 'Clínica Vida Salud']);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'rechazo ficticio'], 503)]);
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'REGISTRO_CODIGO_ENVIO')->where('resultado', 'FALLO')->count());
        Http::assertSent(fn ($request) => str_contains($request['htmlContent'], 'Verifica tu correo')
            && ! str_contains($request['htmlContent'], '000123-A lp') && ! str_contains($request['htmlContent'], self::PASSWORD));
    }

    public function test_wait_is_exactly_sixty_seconds_and_latest_code_cannot_outlive_the_principal_deadline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $primero = $this->codigo();
        $this->travel(59)->seconds();
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        $this->travel(1)->seconds();
        $this->reenviar($cookies)->assertStatus(202);
        $this->assertSame(2, DB::table('verificaciones_correo')->count());
        $this->assertNotSame($primero, $this->codigo());
        $this->verificar($cookies, $primero)->assertStatus(422);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T11:59:00Z'));
        $cookies = array_replace($cookies, $this->csrfAuth());
        $this->reenviar($cookies)->assertStatus(202);
        $ultimo = DB::table('verificaciones_correo')->orderByDesc('id_verificacion')->first();
        $this->assertSame(DB::table('registros_paciente_pendientes')->sole()->expira_en, $ultimo->expira_en);
        $this->assertSame(60.0, CarbonImmutable::parse($ultimo->enviado_en)->diffInSeconds(CarbonImmutable::parse($ultimo->expira_en)));
        $this->travel(1)->minutes();
        $this->verificar($cookies, $this->codigo())->assertStatus(422);
        $this->assertSame(0, DB::table('usuarios')->count());
    }

    public function test_email_hourly_send_cap_survives_expired_process_replacement_and_different_context(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            $this->reenviar($cookies)->assertStatus(202);
        }
        $this->travel(1)->minutes();
        DB::table('registros_paciente_pendientes')->update(['expira_en' => now('UTC')]);
        $nuevo = $this->csrfAuth();
        $this->iniciar($nuevo, ['documento_identidad' => 'OTRO-CI'])->assertStatus(202);
        $this->assertSame(2, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
        $this->travel(55)->minutes();
        $this->reenviar($nuevo)->assertStatus(202);
        $this->assertSame(6, DB::table('verificaciones_correo')->count());
        $this->verificar($nuevo, $this->codigo())->assertNoContent();
        $this->assertSame('OTRO-CI', DB::table('pacientes')->sole()->documento_identidad);
    }

    public function test_pending_creation_audit_failure_rolls_back_and_sends_no_code(): void
    {
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Error ficticio'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies)->assertStatus(202);
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(0, DB::table('verificaciones_correo')->count());
        $this->assertSame(0, DB::table('usuarios')->count());
        Mail::assertNothingSent();
    }
}
