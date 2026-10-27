<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Bitacora;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class GestionCitaSeguridadTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
    }

    public function test_real_restricted_session_cannot_use_any_of_the_four_management_endpoints(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $r = $this->citaResources();
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $target = $this->usuarioAuth('Recepcionista');
        $temporal = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $temporal)->assertOk();
        $before = DB::table('citas')->where('id_cita', $id)->first();
        foreach (['gestion', 'disponibilidad-reprogramacion', 'reprogramacion', 'cancelacion'] as $accion) {
            $this->navegador($restricted, in_array($accion, ['gestion', 'disponibilidad-reprogramacion'], true) ? 'GET' : 'PATCH',
                '/api/citas/'.$id.'/'.$accion)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        }
        $this->assertEquals($before, DB::table('citas')->where('id_cita', $id)->first());
        $this->assertSame(0, DB::table('cambios_cita')->where('id_cita', $id)->count());
    }

    public function test_management_head_and_get_are_private_and_read_only_without_disclosing_creation_secrets(): void
    {
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $this->usuarioAuth())->assertOk();
        $r = $this->citaResources();
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['motivo_breve' => 'MOTIVO-CLINICO-NO-PUBLICO']), 'id_cita');
        $before = DB::table('citas')->where('id_cita', $id)->first();
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id.'/gestion')->assertOk()->assertContent('')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->assertDontSee('MOTIVO-CLINICO-NO-PUBLICO')
            ->assertJsonMissingPath('data.creada_por')->assertJsonMissingPath('data.id_propuesta_reserva')
            ->assertJsonMissingPath('data.motivo_breve')->assertJsonMissingPath('data.motivo');
        $this->assertEquals($before, DB::table('citas')->where('id_cita', $id)->first());
        $this->assertSame(0, DB::table('cambios_cita')->where('id_cita', $id)->count());
    }

    public function test_audit_resource_rejects_unexpected_motive_versions_and_references(): void
    {
        foreach (['CITA_CANCELADA', 'CITA_REPROGRAMADA'] as $tipo) {
            $evento = new Bitacora;
            $evento->forceFill(['id_evento' => 1, 'tipo_evento' => $tipo, 'version_formato' => 1,
                'ocurrido_en' => now(), 'registrado_en' => now(), 'referencias' => [
                    'id_cita' => '1', 'id_medico' => '-1', 'motivo' => 'MOTIVO-NO-PUBLICO', 'revision_turno' => 'SECRETO'],
                'cambios_seguros' => ['antes' => ['estado' => 'Confirmada', 'version_programacion' => 1, 'motivo' => 'MOTIVO-NO-PUBLICO'],
                    'despues' => ['estado' => $tipo === 'CITA_CANCELADA' ? 'Cancelada' : 'Programada',
                        'version_programacion' => 2147483648, 'diagnostico' => 'SECRETO']],
                'metadatos_seguros' => ['motivo' => 'MOTIVO-NO-PUBLICO']]);
            $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
            $this->assertSame('1', $salida['referencias']->id_cita);
            $this->assertObjectNotHasProperty('id_medico', $salida['referencias']);
            $this->assertObjectNotHasProperty('version_programacion', $salida['cambios_seguros']->despues);
            $this->assertStringNotContainsString('SECRETO', json_encode($salida));
            $this->assertStringNotContainsString('MOTIVO-NO-PUBLICO', json_encode($salida));
        }
    }
}
