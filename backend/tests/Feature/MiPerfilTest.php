<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class MiPerfilTest extends TestCase
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

    private function titular(): array
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $perfil = $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertOk()->json('data');

        return [$cookies, $perfil, $usuario];
    }

    private function editar(array &$cookies, array $perfil, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $perfil['actualizado_en'], ...$datos]);
    }

    public function test_profile_is_own_safe_projection_even_when_fiche_inactive(): void
    {
        [$cookies, $f, $usuario] = $this->titular();
        $this->usuarioAuth('Paciente');
        $this->assertSame($usuario->correo_acceso, $f['correo_acceso']);
        $this->assertFalse($f['activo']);
        $this->assertNull($f['recordatorios_habilitados']);
        $this->assertSame(['id_paciente', 'codigo_paciente', 'documento_identidad', 'nombres', 'apellidos', 'activo',
            'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion', 'cuenta_vinculada',
            'contacto_emergencia', 'recordatorios_habilitados', 'actualizado_en', 'correo_acceso'], array_keys($f));
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        $this->assertSame($f, $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertOk()->json('data'));
    }

    public function test_partial_contact_edit_preserves_account_and_other_patient_and_rejects_stale_snapshot(): void
    {
        [$cookies, $f, $usuario] = $this->titular();
        $otro = $this->usuarioAuth('Paciente');
        $otraFicha = DB::table('pacientes')->where('id_usuario', $otro->getKey())->first();
        $cuenta = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->first();
        $r = $this->editar($cookies, $f, ['telefono' => '71111111', 'correo_contacto' => ' CONTACTO@example.test ', 'direccion' => 'Direccion ficticia'])->assertOk()
            ->assertJsonPath('data.correo_contacto', 'contacto@example.test')->assertJsonPath('data.correo_acceso', $usuario->correo_acceso);
        $this->assertTrue(CarbonImmutable::parse($r->json('data.actualizado_en'))->greaterThan(CarbonImmutable::parse($f['actualizado_en'])));
        $this->editar($cookies, $f, ['direccion' => null])->assertConflict()->assertJsonPath('error.code', 'PACIENTE_EDICION_OBSOLETA');
        $this->assertEquals($cuenta, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->first());
        $this->assertEquals($otraFicha, DB::table('pacientes')->where('id_usuario', $otro->getKey())->first());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
        $evento = DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')->sole();
        $this->assertSame($usuario->getKey(), $evento->id_usuario_responsable);
        $this->assertStringNotContainsString('contacto@example.test', json_encode($evento));
        $this->assertStringNotContainsString('71111111', json_encode($evento));
        $this->assertStringNotContainsString('Direccion ficticia', json_encode($evento));
    }

    public function test_emergency_and_preferences_advance_parent_and_preserve_privacy_object(): void
    {
        [$cookies, $f] = $this->titular();
        $futuro = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(999999);
        DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->update(['actualizado_en' => $futuro->format('Y-m-d H:i:s.uP')]);
        $f['actualizado_en'] = $futuro->format('Y-m-d\TH:i:s.u\Z');
        $r = $this->editar($cookies, $f, ['recordatorios_habilitados' => false])->assertOk()->assertJsonPath('data.recordatorios_habilitados', false);
        $this->assertSame($futuro->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z'), $r->json('data.actualizado_en'));
        $this->assertSame('{}', DB::table('preferencias_paciente')->value('preferencias_privacidad'));
        $this->editar($cookies, $f, ['recordatorios_habilitados' => true])->assertConflict();
        DB::table('preferencias_paciente')->update(['preferencias_privacidad' => '{"conservada":true}']);
        $r = $this->editar($cookies, $r->json('data'), ['recordatorios_habilitados' => true,
            'contacto_emergencia' => ['nombre' => 'Familiar Ficticio', 'parentesco' => 'Familiar', 'telefono' => '001']])->assertOk();
        $this->assertSame(['conservada' => true], json_decode(DB::table('preferencias_paciente')->value('preferencias_privacidad'), true));
        $r = $this->editar($cookies, $r->json('data'), ['contacto_emergencia' => ['nombre' => 'Familiar Ficticio', 'parentesco' => 'Familiar', 'telefono' => '1']])->assertOk()
            ->assertJsonPath('data.contacto_emergencia.telefono', '1');
        $r = $this->editar($cookies, $r->json('data'), ['contacto_emergencia' => null])->assertOk()->assertJsonPath('data.contacto_emergencia', null);
        $eventos = DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')->count();
        $this->editar($cookies, $r->json('data'), ['recordatorios_habilitados' => true, 'contacto_emergencia' => null])->assertOk()
            ->assertJsonPath('data.actualizado_en', $r->json('data.actualizado_en'));
        $this->assertSame($eventos, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')->count());
        $this->assertSame(1, DB::table('preferencias_paciente')->count());
    }

    public function test_audit_failure_rolls_back_contact_emergency_and_new_preference(): void
    {
        [$cookies, $f] = $this->titular();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('pacienteModificado')->once()->andThrow(new RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->editar($cookies, $f, ['telefono' => '71111111', 'recordatorios_habilitados' => false,
            'contacto_emergencia' => ['nombre' => 'Familiar Ficticio', 'parentesco' => 'Familiar', 'telefono' => '72222222']])->assertStatus(500);
        $this->assertSame($f, $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertOk()->json('data'));
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        $this->assertSame(0, DB::table('contactos_emergencia')->count());
    }

    public static function camposInvalidos(): array
    {
        return [['id_paciente', 1], ['id_usuario', 1], ['documento_identidad', 'CI-OTRO'], ['nombres', 'Otro'],
            ['apellidos', 'Otros'], ['fecha_nacimiento', '2000-01-01'], ['sexo', 'Otro'], ['activo', true],
            ['correo_acceso', 'otro@example.test'], ['password', 'No permitido'], ['preferencias_privacidad', ['arbitraria' => true]],
            ['antecedentes', 'No permitido'], ['recordatorios_habilitados', null], ['telefono', null],
            ['contacto_emergencia', ['nombre' => 'Incompleto']], ['contacto_emergencia', ['nombre' => 'Ficticio', 'parentesco' => 'Familiar', 'telefono' => '1', 'id_paciente' => 2]],
            ['actualizado_en_original', '2026-10-20T00:00:00.000Z']];
    }

    #[DataProvider('camposInvalidos')]
    public function test_rejects_identity_selectors_clinical_or_invalid_fields(string $campo, mixed $valor): void
    {
        [$cookies, $f] = $this->titular();
        $this->editar($cookies, $f, [$campo => $valor])->assertUnprocessable();
        $this->assertSame($f, $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertOk()->json('data'));
    }

    public function test_requires_session_csrf_and_snapshot_and_rejects_read_selector(): void
    {
        [$cookies, $f, $usuario] = $this->titular();
        $anonimas = $this->csrfAuth();
        $this->navegador($anonimas, 'GET', '/api/mi-perfil')->assertUnauthorized();
        $this->editar($anonimas, $f, ['telefono' => '71111111'])->assertUnauthorized();
        $this->navegador($cookies, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $f['actualizado_en']], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'PATCH', '/api/mi-perfil', ['telefono' => '71111111'])->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/mi-perfil?id_paciente=2')->assertUnprocessable();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->editar($cookies, $f, ['telefono' => '71111111'])->assertUnauthorized();
    }

    #[DataProvider('otrosRoles')]
    public function test_other_roles_cannot_read_or_modify_patient_profile(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertForbidden();
        $this->navegador($cookies, 'PATCH', '/api/mi-perfil', ['telefono' => '71111111'])->assertForbidden();
    }

    public static function otrosRoles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }
}
