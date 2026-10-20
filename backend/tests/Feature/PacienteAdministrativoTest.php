<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Paciente;
use Clinica\data\repositories\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class PacienteAdministrativoTest extends TestCase
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

    private function recepcion(): array
    {
        $actor = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return $cookies;
    }

    private function datos(array $extra = []): array
    {
        return array_replace(['documento_identidad' => '001-A', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], $extra);
    }

    public function test_creates_and_reads_administrative_record_without_account_or_clinical_data(): void
    {
        $cookies = $this->recepcion();
        $cuentas = DB::table('usuarios')->count();
        $datos = $this->datos(['documento_identidad' => ' 001-A ', 'correo_contacto' => 'CONTACTO@example.test',
            'contacto_emergencia' => ['nombre' => 'Contacto Ficticio', 'parentesco' => 'Familiar', 'telefono' => '71111111']]);
        $r = $this->navegador($cookies, 'POST', '/api/pacientes', $datos)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.documento_identidad', '001-A')
            ->assertJsonPath('data.correo_contacto', 'contacto@example.test')->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.cuenta_vinculada', false)->assertJsonPath('data.recordatorios_habilitados', null);
        $id = $r->json('data.id_paciente');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $r->json('data.actualizado_en'));
        $this->assertSame($cuentas, DB::table('usuarios')->count());
        $this->assertNull(DB::table('pacientes')->where('id_paciente', $id)->value('id_usuario'));
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$id)->assertOk()->assertExactJson($r->json());
        $evento = DB::table('bitacora')->where('tipo_evento', 'PACIENTE_CREADO')->first();
        $this->assertSame('PACIENTES', $evento->modulo);
        $this->assertSame(['id_paciente' => $id], json_decode($evento->referencias, true));
        foreach (['001-A', 'Contacto Ficticio', '71111111', 'contacto@example.test'] as $privado) {
            $this->assertStringNotContainsString($privado, json_encode($evento));
        }
    }

    public function test_requires_review_without_ci_and_does_not_merge_similar_people(): void
    {
        $cookies = $this->recepcion();
        $datos = $this->datos(['documento_identidad' => null]);
        $this->navegador($cookies, 'POST', '/api/pacientes', $datos)->assertUnprocessable()
            ->assertJsonValidationErrors('posibles_duplicados_revisados', 'error.details');
        $datos['posibles_duplicados_revisados'] = true;
        $uno = $this->navegador($cookies, 'POST', '/api/pacientes', $datos)->assertCreated();
        unset($datos['documento_identidad']);
        $dos = $this->navegador($cookies, 'POST', '/api/pacientes', $datos)->assertCreated();
        $this->assertNotSame($uno->json('data.id_paciente'), $dos->json('data.id_paciente'));
        $this->assertNotSame($uno->json('data.codigo_paciente'), $dos->json('data.codigo_paciente'));
        $uno->assertJsonPath('data.documento_identidad', null);
        $dos->assertJsonPath('data.documento_identidad', null);
    }

    public function test_rejects_duplicate_document_and_reuses_existing_activation_contract(): void
    {
        $cookies = $this->recepcion();
        $uno = $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos())->assertCreated();
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos(['documento_identidad' => ' 001-A ']))
            ->assertConflict()->assertJsonPath('error.code', 'PACIENTE_DOCUMENTO_NO_DISPONIBLE');
        $this->navegador($cookies, 'POST', '/api/pacientes/'.$uno->json('data.id_paciente').'/codigo-activacion',
            ['identidad_verificada' => true])->assertCreated()->assertJsonStructure(['data' => ['codigo_activacion', 'expira_en']]);
        $this->assertSame(1, DB::table('pacientes')->where('documento_identidad', '001-A')->count());
    }

    public function test_search_filters_pagination_and_minimal_projection(): void
    {
        $cookies = $this->recepcion();
        $a = $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos(['nombres' => 'Ana % Ficticia']))->assertCreated();
        $b = $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos(['documento_identidad' => '002-B', 'nombres' => 'ANA Otra']))->assertCreated();
        DB::table('pacientes')->where('id_paciente', $a->json('data.id_paciente'))->update(['activo' => false]);
        $this->navegador($cookies, 'GET', '/api/pacientes?nombres=ana&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id_paciente', $b->json('data.id_paciente'))
            ->assertJsonMissingPath('data.0.contacto_emergencia')->assertJsonMissingPath('data.0.telefono');
        $this->navegador($cookies, 'GET', '/api/pacientes?nombres=%25&activo=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_paciente', $a->json('data.id_paciente'));
        $this->navegador($cookies, 'GET', '/api/pacientes?documento_identidad=001-A&apellidos=FICT')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/pacientes?nombres=Inexistente')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($cookies, 'GET', '/api/pacientes/9223372036854775807')->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/pacientes?per_page=101')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/pacientes?diagnostico=x')->assertUnprocessable();
    }

    public static function rolesNoPermitidos(): array
    {
        return [['Administrador'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('rolesNoPermitidos')]
    public function test_other_roles_cannot_list_read_or_create(string $rol): void
    {
        $usuario = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->navegador($cookies, 'GET', '/api/pacientes')->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/pacientes/1')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos())->assertForbidden();
    }

    public static function entradasInvalidas(): array
    {
        return [['id_usuario', '1'], ['activo', false], ['password', 'No guardar esta frase'],
            ['antecedentes', 'No permitido'], ['recordatorios_habilitados', true],
            ['fecha_nacimiento', '2999-01-01'], ['documento_identidad', "CI\nINVALIDO"],
            ['nombres', '   '], ['sexo', str_repeat('s', 33)],
            ['contacto_emergencia', []], ['contacto_emergencia', ['nombre' => 'Solo nombre']],
            ['contacto_emergencia', ['nombre' => 'Contacto', 'parentesco' => 'Familiar', 'telefono' => '70000000', 'id_paciente' => 2]]];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_invalid_or_privileged_fields_never_persist(string $campo, mixed $valor): void
    {
        $cookies = $this->recepcion();
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos([$campo => $valor]))->assertUnprocessable();
        $this->assertSame(0, DB::table('pacientes')->count());
    }

    public function test_requires_real_session_and_csrf_and_invalidates_changed_account(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/pacientes')->assertUnauthorized();
        $cookies = $this->recepcion();
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos(), csrf: false)->assertStatus(419);
        DB::table('usuarios')->where('correo_acceso', DB::table('usuarios')->value('correo_acceso'))->update(['cambio_clave_obligatorio' => true]);
        $this->navegador($cookies, 'GET', '/api/pacientes')->assertUnauthorized();
    }

    public function test_audit_failure_rolls_back_patient_and_emergency_contact(): void
    {
        $cookies = $this->recepcion();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('pacienteCreado')->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos(['contacto_emergencia' => [
            'nombre' => 'Contacto', 'parentesco' => 'Familiar', 'telefono' => '70000000']]))->assertStatus(500)
            ->assertJsonPath('error.message', 'No se pudo completar la operación.');
        $this->assertSame(0, Paciente::query()->count());
        $this->assertSame(0, DB::table('contactos_emergencia')->count());
    }

    public function test_restricted_reception_session_cannot_access_administrative_patients(): void
    {
        $admin = $this->usuarioAuth();
        $recepcion = $this->usuarioAuth('Recepcionista');
        $administracion = $this->csrfAuth();
        $this->loginAuth($administracion, $admin)->assertOk();
        $secreto = $this->navegador($administracion, 'POST', '/api/usuarios/'.$recepcion->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $recepcion, $secreto)->assertOk();
        $this->navegador($cookies, 'GET', '/api/pacientes')->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($cookies, 'GET', '/api/pacientes/1')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos())->assertForbidden();
    }

    public function test_respects_live_web_reservations_and_cleans_expired_ones_atomically(): void
    {
        $cookies = $this->recepcion();
        $id = DB::table('registros_paciente_pendientes')->insertGetId(['tipo_registro' => 'Nuevo',
            'documento_identidad' => '001-A', 'correo_propuesto' => 'ficticio@example.test',
            'password_hash_temporal' => 'hash-ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC')->subHours(25), 'expira_en' => CarbonImmutable::now('UTC')->addMinutes(5)], 'id_registro_pendiente');
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos())->assertConflict();
        DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['expira_en' => CarbonImmutable::now('UTC')->subHour()]);
        $this->navegador($cookies, 'POST', '/api/pacientes', $this->datos())->assertCreated();
        $reserva = DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->first();
        $this->assertNotNull($reserva->invalidado_en);
        $this->assertNull($reserva->password_hash_temporal);
    }
}
