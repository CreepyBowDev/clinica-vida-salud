<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\CorreoSeguridadService;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\utils\RegistroPacienteContexto;
use Clinica\shared\utils\VerificacionPacienteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class VinculacionPacienteTest extends TestCase
{
    use AutenticacionFixtures;
    use LaboratorioFixtures;

    private array $recepcion;

    private Paciente $paciente;

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
        $this->paciente = new Paciente;
        $this->paciente->forceFill(['codigo_paciente' => 'PAC-HISTORICO', 'documento_identidad' => '000123-A lp',
            'nombres' => 'Paciente', 'apellidos' => 'Histórico ficticio', 'fecha_nacimiento' => '1980-01-01',
            'telefono' => '70000000', 'correo_contacto' => 'contacto@example.test', 'direccion' => 'Dirección ficticia', 'activo' => false])->saveOrFail();
        $this->recepcion = $this->csrfAuth();
        $this->loginAuth($this->recepcion, $this->usuarioAuth('Recepcionista'))->assertOk();
    }

    private function emitir(array $extra = [], ?string $id = null): TestResponse
    {
        return $this->navegador($this->recepcion, 'POST', '/api/pacientes/'.($id ?? $this->paciente->getKey()).'/codigo-activacion',
            ['identidad_verificada' => true, ...$extra]);
    }

    private function iniciar(array &$cookies, string $principal, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/registro/vincular', ['documento_identidad' => ' 000123-A lp ',
            'codigo_activacion' => $principal, 'correo_acceso' => ' HISTORICO@EXAMPLE.TEST ',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$extra]);
    }

    private function codigo(): string
    {
        return Mail::sent(VerificacionPacienteMail::class)->last()->codigo;
    }

    private function verificar(array &$cookies, ?string $codigo = null): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/registro/verificar', ['codigo' => $codigo ?? $this->codigo()]);
    }

    public function test_reception_issues_only_hmac_and_patient_links_same_inactive_record_with_both_codes_and_new_login(): void
    {
        $antes = $this->paciente->fresh()->getAttributes();
        $principal = $this->emitir()->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data.codigo_activacion');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/D', $principal);
        $codigo = DB::table('codigos_activacion_paciente')->sole();
        $this->assertSame(RegistroPacienteContexto::recepcion($this->paciente->getKey(), $principal), $codigo->codigo_hash);
        $this->assertSame(86400.0, CarbonImmutable::parse($codigo->emitido_en)->diffInSeconds(CarbonImmutable::parse($codigo->expira_en)));
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $registro = DB::table('registros_paciente_pendientes')->sole();
        $this->assertSame('Vinculacion', $registro->tipo_registro);
        $this->assertSame($this->paciente->getKey(), $registro->id_paciente);
        $this->assertSame($codigo->id_codigo_activacion, $registro->id_codigo_activacion);
        $this->assertSame($codigo->expira_en, $registro->expira_en);
        $this->assertTrue(Hash::check(self::PASSWORD, $registro->password_hash_temporal));
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->assertSame(1, DB::table('usuarios')->count());
        $email = $this->codigo();
        $this->verificar($cookies)->assertNoContent();
        $usuario = Usuario::query()->where('correo_acceso', 'historico@example.test')->sole();
        $this->assertSame('Paciente', $usuario->rol->nombre);
        $this->assertSame('Activa', $usuario->estado_cuenta);
        $this->assertNotNull($usuario->correo_verificado_en);
        $this->assertSame(1, DB::table('pacientes')->count());
        $despues = $this->paciente->fresh()->getAttributes();
        unset($antes['id_usuario'], $antes['actualizado_en'], $despues['id_usuario'], $despues['actualizado_en']);
        $this->assertEquals($antes, $despues);
        $this->assertSame($usuario->getKey(), $this->paciente->fresh()->id_usuario);
        $this->assertNotNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->datos_registro);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->password_hash_temporal);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $otroContexto = $this->csrfAuth();
        $this->verificar($otroContexto, $email)->assertStatus(422);
        $bitacora = DB::table('bitacora')->get()->toJson();
        foreach ([$principal, $email, self::PASSWORD, $registro->password_hash_temporal, '000123-A lp', 'historico@example.test'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $bitacora);
        }
        Mail::assertSent(VerificacionPacienteMail::class, 1);
    }

    public static function rolesNoAutorizados(): array
    {
        return [['Administrador'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('rolesNoAutorizados')]
    public function test_only_reception_can_issue_even_administrator_has_no_permission(string $rol): void
    {
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $this->usuarioAuth($rol))->assertOk();
        $this->navegador($cookies, 'POST', '/api/pacientes/'.$this->paciente->getKey().'/codigo-activacion', ['identidad_verificada' => true])->assertForbidden();
        $this->assertSame(0, DB::table('codigos_activacion_paciente')->count());
    }

    public function test_reception_requires_identity_document_and_no_existing_account(): void
    {
        $this->emitir(['identidad_verificada' => false])->assertStatus(422);
        $this->emitir(['id_paciente' => 999])->assertStatus(422);
        $this->emitir(id: '99999')->assertNotFound();
        $this->paciente->documento_identidad = null;
        $this->paciente->saveOrFail();
        $this->emitir()->assertConflict();
        $this->paciente->documento_identidad = '000123-A lp';
        $this->paciente->id_usuario = $this->usuarioAuth('Paciente', vincular: false)->getKey();
        $this->paciente->saveOrFail();
        $this->emitir()->assertConflict();
        $this->assertSame(0, DB::table('codigos_activacion_paciente')->count());
    }

    public static function entradasInvalidas(): array
    {
        return [[['documento_identidad' => null]], [['documento_identidad' => "\tCI"]], [['codigo_activacion' => 'corto']],
            [['codigo_activacion' => '!!!!!!!!!!!!']], [['correo_acceso' => 'incorrecto']], [['password' => 'corta']],
            [['id_paciente' => 1]], [['rol' => 'Administrador']], [['nombres' => 'Sobrescribir']]];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_link_validates_fields_without_accepting_identity_or_profile_overrides(array $extra): void
    {
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, 'Abc123Def456', $extra)->assertStatus(422);
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
    }

    public function test_public_response_does_not_enumerate_document_code_account_or_email(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $respuesta = $this->iniciar($cookies, $principal, ['documento_identidad' => 'INEXISTENTE'])->assertStatus(202)->json();
        $this->iniciar($cookies, str_repeat('0', 12))->assertStatus(202)->assertExactJson($respuesta);
        $usuario = $this->usuarioAuth();
        $this->iniciar($cookies, $principal, ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202)->assertExactJson($respuesta);
        Mail::assertNothingSent();
        $this->iniciar($cookies, $principal)->assertStatus(202)->assertExactJson($respuesta);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
    }

    public function test_reserved_code_cannot_be_taken_or_exhausted_by_another_cookie_or_changed_by_owner_retry(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $antes = DB::table('registros_paciente_pendientes')->sole();
        $ajeno = $this->csrfAuth();
        $this->iniciar($ajeno, $principal, ['correo_acceso' => 'ajeno@example.test'])->assertStatus(202);
        $this->verificar($ajeno)->assertStatus(422);
        $this->travel(1)->minutes();
        $this->iniciar($cookies, $principal, ['password' => 'Otra frase ficticia 123!', 'password_confirmation' => 'Otra frase ficticia 123!'])->assertStatus(202);
        $this->assertEquals($antes, DB::table('registros_paciente_pendientes')->sole());
        $this->assertSame(0, DB::table('codigos_activacion_paciente')->sole()->intentos_fallidos);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_five_incorrect_reception_codes_exhaust_main_code_and_require_reception_reissue(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $incorrecto = $principal === str_repeat('0', 12) ? str_repeat('1', 12) : str_repeat('0', 12);
        for ($i = 0; $i < 5; $i++) {
            $this->iniciar($cookies, $incorrecto)->assertStatus(202);
        }
        $this->assertSame(5, DB::table('codigos_activacion_paciente')->sole()->intentos_fallidos);
        $this->assertNotNull(DB::table('codigos_activacion_paciente')->sole()->invalidado_en);
        $this->travel(1)->minutes();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        Mail::assertNothingSent();
        $nuevo = $this->emitir()->assertCreated()->json('data.codigo_activacion');
        $this->iniciar($cookies, $nuevo)->assertStatus(202);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_reception_replacement_invalidates_reserved_process_email_and_old_principal(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $email = $this->codigo();
        $nuevo = $this->emitir()->assertCreated()->json('data.codigo_activacion');
        $this->assertNotSame($principal, $nuevo);
        $this->assertNotNull(DB::table('registros_paciente_pendientes')->sole()->invalidado_en);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->password_hash_temporal);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->invalidado_en);
        $this->verificar($cookies, $email)->assertStatus(422);
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->iniciar($cookies, $nuevo)->assertStatus(202);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_main_deadline_is_not_extended_by_start_or_resend_and_both_controls_must_remain_valid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $principal = $this->emitir()->json('data.codigo_activacion');
        $this->travel(23)->hours();
        $this->travel(59)->minutes();
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->assertSame(DB::table('codigos_activacion_paciente')->sole()->expira_en, DB::table('verificaciones_correo')->sole()->expira_en);
        $this->travel(1)->minutes();
        $this->verificar($cookies)->assertStatus(422);
        $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        $this->assertNull($this->paciente->fresh()->id_usuario);
    }

    public function test_email_exhaustion_and_resend_preserve_main_reservation_and_apply_wait_and_hourly_cap(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $incorrecto = $this->codigo() === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->verificar($cookies, $incorrecto)->assertStatus(422);
        }
        $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        }
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertSame(0, DB::table('codigos_activacion_paciente')->sole()->intentos_fallidos);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_email_collision_or_changed_document_after_reservation_cannot_link_or_overwrite_record(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->usuarioAuth('Administrador', ['correo_acceso' => 'historico@example.test']);
        $this->verificar($cookies)->assertStatus(422);
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
    }

    public function test_document_change_invalidates_reservation_before_email_delivery_or_activation(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->paciente->documento_identidad = 'CI-CORREGIDO';
        $this->paciente->saveOrFail();
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        Mail::assertSent(VerificacionPacienteMail::class, 1);
        $this->verificar($cookies)->assertStatus(422);
        $this->assertNull($this->paciente->fresh()->id_usuario);
    }

    public function test_audit_failure_rolls_back_both_code_consumptions_and_link_then_retry_succeeds(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $fake = Mockery::mock(BitacoraRepository::class);
        $fake->shouldReceive('autenticacion')->andThrow(new RuntimeException('Fallo ficticio'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->verificar($cookies)->assertStatus(500);
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->completado_en);
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_failed_delivery_keeps_exclusive_process_and_reissue_recovers_without_duplicate_account(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $correo = Mockery::mock(CorreoSeguridadService::class);
        $correo->shouldReceive('enviarActivacionPaciente')->once()->andThrow(new RuntimeException('Fallo ficticio'));
        $this->app->instance(CorreoSeguridadService::class, $correo);
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->app->forgetInstance(CorreoSeguridadService::class);
        $this->travel(1)->minutes();
        $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_csrf_origin_anonymous_issuance_and_authenticated_link_are_rejected(): void
    {
        $uri = '/api/pacientes/'.$this->paciente->getKey().'/codigo-activacion';
        $anonimo = $this->csrfAuth();
        $this->navegador($anonimo, 'POST', $uri, ['identidad_verificada' => true])->assertUnauthorized();
        $this->navegador($this->recepcion, 'POST', $uri, ['identidad_verificada' => true], csrf: false)->assertStatus(419);
        $this->navegador($this->recepcion, 'POST', $uri, ['identidad_verificada' => true], ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $principal = $this->emitir()->json('data.codigo_activacion');
        $this->iniciar($this->recepcion, $principal)->assertConflict();
        $this->navegador($anonimo, 'POST', '/api/auth/registro/vincular', [], csrf: false)->assertStatus(419);
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
    }

    public function test_link_preserves_existing_appointments_clinical_versions_requests_and_result_documents(): void
    {
        foreach (['000004_create_especialidades', '000017_create_medico_especialidad', '000019_create_citas',
            '000022_create_consultas_medicas', '000023_create_versiones_consulta', '000024_create_versiones_antecedente',
            '000025_create_solicitudes_laboratorio', '000026_create_documentos_laboratorio',
            '000027_create_versiones_resultado', '000028_create_revisiones_resultado'] as $nombre) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$nombre.'_table.php'))->up();
        }
        $contexto = $this->laboratorioContext();
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($contexto));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($contexto, ['alergias' => 'Antecedente ficticio protegido']));
        $this->resultadoEnRevision($contexto);
        $this->paciente->documento_identidad = null;
        $this->paciente->saveOrFail();
        $this->paciente = Paciente::query()->findOrFail($contexto['patient']);
        $this->paciente->documento_identidad = '000123-A lp';
        $this->paciente->saveOrFail();
        $tablas = ['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente',
            'solicitudes_laboratorio', 'documentos_laboratorio', 'versiones_resultado', 'revisiones_resultado'];
        $antes = [];
        foreach ($tablas as $tabla) {
            $antes[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $principal = $this->emitir()->assertCreated()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->verificar($cookies)->assertNoContent();
        foreach ($tablas as $tabla) {
            $this->assertSame($antes[$tabla], DB::table($tabla)->get()->toJson(), $tabla);
        }
        $this->assertSame($contexto['patient'], Usuario::query()->where('correo_acceso', 'historico@example.test')->sole()->paciente->getKey());
        $this->assertStringNotContainsString('Antecedente ficticio protegido', DB::table('bitacora')->get()->toJson());
    }

    public function test_failed_reception_replacement_restores_previous_principal_and_pending_email(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $fake = Mockery::mock(BitacoraRepository::class)->makePartial();
        $fake->shouldReceive('autenticacion')->withArgs(fn ($tipo) => $tipo === 'ACTIVACION_PACIENTE_EMITIDA')
            ->andThrow(new RuntimeException('Fallo ficticio'));
        $this->app->instance(BitacoraRepository::class, $fake);
        $this->emitir()->assertStatus(500);
        $this->assertSame(1, DB::table('codigos_activacion_paciente')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->invalidado_en);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->invalidado_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->invalidado_en);
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->verificar($cookies)->assertNoContent();
    }

    public function test_invalidated_principal_cannot_be_replaced_by_valid_email_code_and_tampered_context_cannot_verify(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $falso = $cookies;
        $falso[RegistroPacienteContexto::COOKIE] = str_repeat('a', 64);
        $this->verificar($falso)->assertStatus(422);
        DB::table('codigos_activacion_paciente')->update(['invalidado_en' => now('UTC')]);
        $this->verificar($cookies)->assertStatus(422);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->assertNotNull(DB::table('registros_paciente_pendientes')->sole()->invalidado_en);
    }

    public function test_new_and_historical_requests_share_email_hourly_send_quota_after_replacement(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            $this->navegador($cookies, 'POST', '/api/auth/registro/reenviar')->assertStatus(202);
        }
        $this->emitir()->assertCreated(); // invalida la reserva, conservando las cinco emisiones de correo.
        $nuevo = $this->csrfAuth();
        $this->navegador($nuevo, 'POST', '/api/auth/registro', ['documento_identidad' => 'CI-NUEVO',
            'nombres' => 'Paciente', 'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
            'correo_acceso' => 'historico@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(202);
        $this->assertSame(2, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(5, DB::table('verificaciones_correo')->count());
        Mail::assertSent(VerificacionPacienteMail::class, 5);
    }

    public function test_email_expiring_during_account_write_rolls_back_account_link_and_both_consumptions(): void
    {
        $principal = $this->emitir()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $real = new UsuarioRepository;
        $fake = Mockery::mock(UsuarioRepository::class)->makePartial();
        $fake->shouldReceive('crearPaciente')->once()->andReturnUsing(function (...$args) use ($real) {
            $usuario = $real->crearPaciente(...$args);
            $this->travel(10)->minutes();

            return $usuario;
        });
        $this->app->instance(UsuarioRepository::class, $fake);
        $this->verificar($cookies)->assertStatus(422);
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertNull($this->paciente->fresh()->id_usuario);
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
    }

    public function test_legacy_document_uses_same_ascii_trim_normalization_without_overwriting_stored_identity(): void
    {
        DB::table('pacientes')->where('id_paciente', $this->paciente->getKey())->update(['documento_identidad' => ' 000123-A lp ']);
        $principal = $this->emitir()->assertCreated()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $this->iniciar($cookies, $principal)->assertStatus(202);
        $this->verificar($cookies)->assertNoContent();
        $this->assertSame(' 000123-A lp ', $this->paciente->fresh()->documento_identidad);
        $this->assertNotNull($this->paciente->fresh()->id_usuario);
    }
}
