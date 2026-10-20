<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\CorreoSeguridadService;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\shared\utils\InvitacionPersonalMail;
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

class InvitacionPersonalTest extends TestCase
{
    use AutenticacionFixtures;

    private string $schema;

    private string $previousPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_auth_'.strtolower(Str::random(16));
        $this->previousPath = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->previousPath]);
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        });
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000006_create_usuarios', '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes', '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes', '000015_create_verificaciones_correo', '000016_create_medicos'] as $name) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php'))->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
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

    private function datos(string $rol = 'Recepcionista', array $cambios = []): array
    {
        $id = DB::table('roles')->where('nombre', $rol)->value('id_rol');
        if ($id === null) {
            DB::table('roles')->insert(['nombre' => $rol]);
        }
        $datos = ['rol' => $rol, 'correo_acceso' => Str::lower(Str::random(16)).'@example.test', 'nombres_personal' => 'Trabajador', 'apellidos_personal' => 'Ficticio'];
        if ($rol === 'Médico') {
            unset($datos['nombres_personal'], $datos['apellidos_personal']);
            $datos['id_medico'] = DB::table('medicos')->insertGetId([
                'codigo_medico' => Str::random(16), 'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Profesional',
                'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'profesional@example.test', 'activo' => false,
            ], 'id_medico');
        }

        return array_replace($datos, $cambios);
    }

    private function token(?string $correo = null): string
    {
        $mail = Mail::sent(InvitacionPersonalMail::class, fn ($mail) => $correo === null || $mail->hasTo($correo))->last();
        $this->assertNotNull($mail);
        parse_str(parse_url($mail->enlace, PHP_URL_FRAGMENT), $fragmento);

        return $fragmento['token'];
    }

    private function aceptar(array &$cookies, string $token, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/invitaciones/aceptar', array_replace([
            'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $extra));
    }

    public static function rolesPersonal(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    #[DataProvider('rolesPersonal')]
    public function test_admin_creates_pending_staff_and_token_verifies_mail_and_sets_first_password_without_auto_login(string $rol): void
    {
        [$admin, $cookies] = $this->administrador();
        $datos = $this->datos($rol);
        $response = $this->navegador($cookies, 'POST', '/api/personal', [...$datos, 'password' => 'NO GUARDAR', 'estado_cuenta' => 'Activa', 'correo_verificado_en' => now()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.rol', $rol)->assertJsonPath('data.estado_cuenta', 'Pendiente')->assertJsonPath('meta.invitacion.envio', 'simulado');
        $id = $response->json('data.id_usuario');
        $this->assertIsString($id);
        $usuario = Usuario::query()->findOrFail($id);
        $this->assertNull($usuario->password_hash);
        $this->assertNull($usuario->correo_verificado_en);
        $this->assertFalse($usuario->cambio_clave_obligatorio);
        $token = $this->token($datos['correo_acceso']);
        $row = DB::table('tokens_acceso')->sole();
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash_hmac('sha256', $token, config('app.key')), $row->token_hash);
        $this->assertSame(86400.0, CarbonImmutable::parse($row->emitido_en)->diffInSeconds(CarbonImmutable::parse($row->expira_en)));
        $this->assertStringNotContainsString($token, $response->getContent());
        $invitado = $this->csrfAuth();
        $this->loginAuth($invitado, $usuario)->assertUnauthorized();
        $this->aceptar($invitado, $token, ['id_usuario' => $admin->getKey(), 'rol' => 'Paciente'])->assertNoContent()->assertContent('');
        $nuevo = $usuario->fresh();
        $this->assertSame('Activa', $nuevo->estado_cuenta);
        $this->assertNotNull($nuevo->correo_verificado_en);
        $this->assertTrue(Hash::check(self::PASSWORD, $nuevo->password_hash));
        $this->assertSame($rol, $nuevo->rol->nombre);
        $this->assertNotNull(DB::table('tokens_acceso')->where('id_token', $row->id_token)->value('consumido_en'));
        $this->navegador($invitado, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($invitado, $nuevo)->assertOk()->assertJsonPath('data.rol', $rol);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id_usuario', (string) $admin->getKey());
        if ($rol === 'Médico') {
            $this->assertSame((string) $datos['id_medico'], (string) $nuevo->medico->getKey());
            $this->assertFalse($nuevo->medico->activo);
            $this->assertSame('Profesional', $nuevo->medico->nombres);
        }
        $eventos = DB::table('bitacora')->where('id_usuario_objetivo', $id)->get();
        $this->assertCount(4, $eventos);
        foreach ([$token, $row->token_hash, self::PASSWORD, $nuevo->password_hash, $datos['correo_acceso']] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($eventos));
        }
        $this->navegador($invitado, 'POST', '/api/auth/logout')->assertNoContent();
        $this->aceptar($invitado, $token)->assertStatus(422)->assertJsonPath('error.code', 'INVITACION_INVALIDA');
    }

    public function test_resend_uses_same_pending_account_invalidates_previous_link_and_rate_window_does_not_extend(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        [, $cookies] = $this->administrador();
        $response = $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated();
        $id = $response->json('data.id_usuario');
        $primero = $this->token();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(20)->seconds();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertStatus(429)->assertHeader('Retry-After', '40');
        $this->travel(40)->seconds();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertOk()->assertJsonPath('data.id_usuario', $id);
        $segundo = $this->token();
        $this->assertNotSame($primero, $segundo);
        $this->assertSame(2, DB::table('usuarios')->count());
        $this->assertSame(2, DB::table('tokens_acceso')->count());
        $publico = $this->csrfAuth();
        $this->aceptar($publico, $primero)->assertStatus(422);
        $this->aceptar($publico, $segundo)->assertNoContent();
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertConflict();
    }

    public function test_hourly_resend_limit_counts_original_and_expires_at_boundary(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 0));
        [, $cookies] = $this->administrador();
        $id = $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->json('data.id_usuario');
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertOk();
        }
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertStatus(429)->assertHeader('Retry-After', '3300');
        $this->travel(55)->minutes();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertOk();
        $this->assertSame(6, DB::table('tokens_acceso')->count());
    }

    public static function denegaciones(): array
    {
        return [['inexistente'], ['vencida'], ['futura'], ['invalidada'], ['consumida'], ['desactivada'], ['sin_vinculo']];
    }

    #[DataProvider('denegaciones')]
    public function test_invalid_invitation_does_not_activate_or_disclose_account_data(string $caso): void
    {
        [, $cookies] = $this->administrador();
        $datos = $this->datos('Médico');
        $id = $this->navegador($cookies, 'POST', '/api/personal', $datos)->assertCreated()->json('data.id_usuario');
        $token = $this->token();
        match ($caso) {
            'inexistente' => $token = str_repeat('a', 64),
            'vencida' => $this->travel(24)->hours(),
            'futura' => $this->travel(-1)->hours(),
            'invalidada' => DB::table('tokens_acceso')->update(['invalidado_en' => now()]),
            'consumida' => DB::table('tokens_acceso')->update(['consumido_en' => now()]),
            'desactivada' => DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada', 'password_hash' => Hash::make(self::PASSWORD)]),
            'sin_vinculo' => DB::table('medicos')->where('id_medico', $datos['id_medico'])->update(['id_usuario' => null]),
        };
        $publico = $this->csrfAuth();
        $this->aceptar($publico, $token)->assertStatus(422)->assertExactJson(['success' => false, 'error' => [
            'code' => 'INVITACION_INVALIDA', 'message' => 'La invitación no es válida o ya no está disponible. Solicita asistencia a Administración.',
        ]]);
        $this->assertNotSame('Activa', Usuario::query()->findOrFail($id)->estado_cuenta);
    }

    public static function noAdministradores(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('noAdministradores')]
    public function test_staff_creation_and_resend_are_admin_only(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertUnauthorized();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/personal/1/invitacion')->assertForbidden();
        Mail::assertNothingSent();
    }

    public static function entradasInvalidas(): array
    {
        return [
            [['rol' => 'Paciente']], [['rol' => 'Otro']], [['correo_acceso' => 'incorrecto']], [['nombres_personal' => '']],
            [['apellidos_personal' => []]], [['id_medico' => 1]], [['correo_acceso' => str_repeat('a', 255).'@example.test']],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_invalid_staff_fields_are_rejected_without_an_account_or_email(array $cambios): void
    {
        [, $cookies] = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos(cambios: $cambios))->assertStatus(422);
        $this->assertSame(1, DB::table('usuarios')->count());
        Mail::assertNothingSent();
    }

    public function test_normalized_duplicate_email_and_occupied_or_missing_professional_link_are_conflicts(): void
    {
        [$admin, $cookies] = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos(cambios: ['correo_acceso' => ' '.strtoupper($admin->correo_acceso).' ']))
            ->assertConflict()->assertJsonPath('error.code', 'PERSONAL_CORREO_NO_DISPONIBLE');
        $datos = $this->datos('Médico');
        $this->navegador($cookies, 'POST', '/api/personal', [...$datos, 'id_medico' => 999999])->assertConflict();
        $this->navegador($cookies, 'POST', '/api/personal', $datos)->assertCreated();
        $this->navegador($cookies, 'POST', '/api/personal', [...$datos, 'correo_acceso' => 'otro@example.test'])->assertConflict();
        $this->assertSame(2, DB::table('usuarios')->count());
        Mail::assertSent(InvitacionPersonalMail::class, 1);
    }

    public function test_delivery_runs_only_after_outer_commit_and_rollback_sends_nothing(): void
    {
        [, $cookies] = $this->administrador();
        DB::beginTransaction();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'pendiente');
        Mail::assertNothingSent();
        DB::rollBack();
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('usuarios')->count());
        // La sesión del administrador permanece en DB tras el rollback de la cuenta nueva.
        DB::beginTransaction();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated();
        Mail::assertNothingSent();
        DB::commit();
        Mail::assertSent(InvitacionPersonalMail::class, 1);
    }

    public function test_failed_delivery_preserves_pending_account_and_allows_controlled_resend(): void
    {
        [, $cookies] = $this->administrador();
        $fake = Mockery::mock(CorreoSeguridadService::class);
        $fake->shouldReceive('enviarInvitacion')->once()->andReturnUsing(function (): never {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Fallo de correo ficticio');
        });
        $this->app->instance(CorreoSeguridadService::class, $fake);
        $id = $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'fallido')->json('data.id_usuario');
        $this->assertSame('Pendiente', Usuario::query()->findOrFail($id)->estado_cuenta);
        $this->assertSame('FALLO', DB::table('bitacora')->where('tipo_evento', 'INVITACION_ENVIO')->sole()->resultado);
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->app->forgetInstance(CorreoSeguridadService::class);
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertOk()->assertJsonPath('meta.invitacion.envio', 'simulado');
        $this->assertSame(2, DB::table('usuarios')->count());
        Mail::assertSent(InvitacionPersonalMail::class, 1);
    }

    public function test_database_audit_failure_rolls_back_user_link_token_and_never_sends_email(): void
    {
        [, $cookies] = $this->administrador();
        $datos = $this->datos('Médico');
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->navegador($cookies, 'POST', '/api/personal', $datos)->assertStatus(500);
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertNull(DB::table('medicos')->where('id_medico', $datos['id_medico'])->value('id_usuario'));
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        Mail::assertNothingSent();
    }

    public function test_acceptance_failure_rolls_back_activation_consumption_and_preserves_retry(): void
    {
        [, $cookies] = $this->administrador();
        $id = $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->json('data.id_usuario');
        $token = $this->token();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $publico = $this->csrfAuth();
        $this->aceptar($publico, $token)->assertStatus(500);
        $this->assertSame('Pendiente', Usuario::query()->findOrFail($id)->estado_cuenta);
        $this->assertNull(DB::table('tokens_acceso')->sole()->consumido_en);
        $this->assertSame(1, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($publico, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->aceptar($publico, $token)->assertNoContent();
    }

    public function test_acceptance_requires_csrf_valid_password_and_anonymous_context_and_has_request_limits(): void
    {
        [, $cookies] = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated();
        $token = $this->token();
        $this->aceptar($cookies, $token)->assertConflict();
        $publico = $this->csrfAuth();
        $payload = ['token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->navegador($publico, 'POST', '/api/auth/invitaciones/aceptar', $payload, [], false)->assertStatus(419);
        $this->navegador($publico, 'POST', '/api/auth/invitaciones/aceptar', $payload, ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->aceptar($publico, $token, ['password' => 'corta'])->assertStatus(422);
        $this->aceptar($publico, 'mal-formado')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION_ERROR');
        for ($i = 0; $i < 5; $i++) {
            $this->aceptar($publico, str_repeat('f', 64))->assertStatus(422);
        }
        $this->aceptar($publico, str_repeat('f', 64))->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_mail_template_and_http_configuration_contain_only_security_content(): void
    {
        $url = 'http://localhost:5173/activar-personal#token='.str_repeat('a', 64);
        $html = (new InvitacionPersonalMail($url))->render();
        $this->assertStringContainsString('24 horas', $html);
        $this->assertStringContainsString($url, $html);
        $this->assertStringNotContainsString('diagnóstico', $html);
        $this->assertSame('brevo-http', config('mail.mailers.brevo.transport'));
    }

    public function test_unapproved_mail_transport_does_not_send_or_log_a_secret_link(): void
    {
        [, $cookies] = $this->administrador();
        config(['seguridad.correo.mailer' => 'log']);
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'fallido');
        Mail::assertNothingSent();
    }

    public function test_resend_cannot_target_patient_accounts_existing_active_users_or_invalid_ids(): void
    {
        [$admin, $cookies] = $this->administrador();
        $paciente = $this->usuarioAuth('Paciente', ['estado_cuenta' => 'Pendiente', 'correo_verificado_en' => null, 'password_hash' => null]);
        $this->navegador($cookies, 'POST', '/api/personal/'.$paciente->getKey().'/invitacion')->assertConflict();
        $this->navegador($cookies, 'POST', '/api/personal/'.$admin->getKey().'/invitacion')->assertConflict();
        $this->navegador($cookies, 'POST', '/api/personal/999999/invitacion')->assertNotFound();
        $this->navegador($cookies, 'POST', '/api/personal/9223372036854775808/invitacion')->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_missing_professional_id_and_substituted_names_cannot_create_a_medical_identity(): void
    {
        [, $cookies] = $this->administrador();
        $datos = $this->datos('Médico');
        $this->navegador($cookies, 'POST', '/api/personal', [...$datos, 'id_medico' => null])->assertStatus(422);
        $this->navegador($cookies, 'POST', '/api/personal', [...$datos, 'nombres_personal' => 'Otra persona'])->assertStatus(422);
        $this->assertSame(1, DB::table('usuarios')->count());
        Mail::assertNothingSent();
    }

    public function test_brevo_mailer_is_used_with_simulated_credentials_and_no_network_delivery(): void
    {
        [, $cookies] = $this->administrador();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => 'clave-ficticia-de-pruebas']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<invitacion-ficticia>'], 201)]);
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'aceptado');
        Http::assertSentCount(1);
        $this->assertSame('EXITO', DB::table('bitacora')->where('tipo_evento', 'INVITACION_ENVIO')->sole()->resultado);
    }

    public function test_unconfigured_brevo_preserves_pending_account_without_falling_back_to_other_transports(): void
    {
        [, $cookies] = $this->administrador();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => null]);
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'fallido');
        Mail::assertNothingSent();
    }

    public function test_brevo_http_rejection_preserves_pending_account_token_and_resend_after_commit(): void
    {
        [, $cookies] = $this->administrador();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => 'clave-ficticia-de-pruebas']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        Http::fakeSequence()->push(['code' => 'unauthorized', 'message' => 'Rechazo ficticio'], 401)
            ->push(['messageId' => '<reenvio-ficticio>'], 201);
        $datos = $this->datos();
        $id = $this->navegador($cookies, 'POST', '/api/personal', $datos)->assertCreated()
            ->assertJsonPath('meta.invitacion.envio', 'fallido')->json('data.id_usuario');
        $this->assertSame('Pendiente', Usuario::query()->findOrFail($id)->estado_cuenta);
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->assertSame('FALLO', DB::table('bitacora')->where('tipo_evento', 'INVITACION_ENVIO')->sole()->resultado);
        Http::assertSentCount(1);
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertOk()->assertJsonPath('meta.invitacion.envio', 'aceptado');
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('invalidado_en')->count());
        $this->assertSame(2, DB::table('usuarios')->count());
    }

    public function test_only_configured_frontend_builds_link_and_production_cannot_simulate_delivery(): void
    {
        [, $cookies] = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/personal', [...$this->datos(), 'url' => 'https://ajeno.example.test', 'redirect' => 'https://ajeno.example.test'])
            ->assertCreated();
        Mail::assertSent(InvitacionPersonalMail::class, fn ($mail) => str_starts_with($mail->enlace, 'http://localhost:5173/activar-personal#token='));
        Mail::fake();
        $this->app['env'] = 'production';
        $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->assertJsonPath('meta.invitacion.envio', 'fallido');
        Mail::assertNothingSent();
    }

    public function test_token_storage_failure_does_not_commit_a_staff_account_or_send_an_invitation(): void
    {
        [, $cookies] = $this->administrador();
        $datos = $this->datos('Médico');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prueba_rechazar_token() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'fallo ficticio de token' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER prueba_token_trg BEFORE INSERT ON tokens_acceso
                FOR EACH ROW EXECUTE FUNCTION prueba_rechazar_token();
            SQL);
        $this->navegador($cookies, 'POST', '/api/personal', $datos)->assertStatus(500);
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertNull(DB::table('medicos')->where('id_medico', $datos['id_medico'])->value('id_usuario'));
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'PERSONAL_CREADO')->count());
        Mail::assertNothingSent();
    }

    public function test_failed_resend_preserves_original_token_if_new_token_transaction_fails(): void
    {
        [, $cookies] = $this->administrador();
        $id = $this->navegador($cookies, 'POST', '/api/personal', $this->datos())->assertCreated()->json('data.id_usuario');
        $token = $this->token();
        $this->travel(1)->minutes();
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->navegador($cookies, 'POST', '/api/personal/'.$id.'/invitacion')->assertStatus(500);
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->assertNull(DB::table('tokens_acceso')->sole()->invalidado_en);
        Mail::assertSent(InvitacionPersonalMail::class, 1);
        $this->app->forgetInstance(BitacoraRepository::class);
        $publico = $this->csrfAuth();
        $this->aceptar($publico, $token)->assertNoContent();
    }
}
