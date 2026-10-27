<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class GestionCitaIntegracionTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function preparar(string $rol): array
    {
        // Solo las identidades previamente verificadas son fixtures; la preparación funcional usa APIs reales.
        $admin = $this->usuarioAuth();
        $administracion = $this->csrfAuth();
        $this->loginAuth($administracion, $admin)->assertOk();
        $especialidad = $this->navegador($administracion, 'POST', '/api/especialidades', ['nombre' => 'Gestión integración ficticia'])
            ->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($administracion, 'POST', '/api/medicos', ['registro_profesional' => 'GESTION-FICTICIA',
            'nombres' => 'Profesional', 'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'gestion@example.test',
            'especialidades' => [$especialidad]])->assertCreated()->json('data.id_medico');
        $sala = $this->navegador($administracion, 'POST', '/api/consultorios', ['codigo_consultorio' => 'GES-INT', 'nombre' => 'Sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($administracion, 'GET', '/api/medicos/'.$medico.'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $huella = $this->navegador($administracion, 'POST', '/api/medicos/'.$medico.'/consultorio/impacto', $seleccion)
            ->assertOk()->json('data.huella_impacto');
        $asignacion = $this->navegador($administracion, 'PUT', '/api/medicos/'.$medico.'/consultorio',
            [...$seleccion, 'huella_impacto' => $huella, 'confirmar_traslados' => false])->assertOk()->json('data');
        $this->navegador($administracion, 'POST', '/api/medicos/'.$medico.'/horarios', ['dia_semana' => 7,
            'hora_inicio' => '08:00:01', 'hora_fin' => '10:00:01', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $paciente = $this->navegador($cookies, 'POST', '/api/pacientes', ['nombres' => 'Paciente', 'apellidos' => 'Gestión sin CI',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'posibles_duplicados_revisados' => true])
            ->assertCreated()->json('data.id_paciente');
        $criterios = ['id_especialidad' => $especialidad, 'id_medico' => $medico, 'id_paciente' => $paciente, 'fecha' => '2026-11-01'];
        [$cita, $payload] = $this->reservar($cookies, $criterios);

        return compact('administracion', 'cookies', 'actor', 'paciente', 'medico', 'especialidad', 'sala', 'criterios', 'cita', 'payload');
    }

    private function reservar(array &$cookies, array $criterios): array
    {
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($criterios))
            ->assertOk()->json('data.turnos.0');
        $motivo = 'MOTIVO-RESERVA-FICTICIO-PRIVADO';
        $propuesta = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$criterios,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => $motivo])
            ->assertOk()->json('data.propuesta');
        $payload = ['propuesta' => $propuesta, 'motivo_breve' => $motivo];

        return [$this->navegador($cookies, 'POST', '/api/citas', $payload)->assertCreated()->json('data'), $payload];
    }

    private function originales(array $vista): array
    {
        return ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en']];
    }

    private function alternativas(array &$cookies, string $id): array
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')
            ->assertOk()->json('data');
    }

    private function reprogramar(array &$cookies, string $id, array $vista): array
    {
        $datos = [...$this->originales($vista['cita_original']), 'fecha' => '2026-11-01',
            'hora_inicio' => $vista['turnos'][0]['hora_inicio'], 'revision_turno' => $vista['turnos'][0]['revision_turno']];
        $resultado = $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos)->assertOk()->json('data');

        return [$resultado, $datos];
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_real_booking_management_reconciliation_cancellation_history_and_reuse(string $rol): void
    {
        $r = $this->preparar($rol);
        $cookies = $r['cookies'];
        $admin = $r['administracion'];
        $id = $r['cita']['id_cita'];
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        // Avisos preparados únicamente para probar invalidación; no acredita generador CU-28.
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        $config = $this->navegador($admin, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 20,
            'actualizado_en_original' => $config['actualizado_en']])->assertOk();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->assertJsonPath('data.acciones.reprogramar', true);
        [$reprogramada, $datos] = $this->reprogramar($cookies, $id, $this->alternativas($cookies, $id));
        $this->assertSame(20, $reprogramada['duracion_minutos']);
        $this->assertSame(2, $reprogramada['version_programacion']);
        $this->assertNotSame($r['cita']['inicio_at'], $reprogramada['inicio_at']);
        $this->assertNotNull(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'));
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos)->assertConflict()
            ->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->navegador($cookies, 'GET', '/api/agenda?'.http_build_query(['id_medico' => $r['medico'], 'fecha' => '2026-11-01']))
            ->assertOk()->assertJsonPath('data.0.inicio_at', $reprogramada['inicio_at']);
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'].'/citas?periodo=todas')->assertOk()
            ->assertJsonPath('data.0.duracion_minutos', 20);
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.inicio_at', $reprogramada['inicio_at']);
        // La respuesta de escritura puede perderse: el GET posterior consulta estado, no reenvía PATCH.
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertUnauthorized();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $r['actor'])->assertOk();
        $gestion = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()
            ->assertJsonPath('data.ultimo_cambio.operacion', 'REPROGRAMACION')->json('data');
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $r['payload']['propuesta']])->assertOk()
            ->assertJsonPath('data.cita.version_programacion', 2);
        $this->navegador($cookies, 'POST', '/api/citas', $r['payload'])->assertOk()->assertJsonPath('meta.repetida', true)
            ->assertJsonPath('data.inicio_at', $reprogramada['inicio_at']);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 2, 'generado_en' => now(), 'leido_en' => now()]);
        $motivo = 'MOTIVO-CANCELACION-FICTICIO-PRIVADO';
        $cancelacion = [...$this->originales($gestion), 'motivo' => $motivo];
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $cancelacion)->assertOk()
            ->assertJsonPath('data.estado', 'Cancelada')->assertJsonPath('data.version_programacion', 2)->assertDontSee($motivo);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $cancelacion)->assertConflict();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->assertJsonPath('data.acciones.cancelar', false)
            ->assertJsonPath('data.ultimo_cambio.operacion', 'CANCELACION')->assertDontSee($motivo);
        $this->navegador($cookies, 'POST', '/api/citas', $r['payload'])->assertOk()->assertJsonPath('meta.repetida', true)
            ->assertJsonPath('data.estado', 'Cancelada');
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'].'/citas?periodo=todas&estado=Cancelada')->assertOk()
            ->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('data.0.inicio_at', $reprogramada['inicio_at']);
        $this->assertSame(0, DB::table('recordatorios')->where('id_cita', $id)->whereNull('invalidado_en')->count());
        $this->assertSame(2, DB::table('cambios_cita')->where('id_cita', $id)->count());
        foreach (['CITA_PROGRAMADA', 'CITA_REPROGRAMADA', 'CITA_CANCELADA'] as $tipo) {
            $evento = $this->navegador($admin, 'GET', '/api/bitacora?'.http_build_query(['tipo_evento' => $tipo,
                'entidad_referida' => 'Cita', 'registro_referido' => $id]))->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
            $this->navegador($admin, 'GET', '/api/bitacora/'.$evento)->assertOk()
                ->assertJsonPath('data.id_usuario_responsable', (string) $r['actor']->getKey())
                ->assertJsonPath('data.rol_responsable', $rol)->assertDontSee($motivo)
                ->assertDontSee($r['payload']['motivo_breve'])->assertDontSee($r['payload']['propuesta']);
        }
        [$nueva] = $this->reservar($cookies, $r['criterios']);
        $this->assertNotSame($id, $nueva['id_cita']);
        $this->assertSame($r['cita']['inicio_at'], $nueva['inicio_at']);
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach (['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'creada_por', 'creada_en', 'id_propuesta_reserva', 'motivo_breve'] as $campo) {
            $this->assertSame($antes[$campo], $despues[$campo]);
        }
        $this->assertSame(2, DB::table('citas')->count());
        $this->assertSame(0, DB::table('consultas_medicas')->count());
    }

    #[DataProvider('operadores')]
    public function test_real_location_transfer_and_inactive_chart_preserve_same_cita_and_stale_guards(string $rol): void
    {
        $r = $this->preparar($rol);
        $cookies = $r['cookies'];
        $admin = $r['administracion'];
        $id = $r['cita']['id_cita'];
        $original = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $paciente = $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'])->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$r['paciente'].'/estado', ['activo' => false,
            'motivo' => 'Cambio administrativo ficticio', 'actualizado_en_original' => $paciente['actualizado_en']])->assertOk();
        $sala = $this->navegador($admin, 'POST', '/api/consultorios', ['codigo_consultorio' => 'GES-INT-2', 'nombre' => 'Segunda sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($admin, 'GET', '/api/medicos/'.$r['medico'].'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $impacto = $this->navegador($admin, 'POST', '/api/medicos/'.$r['medico'].'/consultorio/impacto', $seleccion)
            ->assertOk()->assertJsonPath('data.totales.conflictos', 0)->json('data');
        $this->navegador($admin, 'PUT', '/api/medicos/'.$r['medico'].'/consultorio', [...$seleccion,
            'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true])->assertOk();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($original))->assertConflict()
            ->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $vista = $this->alternativas($cookies, $id);
        $this->assertSame($sala, $vista['cita_original']['id_consultorio']);
        $this->assertSame(1, $vista['cita_original']['version_programacion']);
        [$reprogramada] = $this->reprogramar($cookies, $id, $vista);
        $this->assertSame($sala, $reprogramada['id_consultorio']);
        $turnos = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($r['criterios']))
            ->assertOk()->assertJsonPath('data.paciente_reservable', false)->json('data.turnos');
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$r['criterios'],
            'hora_inicio' => $turnos[0]['hora_inicio'], 'revision_turno' => $turnos[0]['revision_turno']])->assertConflict()
            ->assertJsonPath('error.code', 'CITA_PACIENTE_NO_DISPONIBLE');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($reprogramada))->assertOk()
            ->assertJsonPath('data.estado', 'Cancelada');
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(3, DB::table('cambios_cita')->where('id_cita', $id)->count());
        $this->assertFalse(DB::table('pacientes')->where('id_paciente', $r['paciente'])->value('activo'));
        $this->assertSame(0, DB::table('recordatorios')->count());
    }
}
