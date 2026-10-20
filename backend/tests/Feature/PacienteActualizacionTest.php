<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Paciente;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\CodigoActivacionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class PacienteActualizacionTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

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

    private function ficha(array &$cookies, array $extra = []): array
    {
        return $this->navegador($cookies, 'POST', '/api/pacientes', array_replace([
            'documento_identidad' => '001-A', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], $extra))->assertCreated()->json('data');
    }

    private function editar(array &$cookies, array $ficha, array $datos, string $sufijo = ''): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$ficha['id_paciente'].$sufijo,
            ['actualizado_en_original' => $ficha['actualizado_en'], ...$datos]);
    }

    public function test_partial_update_preserves_identity_and_rejects_stale_form(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $r = $this->editar($cookies, $f, ['telefono' => '71111111'])->assertOk()
            ->assertJsonPath('data.documento_identidad', '001-A')->assertJsonPath('data.nombres', 'Paciente')
            ->assertJsonPath('data.codigo_paciente', $f['codigo_paciente']);
        $this->assertTrue(CarbonImmutable::parse($r->json('data.actualizado_en'))->greaterThan(CarbonImmutable::parse($f['actualizado_en'])));
        $this->editar($cookies, $f, ['direccion' => 'Direccion ficticia'])->assertConflict()->assertJsonPath('error.code', 'PACIENTE_EDICION_OBSOLETA');
        $this->assertNull(DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->value('direccion'));
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')->count());
    }

    public function test_emergency_only_updates_advance_parent_even_with_future_timestamp_and_detect_exact_phone_changes(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $futuro = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(123456);
        DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->update(['actualizado_en' => $futuro->format('Y-m-d H:i:s.uP')]);
        $f['actualizado_en'] = $futuro->format('Y-m-d\TH:i:s.u\Z');
        $contacto = ['nombre' => 'Persona Ficticia', 'parentesco' => 'Familiar', 'telefono' => '001'];
        $r = $this->editar($cookies, $f, ['contacto_emergencia' => $contacto])->assertOk();
        $this->assertSame($futuro->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z'), $r->json('data.actualizado_en'));
        $this->editar($cookies, $f, ['telefono' => '72222222'])->assertConflict();
        $contacto['telefono'] = '1';
        $r = $this->editar($cookies, $r->json('data'), ['contacto_emergencia' => $contacto])->assertOk()->assertJsonPath('data.contacto_emergencia.telefono', '1');
        $r = $this->editar($cookies, $r->json('data'), ['contacto_emergencia' => null])->assertOk()->assertJsonPath('data.contacto_emergencia', null);
        $this->assertSame(0, DB::table('contactos_emergencia')->count());
        $this->editar($cookies, $r->json('data'), [])->assertOk()->assertJsonPath('data.actualizado_en', $r->json('data.actualizado_en'));
    }

    public function test_identity_correction_requires_verification_and_reason_and_cannot_remove_ci(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        foreach ([['nombres' => 'Corregido'], ['documento_identidad' => '002-B', 'identidad_verificada' => true]] as $datos) {
            $this->editar($cookies, $f, $datos)->assertUnprocessable()->assertJsonPath('error.code', 'PACIENTE_IDENTIDAD_NO_VERIFICADA');
        }
        $r = $this->editar($cookies, $f, ['nombres' => 'Corregido', 'identidad_verificada' => true, 'motivo' => 'Correccion documental presencial'])->assertOk();
        $this->editar($cookies, $r->json('data'), ['documento_identidad' => null, 'identidad_verificada' => true, 'motivo' => 'Correccion'])->assertConflict();
        $this->assertSame('001-A', DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->value('documento_identidad'));
    }

    public function test_adds_ci_to_same_record_and_invalidates_pending_activation_when_corrected(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies, ['documento_identidad' => null, 'posibles_duplicados_revisados' => true]);
        $r = $this->editar($cookies, $f, ['documento_identidad' => ' 001-A ', 'identidad_verificada' => true, 'motivo' => 'Documento comprobado'])->assertOk()
            ->assertJsonPath('data.id_paciente', $f['id_paciente'])->assertJsonPath('data.codigo_paciente', $f['codigo_paciente']);
        $codigo = $this->navegador($cookies, 'POST', '/api/pacientes/'.$f['id_paciente'].'/codigo-activacion', ['identidad_verificada' => true])->assertCreated();
        $idCodigo = DB::table('codigos_activacion_paciente')->value('id_codigo_activacion');
        $idRegistro = DB::table('registros_paciente_pendientes')->insertGetId(['tipo_registro' => 'Vinculacion',
            'id_paciente' => $f['id_paciente'], 'id_codigo_activacion' => $idCodigo, 'documento_identidad' => '001-A',
            'correo_propuesto' => 'ficticio@example.test', 'password_hash_temporal' => 'hash-ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addHours(24)], 'id_registro_pendiente');
        DB::table('verificaciones_correo')->insert(['id_registro_pendiente' => $idRegistro, 'proposito' => 'ActivacionPaciente',
            'correo_destino' => 'ficticio@example.test', 'codigo_hash' => 'hash-ficticio', 'enviado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addMinutes(10)]);
        $this->editar($cookies, $r->json('data'), ['documento_identidad' => '002-B', 'identidad_verificada' => true, 'motivo' => 'Correccion comprobada'])->assertOk();
        $this->assertNotNull(DB::table('codigos_activacion_paciente')->value('invalidado_en'));
        $registro = DB::table('registros_paciente_pendientes')->first();
        $this->assertNotNull($registro->invalidado_en);
        $this->assertNull($registro->password_hash_temporal);
        $this->assertNull($registro->datos_registro);
        $this->assertNotNull(DB::table('verificaciones_correo')->value('invalidado_en'));
        $this->assertSame(1, DB::table('pacientes')->count());
        $this->assertNull(DB::table('pacientes')->value('id_usuario'));
        $this->assertStringNotContainsString($codigo->json('data.codigo_activacion'), DB::table('bitacora')->get()->toJson());
    }

    public function test_ci_conflict_preserves_old_codes_and_rejects_other_live_web_reservation(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $this->ficha($cookies, ['documento_identidad' => '002-B']);
        $this->navegador($cookies, 'POST', '/api/pacientes/'.$f['id_paciente'].'/codigo-activacion', ['identidad_verificada' => true])->assertCreated();
        $this->editar($cookies, $f, ['documento_identidad' => '002-B', 'identidad_verificada' => true, 'motivo' => 'Correccion'])->assertConflict();
        DB::table('registros_paciente_pendientes')->insert(['tipo_registro' => 'Nuevo', 'documento_identidad' => '003-C',
            'correo_propuesto' => 'reserva@example.test', 'password_hash_temporal' => 'hash-ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addDay()]);
        $this->editar($cookies, $f, ['documento_identidad' => '003-C', 'identidad_verificada' => true, 'motivo' => 'Correccion'])->assertConflict();
        $this->assertNull(DB::table('codigos_activacion_paciente')->value('invalidado_en'));
        $this->assertSame('001-A', DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->value('documento_identidad'));
    }

    public function test_state_and_ci_changes_preserve_linked_account_sessions_and_appointments(): void
    {
        $cookies = $this->recepcion();
        $titular = $this->usuarioAuth('Paciente');
        $propias = $this->csrfAuth();
        $this->loginAuth($propias, $titular)->assertOk();
        $recursos = $this->citaResources();
        $id = DB::table('pacientes')->where('id_usuario', $titular->getKey())->value('id_paciente');
        $recursos['patient'] = $id;
        $cita = DB::table('citas')->insertGetId($this->citaRow($recursos), 'id_cita');
        $cuentaAnterior = DB::table('usuarios')->where('id_usuario', $titular->getKey())->first();
        $citaAnterior = DB::table('citas')->where('id_cita', $cita)->first();
        $f = $this->navegador($cookies, 'GET', '/api/pacientes/'.$id)->assertOk()->json('data');
        $r = $this->editar($cookies, $f, ['documento_identidad' => 'CI-PROPIO', 'identidad_verificada' => true, 'motivo' => 'Documento comprobado'])->assertOk();
        $r = $this->editar($cookies, $r->json('data'), ['activo' => true, 'motivo' => 'Rehabilitacion administrativa'], '/estado')->assertOk();
        $r = $this->editar($cookies, $r->json('data'), ['activo' => false, 'motivo' => 'Baja administrativa'], '/estado')->assertOk();
        $this->navegador($propias, 'GET', '/api/auth/me')->assertOk();
        $this->assertEquals($cuentaAnterior, DB::table('usuarios')->where('id_usuario', $titular->getKey())->first());
        $this->assertEquals($citaAnterior, DB::table('citas')->where('id_cita', $cita)->first());
        $this->assertSame($titular->getKey(), DB::table('pacientes')->where('id_paciente', $id)->value('id_usuario'));
        $eventos = DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ESTADO_CAMBIADO')->count();
        $this->editar($cookies, $r->json('data'), ['activo' => false, 'motivo' => 'Sin cambio'], '/estado')->assertOk()
            ->assertJsonPath('data.actualizado_en', $r->json('data.actualizado_en'));
        $this->assertSame($eventos, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ESTADO_CAMBIADO')->count());
    }

    public function test_audit_failure_rolls_back_identity_contact_and_invalidations(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $this->navegador($cookies, 'POST', '/api/pacientes/'.$f['id_paciente'].'/codigo-activacion', ['identidad_verificada' => true])->assertCreated();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('pacienteModificado')->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->editar($cookies, $f, ['documento_identidad' => '002-B', 'identidad_verificada' => true, 'motivo' => 'Correccion',
            'contacto_emergencia' => ['nombre' => 'Contacto Ficticio', 'parentesco' => 'Familiar', 'telefono' => '71111111']])->assertStatus(500);
        $this->assertSame('001-A', DB::table('pacientes')->value('documento_identidad'));
        $this->assertSame(0, DB::table('contactos_emergencia')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->value('invalidado_en'));
        $this->assertSame($f, $this->navegador($cookies, 'GET', '/api/pacientes/'.$f['id_paciente'])->assertOk()->json('data'));
    }

    public static function camposInvalidos(): array
    {
        return [['id_usuario', 1], ['activo', false], ['codigo_paciente', 'OTRO'], ['antecedentes', 'No permitido'],
            ['recordatorios_habilitados', true], ['actualizado_en_original', '2026-10-20T00:00:00.000Z'],
            ['motivo', '   '], ['contacto_emergencia', ['nombre' => 'Incompleto']]];
    }

    #[DataProvider('camposInvalidos')]
    public function test_rejects_unknown_fields_and_invalid_preconditions(string $campo, mixed $valor): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $this->editar($cookies, $f, [$campo => $valor])->assertUnprocessable();
    }

    public function test_state_requires_reason_and_precondition_and_stale_noop_is_rejected(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $this->editar($cookies, $f, ['activo' => false], '/estado')->assertUnprocessable();
        $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$f['id_paciente'], ['telefono' => '71111111'])->assertUnprocessable();
        $this->editar($cookies, $f, ['activo' => false, 'motivo' => 'Baja administrativa'], '/estado')->assertOk();
        $this->editar($cookies, $f, ['activo' => false, 'motivo' => 'Baja administrativa'], '/estado')->assertConflict();
        $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$f['id_paciente'], ['actualizado_en_original' => $f['actualizado_en']], csrf: false)->assertStatus(419);
        $this->editar($cookies, [...$f, 'id_paciente' => '9223372036854775807'], ['telefono' => '71111111'])->assertNotFound();
    }

    public function test_linking_also_preserves_monotonic_timestamp_and_invalidates_old_form(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $titular = $this->usuarioAuth('Paciente', vincular: false);
        $futuro = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(999999);
        DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->update(['actualizado_en' => $futuro->format('Y-m-d H:i:s.uP')]);
        $f['actualizado_en'] = $futuro->format('Y-m-d\TH:i:s.u\Z');
        $this->app->make(CodigoActivacionRepository::class)->vincular(Paciente::query()->findOrFail($f['id_paciente']), $titular->getKey());
        $this->assertTrue(Paciente::query()->findOrFail($f['id_paciente'])->actualizado_en->greaterThan($futuro));
        $this->editar($cookies, $f, ['telefono' => '71111111'])->assertConflict();
    }

    public function test_state_audit_failure_rolls_back_and_unauthenticated_cannot_write(): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $anonimas = $this->csrfAuth();
        $this->editar($anonimas, $f, ['telefono' => '71111111'])->assertUnauthorized();
        $this->editar($anonimas, $f, ['activo' => false, 'motivo' => 'Baja'], '/estado')->assertUnauthorized();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('pacienteModificado')->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->editar($cookies, $f, ['activo' => false, 'motivo' => 'Baja'], '/estado')->assertStatus(500);
        $this->assertTrue(Paciente::query()->findOrFail($f['id_paciente'])->activo);
        $this->assertSame($f, $this->navegador($cookies, 'GET', '/api/pacientes/'.$f['id_paciente'])->assertOk()->json('data'));
    }

    #[DataProvider('rolesAjenos')]
    public function test_other_roles_cannot_update_or_change_state(string $rol): void
    {
        $cookies = $this->recepcion();
        $f = $this->ficha($cookies);
        $actor = $this->usuarioAuth($rol);
        $ajenas = $this->csrfAuth();
        $this->loginAuth($ajenas, $actor)->assertOk();
        $this->editar($ajenas, $f, ['telefono' => '71111111'])->assertForbidden();
        $this->editar($ajenas, $f, ['activo' => false, 'motivo' => 'Baja'], '/estado')->assertForbidden();
    }

    public static function rolesAjenos(): array
    {
        return [['Administrador'], ['Médico'], ['Paciente']];
    }
}
