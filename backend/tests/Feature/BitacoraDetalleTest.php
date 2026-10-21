<?php

namespace Tests\Feature;

use Clinica\business\services\bitacora\BitacoraService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class BitacoraDetalleTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function administrador(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return [$cookies, $actor];
    }

    private function evento(array $datos = []): string
    {
        $evento = new Bitacora;
        $evento->forceFill(array_replace([
            'tipo_evento' => 'PRUEBA_DETALLE', 'tipo_actor' => 'SISTEMA', 'modulo' => 'PRUEBA',
            'operacion' => 'CONSULTAR', 'resultado' => 'EXITO', 'ocurrido_en' => '2026-10-21 14:00:00+00',
            'correlacion' => (string) Str::uuid(),
        ], $datos))->saveOrFail();

        return (string) $evento->getKey();
    }

    public function test_detail_extends_list_header_preserves_historical_roles_and_does_not_expand_relations_or_mutate(): void
    {
        [$cookies, $actor] = $this->administrador();
        $anterior = $this->evento(['id_evento' => '9007199254740993']);
        $correlacion = (string) Str::uuid();
        $id = $this->evento(['id_evento' => '9007199254740995', 'tipo_evento' => 'MEDICO_ACTUALIZADO',
            'tipo_actor' => 'USUARIO', 'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => 'Recepcionista',
            'id_usuario_objetivo' => $actor->getKey(), 'rol_objetivo' => 'Médico',
            'id_evento_relacionado' => $anterior, 'correlacion' => $correlacion,
            'direccion_ip' => '192.0.2.10', 'procedencia_ip' => 'DIRECTA',
            'referencias' => ['id_medico' => '9007199254740997', 'token' => 'SECRETO-FICTICIO'],
            'cambios_seguros' => ['campos' => ['telefono'], 'password' => 'SECRETO-FICTICIO'],
            'metadatos_seguros' => ['motivo_administrativo' => 'SECRETO-FICTICIO']]);
        $antes = DB::table('bitacora')->orderBy('id_evento')->get()->toJson();
        $lista = $this->navegador($cookies, 'GET', '/api/bitacora?modulo=PRUEBA')->assertOk()->json('data.0');
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id_evento', $id)->assertJsonPath('data.id_evento_relacionado', $anterior)
            ->assertJsonPath('data.correlacion', $correlacion)->assertJsonPath('data.procedencia_ip', 'DIRECTA')
            ->assertJsonPath('data.version_formato', 1)->assertJsonPath('data.cambios_seguros.campos', ['telefono'])
            ->assertJsonPath('data.referencias.id_medico', '9007199254740997')
            ->assertJsonPath('data.rol_responsable', 'Recepcionista')->assertJsonPath('data.rol_objetivo', 'Médico');
        $this->assertSame($lista, array_intersect_key($r->json('data'), $lista));
        $this->assertSame([...array_keys($lista), 'procedencia_ip', 'correlacion', 'id_evento_relacionado', 'version_formato',
            'cambios_seguros', 'metadatos_seguros'], array_keys($r->json('data')));
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
        $this->assertStringNotContainsString($actor->correo_acceso, $r->getContent());
        $this->assertSame($antes, DB::table('bitacora')->orderBy('id_evento')->get()->toJson());
    }

    #[DataProvider('camposPermitidos')]
    public function test_each_field_change_family_only_exposes_allowed_names(string $tipo, array $permitidos): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => $tipo, 'cambios_seguros' => [
            'campos' => [...$permitidos, $permitidos[0], 'password_hash', 'diagnostico', 'token', 'SECRETO-FICTICIO', ['nombres'], false, null],
            'antes' => ['nombres' => 'SECRETO-FICTICIO'], 'despues' => ['telefono' => 'SECRETO-FICTICIO'],
        ], 'metadatos_seguros' => ['motivo_administrativo' => 'SECRETO-FICTICIO']]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()
            ->assertJsonPath('data.cambios_seguros.campos', $permitidos);
        $this->assertSame(['campos'], array_keys($r->json('data.cambios_seguros')));
        $this->assertStringContainsString('"metadatos_seguros":{}', $r->getContent());
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
    }

    public static function camposPermitidos(): array
    {
        $paciente = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono',
            'correo_contacto', 'direccion', 'activo', 'contacto_emergencia', 'recordatorios_habilitados'];
        $medico = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto', 'activo', 'especialidades'];
        $especialidad = ['nombre', 'descripcion', 'activa'];

        return [
            ['PACIENTE_ACTUALIZADO', $paciente], ['PACIENTE_ESTADO_CAMBIADO', $paciente],
            ['MEDICO_CREADO', $medico], ['MEDICO_ACTUALIZADO', $medico], ['MEDICO_ESTADO_CAMBIADO', $medico], ['MEDICO_ESPECIALIDADES_CAMBIADAS', $medico],
            ['ESPECIALIDAD_CREADA', $especialidad], ['ESPECIALIDAD_ACTUALIZADA', $especialidad], ['ESPECIALIDAD_ESTADO_CAMBIADO', $especialidad],
        ];
    }

    public function test_patient_creation_only_accepts_real_boolean_and_its_own_reference(): void
    {
        [$cookies] = $this->administrador();
        foreach ([true, false, 'false', 0, ['sin_documento' => true]] as $valor) {
            $id = $this->evento(['tipo_evento' => 'PACIENTE_CREADO', 'referencias' => ['id_paciente' => '7', 'id_medico' => '8'],
                'cambios_seguros' => ['sin_documento' => $valor, 'campos' => ['nombres']]]);
            $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.referencias', ['id_paciente' => '7']);
            $this->assertSame(is_bool($valor) ? ['sin_documento' => $valor] : [], $r->json('data.cambios_seguros'));
        }
    }

    public function test_medical_specialty_changes_validate_ids_deduplicate_and_limit_lists_without_truncation(): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => 'MEDICO_ESPECIALIDADES_CAMBIADAS', 'cambios_seguros' => [
            'habilitaciones' => ['habilitadas' => [7, '7', '9007199254740993', '9223372036854775807', true, [], '9223372036854775808', 'SECRETO-FICTICIO'],
                'retiradas' => ['9'], 'archivo' => 'SECRETO-FICTICIO'],
        ]]);
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.cambios_seguros', [
            'habilitaciones' => ['habilitadas' => ['7', '9007199254740993', '9223372036854775807'], 'retiradas' => ['9']],
        ]);
        $id = $this->evento(['tipo_evento' => 'MEDICO_ESPECIALIDADES_CAMBIADAS', 'cambios_seguros' => [
            'campos' => array_fill(0, 101, 'especialidades'),
            'habilitaciones' => ['habilitadas' => range(1, 101), 'retiradas' => range(1, 100)],
        ]]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()
            ->assertJsonMissingPath('data.cambios_seguros.campos')->assertJsonMissingPath('data.cambios_seguros.habilitaciones.habilitadas');
        $this->assertSame(array_map(strval(...), range(1, 100)), $r->json('data.cambios_seguros.habilitaciones.retiradas'));
    }

    #[DataProvider('cuentas')]
    public function test_account_before_after_excludes_identity_secrets_and_invalid_states(string $tipo): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => $tipo, 'cambios_seguros' => [
            'antes' => ['rol' => 'Médico', 'estado_cuenta' => 'Activa', 'id_medico' => '9007199254740993', 'correo_acceso' => 'SECRETO-FICTICIO'],
            'despues' => ['rol' => 'Paciente', 'estado_cuenta' => 'Desactivada', 'id_medico' => null, 'password' => 'SECRETO-FICTICIO'],
        ]]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.cambios_seguros', [
            'antes' => ['rol' => 'Médico', 'estado_cuenta' => 'Activa', 'id_medico' => '9007199254740993'],
            'despues' => ['rol' => 'Paciente', 'estado_cuenta' => 'Desactivada', 'id_medico' => null],
        ]);
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
        $id = $this->evento(['tipo_evento' => $tipo, 'cambios_seguros' => [
            'antes' => ['rol' => ['Administrador'], 'estado_cuenta' => 'Suspendida', 'id_medico' => false],
            'despues' => ['rol' => 'QuintoRol', 'estado_cuenta' => ['Activa'], 'id_medico' => '9223372036854775808'],
        ]]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk();
        $this->assertStringContainsString('"cambios_seguros":{}', $r->getContent());
    }

    public static function cuentas(): array
    {
        return [['CUENTA_DESACTIVADA'], ['CUENTA_REACTIVADA'], ['CUENTA_ROL_CAMBIADO']];
    }

    #[DataProvider('activaciones')]
    public function test_activation_only_exposes_record_ids_not_secret_codes(string $tipo): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => $tipo, 'cambios_seguros' => ['id_paciente' => 7,
            'id_codigo_activacion' => '9007199254740993', 'codigo' => 'SECRETO-FICTICIO', 'codigo_hash' => 'SECRETO-FICTICIO']]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.cambios_seguros', [
            'id_paciente' => '7', 'id_codigo_activacion' => '9007199254740993',
        ]);
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
    }

    public static function activaciones(): array
    {
        return [['ACTIVACION_PACIENTE_EMITIDA'], ['VINCULACION_PACIENTE_INICIADA'], ['PACIENTE_CUENTA_VINCULADA']];
    }

    #[DataProvider('semillas')]
    public function test_only_controlled_seed_metadata_is_exposed(string $tipo, string $valor): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => $tipo, 'metadatos_seguros' => [
            'verificacion_correo' => $valor, 'metodo' => 'SEEDER', 'token' => 'SECRETO-FICTICIO',
        ]]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.metadatos_seguros', [
            'verificacion_correo' => $valor, 'metodo' => 'SEEDER',
        ]);
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
        $id = $this->evento(['tipo_evento' => $tipo, 'metadatos_seguros' => [
            'verificacion_correo' => 'SECRETO-FICTICIO', 'metodo' => ['SEEDER'],
        ]]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk();
        $this->assertStringContainsString('"metadatos_seguros":{}', $r->getContent());
    }

    public static function semillas(): array
    {
        return [['INSTALACION_ADMIN_INICIAL', 'AUTORIZACION_INSTALACION'], ['SEED_CUENTA_PRUEBA', 'SIMULADA_PRUEBAS']];
    }

    #[DataProvider('formatosDesconocidos')]
    public function test_unknown_event_or_format_retains_header_but_never_raw_json(string $tipo, int $version): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => $tipo, 'version_formato' => $version,
            'referencias' => ['id_medico' => '7', 'token' => 'SECRETO-FICTICIO'],
            'cambios_seguros' => ['campos' => ['telefono'], 'id_paciente' => '7', 'password' => 'SECRETO-FICTICIO'],
            'metadatos_seguros' => ['verificacion_correo' => 'AUTORIZACION_INSTALACION', 'metodo' => 'SEEDER', 'diagnostico' => 'SECRETO-FICTICIO']]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.tipo_evento', $tipo)
            ->assertJsonPath('data.version_formato', $version);
        foreach (['referencias', 'cambios_seguros', 'metadatos_seguros'] as $campo) {
            $this->assertStringContainsString('"'.$campo.'":{}', $r->getContent());
        }
        $this->assertStringNotContainsString('SECRETO-FICTICIO', $r->getContent());
    }

    public static function formatosDesconocidos(): array
    {
        return [['FUTURO_NO_REVISADO', 1], ['AUTENTICACION_LOGIN', 1], ['SEED_DEMOSTRACION', 1],
            ['MEDICO_ACTUALIZADO', 2], ['INSTALACION_ADMIN_INICIAL', 2]];
    }

    #[DataProvider('estructurasInvalidas')]
    public function test_malformed_nested_values_are_omitted_without_errors(array $cambios): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => 'MEDICO_ACTUALIZADO', 'cambios_seguros' => $cambios]);
        $r = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk();
        $this->assertStringContainsString('"cambios_seguros":{}', $r->getContent());
    }

    public static function estructurasInvalidas(): array
    {
        return [
            [['campos' => (object) ['0' => 'telefono'], 'habilitaciones' => ['7']]],
            [['campos' => 'telefono', 'habilitaciones' => (object) ['habilitadas' => '7', 'retiradas' => (object) ['id' => 7]]]],
            [['campos' => null, 'habilitaciones' => false]],
            [['campos' => true, 'habilitaciones' => null]],
        ];
    }

    public function test_missing_event_invalid_route_range_and_extra_parameters_use_correct_errors(): void
    {
        [$cookies] = $this->administrador();
        $this->navegador($cookies, 'GET', '/api/bitacora/9223372036854775807')->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
        $this->navegador($cookies, 'GET', '/api/bitacora/9223372036854775808')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
        foreach (['0', '-1', 'abc', '1.5', '01', '99999999999999999999'] as $ruta) {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$ruta)->assertNotFound();
        }
        $id = $this->evento();
        foreach (['id_objetivo=1', 'id=1', 'include=responsable', 'campos[]=password', 'page=1'] as $query) {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$id.'?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
        }
    }

    #[DataProvider('otrosRoles')]
    public function test_other_roles_are_rejected_before_lookup_and_parameter_validation(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach (['9223372036854775807', '9223372036854775808'] as $id) {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
        }
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No tienes permiso');
        $this->app->make(BitacoraService::class)->consultar('9223372036854775807');
    }

    public static function otrosRoles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    public function test_missing_deactivated_expired_revoked_and_restricted_sessions_cannot_read_detail(): void
    {
        $id = $this->evento();
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertUnauthorized();
        [$cookies, $actor] = $this->administrador();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertUnauthorized();
        [$cookies, $actor] = $this->administrador();
        DB::table('sessions')->where('user_id', $actor->getKey())->update(['last_activity' => now()->subDays(2)->timestamp]);
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertUnauthorized();
        [$admin] = $this->administrador();
        $objetivo = $this->usuarioAuth();
        $normal = $this->csrfAuth();
        $this->loginAuth($normal, $objetivo)->assertOk();
        $secreto = $this->navegador($admin, 'POST', '/api/usuarios/'.$objetivo->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $this->navegador($normal, 'GET', '/api/bitacora/'.$id)->assertUnauthorized();
        $restringida = $this->csrfAuth();
        $this->loginAuth($restringida, $objetivo, $secreto)->assertOk();
        $this->navegador($restringida, 'GET', '/api/bitacora/'.$id)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public function test_anonymous_and_system_detail_have_no_invented_identity(): void
    {
        [$cookies] = $this->administrador();
        foreach (['ANONIMO', 'SISTEMA'] as $tipo) {
            $id = $this->evento(['tipo_actor' => $tipo]);
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.tipo_actor', $tipo)
                ->assertJsonPath('data.id_usuario_responsable', null)->assertJsonPath('data.rol_responsable', null)
                ->assertJsonPath('data.id_usuario_objetivo', null)->assertJsonPath('data.id_evento_relacionado', null);
        }
    }

    public function test_query_failure_keeps_central_safe_error_contract(): void
    {
        [$cookies] = $this->administrador();
        $repository = Mockery::mock(BitacoraRepository::class);
        $repository->shouldReceive('porId')->once()->with('7')->andThrow(new RuntimeException('SQL SECRETO-FICTICIO /ruta/privada'));
        $this->app->instance(BitacoraRepository::class, $repository);
        $this->navegador($cookies, 'GET', '/api/bitacora/7')->assertInternalServerError()->assertExactJson([
            'success' => false, 'error' => ['code' => 'ERROR_INTERNO', 'message' => 'No se pudo completar la operación.'],
        ]);
    }
}
