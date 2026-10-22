<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Recordatorio;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class PlanificacionIntegracionTest extends TestCase
{
    use AutenticacionFixtures, LaboratorioFixtures;

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
        CarbonImmutable::setTestNow('2026-10-21T12:00:00Z');
    }

    private function instalacionAdministrativa(): array
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $especialidad = $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Integración ficticia'])->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($cookies, 'POST', '/api/medicos', ['registro_profesional' => 'REG-INTEGRACION', 'nombres' => 'Profesional',
            'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'integracion@example.test', 'especialidades' => [$especialidad]])->assertCreated()->json('data');
        $salas = [];
        foreach (['ORIGEN', 'DESTINO'] as $codigo) {
            $salas[] = $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => $codigo, 'nombre' => 'Sala ficticia '.$codigo])->assertCreated()->json('data');
        }
        $base = '/api/medicos/'.$medico['id_medico'];
        $asignacion = $this->asignar($cookies, $base, $salas[0]['id_consultorio']);
        $horario = $this->navegador($cookies, 'POST', $base.'/horarios', ['dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00',
            'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated()->json('data');

        return [$cookies, $admin, $base, $medico, $salas, $especialidad, $horario];
    }

    private function asignar(array &$cookies, string $base, string $sala, bool $confirmar = false): array
    {
        $version = $this->navegador($cookies, 'GET', $base.'/consultorio')->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->assertJsonPath('data.puede_confirmar', true)->json('data.huella_impacto');

        return $this->navegador($cookies, 'PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $huella, 'confirmar_traslados' => $confirmar])->assertOk()->json('data');
    }

    public function test_full_administrative_journey_preserves_appointments_notices_and_all_audit_producers(): void
    {
        [$cookies, $admin, $base, $medico, $salas, $especialidad, $h] = $this->instalacionAdministrativa();
        $h = $this->navegador($cookies, 'PATCH', $base.'/horarios/'.$h['id_horario'], ['hora_fin' => '12:50:00', 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        foreach ([false, true] as $activo) {
            $h = $this->navegador($cookies, 'PATCH', $base.'/horarios/'.$h['id_horario'].'/estado', ['activo' => $activo, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        }
        $recepcion = $this->usuarioAuth('Recepcionista');
        $recepcionCookies = $this->csrfAuth();
        $this->loginAuth($recepcionCookies, $recepcion)->assertOk();
        $paciente = $this->navegador($recepcionCookies, 'POST', '/api/pacientes', ['documento_identidad' => 'CI-FICTICIO-INTEGRACION',
            'nombres' => 'Paciente', 'apellidos' => 'De integración', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'])->assertCreated()->json('data.id_paciente');
        $r = ['patient' => $paciente, 'doctors' => [$medico['id_medico']], 'rooms' => [$salas[0]['id_consultorio']], 'specialty' => $especialidad, 'actor' => $recepcion->getKey()];
        $ids = [];
        foreach (['Programada', 'Confirmada'] as $i => $estado) {
            $inicio = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addWeeks($i);
            // CU-14/CU-28 aún sin endpoints: solo sus filas ficticias existentes.
            $ids[] = $id = DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado, 'inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15)]), 'id_cita');
            DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => '2026-10-21T11:00:00Z', 'leido_en' => '2026-10-21T11:01:00Z']);
        }
        $antes = DB::table('citas')->orderBy('id_cita')->get()->map(fn ($fila) => (array) $fila)->all();
        $avisos = DB::table('recordatorios')->orderBy('id_recordatorio')->get()->toJson();
        $config = $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        $this->navegador($cookies, 'POST', '/api/configuracion-citas/impacto', ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']])->assertOk()->assertJsonPath('data.puede_confirmar', true);
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']])->assertOk();
        $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->assertJsonPath('data.duracion_turno_minutos', 20)->assertJsonPath('data.elementos.0.turnos_completos', 14);
        $asignacion = $this->asignar($cookies, $base, $salas[1]['id_consultorio'], confirmar: true);
        $this->assertSame(2, $asignacion['citas_trasladadas']);
        $despues = DB::table('citas')->orderBy('id_cita')->get()->map(fn ($fila) => (array) $fila)->all();
        foreach ($antes as $i => $fila) {
            $this->assertSame((int) $salas[1]['id_consultorio'], $despues[$i]['id_consultorio']);
            foreach (['id_consultorio', 'consultorio_atencion', 'actualizada_en'] as $campo) {
                unset($fila[$campo], $despues[$i][$campo]);
            }
            $this->assertSame($fila, $despues[$i]);
        }
        $this->assertSame($avisos, DB::table('recordatorios')->orderBy('id_recordatorio')->get()->toJson());
        foreach (Recordatorio::query()->with('cita')->get() as $aviso) {
            $this->assertSame((int) $salas[1]['id_consultorio'], $aviso->cita->id_consultorio);
            $this->assertSame(1, $aviso->version_programacion);
        }
        $historial = DB::table('cambios_cita')->orderBy('id_cambio_cita')->get()->toJson();
        $this->assertSame(2, DB::table('cambios_cita')->count());
        $this->navegador($cookies, 'PATCH', '/api/consultorios/'.$salas[1]['id_consultorio'], ['nombre' => 'Etiqueta nueva', 'actualizado_en_original' => $salas[1]['actualizado_en']])->assertOk();
        $this->asignar($cookies, $base, $salas[1]['id_consultorio']);
        $this->assertSame($historial, DB::table('cambios_cita')->orderBy('id_cambio_cita')->get()->toJson());
        $this->assertSame('DESTINO — Sala ficticia DESTINO', DB::table('citas')->where('id_cita', $ids[0])->value('consultorio_atencion'));
        $this->navegador($cookies, 'PATCH', '/api/consultorios/'.$salas[0]['id_consultorio'].'/estado', ['activo' => false, 'actualizado_en_original' => $salas[0]['actualizado_en']])->assertOk();
        $tipos = [];
        foreach (['CONSULTORIOS', 'HORARIOS'] as $modulo) {
            $eventos = $this->navegador($cookies, 'GET', '/api/bitacora?modulo='.$modulo.'&per_page=100')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
            foreach ($eventos as $evento) {
                $tipos[] = $evento['tipo_evento'];
                $detalle = $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento['id_evento'])->assertOk()->json('data');
                $this->assertSame((string) $admin->getKey(), $detalle['id_usuario_responsable']);
                $this->assertSame('EXITO', $detalle['resultado']);
                $this->assertStringNotContainsString('CI-FICTICIO', json_encode($detalle));
            }
        }
        $this->assertEqualsCanonicalizing(['CONSULTORIO_CREADO', 'CONSULTORIO_ACTUALIZADO', 'CONSULTORIO_ESTADO_CAMBIADO', 'MEDICO_CONSULTORIO_CAMBIADO',
            'HORARIO_CREADO', 'HORARIO_ACTUALIZADO', 'HORARIO_ESTADO_CAMBIADO', 'DURACION_CITAS_CAMBIADA'], array_values(array_unique($tipos)));
        $this->navegador($recepcionCookies, 'GET', '/api/bitacora')->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($cookies, 'GET', $base.'/horarios')->assertUnauthorized();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->navegador($cookies, 'GET', $base.'/consultorio')->assertOk()->assertJsonPath('data.consultorio.id_consultorio', $salas[1]['id_consultorio']);
    }

    public function test_professional_activity_schedule_activity_and_shared_room_remain_independent_through_api(): void
    {
        [$cookies, , $base, $medico, $salas, $especialidad, $h] = $this->instalacionAdministrativa();
        $inactivo = $this->navegador($cookies, 'PATCH', $base.'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->assertJsonPath('data.elementos.0.activo', true);
        $this->navegador($cookies, 'PATCH', '/api/consultorios/'.$salas[0]['id_consultorio'].'/estado', ['activo' => false, 'actualizado_en_original' => $salas[0]['actualizado_en']])->assertConflict();
        $h = $this->navegador($cookies, 'PATCH', $base.'/horarios/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $inactivo['actualizado_en']])->assertOk()->json('data');
        $m = $this->navegador($cookies, 'PATCH', $base.'/estado', ['activo' => true, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->assertJsonPath('data.elementos.0.activo', false)->assertJsonPath('data.actualizado_en', $m['actualizado_en']);
        $otro = $this->navegador($cookies, 'POST', '/api/medicos', ['registro_profesional' => 'COMPARTIDO', 'nombres' => 'Segundo', 'apellidos' => 'Ficticio', 'telefono' => '70000000',
            'correo_contacto' => 'compartido@example.test', 'especialidades' => [$especialidad]])->assertCreated()->json('data');
        $baseOtro = '/api/medicos/'.$otro['id_medico'];
        $asignacion = $this->asignar($cookies, $baseOtro, $salas[0]['id_consultorio']);
        $this->navegador($cookies, 'POST', $baseOtro.'/horarios', ['dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();
        $this->navegador($cookies, 'PATCH', $base.'/horarios/'.$h['id_horario'].'/estado', ['activo' => true, 'actualizado_en_original' => $m['actualizado_en']])->assertConflict()->assertJsonPath('error.code', 'PLANIFICACION_CONFLICTO');
        $nuevaSala = $this->asignar($cookies, $base, $salas[1]['id_consultorio']);
        $this->navegador($cookies, 'PATCH', $base.'/horarios/'.$h['id_horario'].'/estado', ['activo' => true, 'actualizado_en_original' => $nuevaSala['actualizado_en']])->assertOk();
        $this->navegador($cookies, 'GET', $base)->assertOk()->assertJsonPath('data.activo', true)->assertJsonPath('data.cuenta_vinculada', false);
        $this->assertSame(0, DB::table('citas')->count());
    }

    public function test_documented_care_and_private_laboratory_survive_rejected_then_historical_assignment_change(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $contexto = $this->laboratorioContext();
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($contexto));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($contexto, ['alergias' => 'Antecedente ficticio privado']));
        $versionResultado = $this->resultadoEnRevision($contexto);
        DB::table('revisiones_resultado')->insert($this->revisionRow($contexto, $versionResultado));
        DB::table('horarios_atencion')->insert(['id_medico' => $contexto['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $antes = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'solicitudes_laboratorio', 'documentos_laboratorio', 'versiones_resultado', 'revisiones_resultado'] as $tabla) {
            $antes[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $base = '/api/medicos/'.$contexto['doctors'][0];
        $version = $this->navegador($cookies, 'GET', $base.'/consultorio')->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => (string) $contexto['rooms'][1], 'actualizado_en_original' => $version];
        $impacto = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->assertJsonPath('data.puede_confirmar', false)
            ->assertDontSee('Antecedente ficticio privado')->assertDontSee('ruta_privada')->json('data');
        $this->navegador($cookies, 'PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true])->assertConflict()->assertJsonPath('error.code', 'ATENCION_NO_TRASLADABLE');
        CarbonImmutable::setTestNow('2026-11-02T12:00:00Z');
        // El cambio de fecha caduca la sesión: nuevo login real antes de continuar.
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $this->asignar($cookies, $base, (string) $contexto['rooms'][1]);
        foreach ($antes as $tabla => $filas) {
            $this->assertSame($filas, DB::table($tabla)->get()->toJson(), $tabla);
        }
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $evento = $this->navegador($cookies, 'GET', '/api/bitacora?tipo_evento=MEDICO_CONSULTORIO_CAMBIADO')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento)->assertOk()->assertJsonPath('data.cambios_seguros.citas_trasladadas', 0)
            ->assertDontSee('Antecedente ficticio privado')->assertDontSee('motivo_consulta')->assertDontSee('ruta_privada');
    }
}
