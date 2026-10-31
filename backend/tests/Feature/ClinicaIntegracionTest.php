<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\shared\utils\InvitacionPersonalMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class ClinicaIntegracionTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_clinica_integracion_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->beforeApplicationDestroyed(function () use ($schema, $anterior): void {
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $anterior]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        });
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            DB::table('roles')->insertOrIgnore(['nombre' => $rol]);
        }
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function preparar(string $reservante): array
    {
        $usuarios = $cookies = [];
        // Solo las identidades verificadas/ficha vinculada son fixtures; resto mediante APIs reales.
        foreach (['Administrador', 'Recepcionista', 'Paciente'] as $rol) {
            $usuarios[$rol] = $this->usuarioAuth($rol);
            $cookies[$rol] = $this->csrfAuth();
            $this->loginAuth($cookies[$rol], $usuarios[$rol])->assertOk();
        }
        $admin = &$cookies['Administrador'];
        $especialidad = $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => 'Clínica integral ficticia'])
            ->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($admin, 'POST', '/api/medicos', ['registro_profesional' => 'CLINICA-FICTICIA',
            'nombres' => 'Profesional', 'apellidos' => 'Clínico Ficticio', 'telefono' => '70000000',
            'correo_contacto' => 'contacto.clinica@example.test', 'especialidades' => [$especialidad]])->assertCreated()->json('data.id_medico');
        $sala = $this->navegador($admin, 'POST', '/api/consultorios', ['codigo_consultorio' => 'CLI-INT', 'nombre' => 'Sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($admin, 'GET', '/api/medicos/'.$medico.'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $huella = $this->navegador($admin, 'POST', '/api/medicos/'.$medico.'/consultorio/impacto', $seleccion)->assertOk()->json('data.huella_impacto');
        $asignacion = $this->navegador($admin, 'PUT', '/api/medicos/'.$medico.'/consultorio',
            [...$seleccion, 'huella_impacto' => $huella, 'confirmar_traslados' => false])->assertOk()->json('data');
        $this->navegador($admin, 'POST', '/api/medicos/'.$medico.'/horarios', ['dia_semana' => 7,
            'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();
        $this->navegador($admin, 'POST', '/api/personal', ['rol' => 'Médico', 'id_medico' => $medico,
            'correo_acceso' => 'acceso.clinica@example.test'])->assertCreated();
        parse_str(parse_url(Mail::sent(InvitacionPersonalMail::class)->sole()->enlace, PHP_URL_FRAGMENT), $fragmento);
        $usuarios['Médico'] = Usuario::query()->where('correo_acceso', 'acceso.clinica@example.test')->sole();
        $cookies['Médico'] = $this->csrfAuth();
        $this->navegador($cookies['Médico'], 'POST', '/api/auth/invitaciones/aceptar', ['token' => $fragmento['token'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $this->loginAuth($cookies['Médico'], $usuarios['Médico'])->assertOk();
        $paciente = (string) $usuarios['Paciente']->paciente->getKey();
        $ficha = $this->navegador($admin, 'GET', '/api/pacientes/'.$paciente)->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/pacientes/'.$paciente.'/estado', ['activo' => true,
            'motivo' => 'Habilitación ficticia', 'actualizado_en_original' => $ficha['actualizado_en']])->assertOk();
        $criterios = ['id_medico' => $medico, 'id_especialidad' => $especialidad, 'fecha' => '2026-11-01',
            ...($reservante === 'Paciente' ? [] : ['id_paciente' => $paciente])];
        $turno = $this->navegador($cookies[$reservante], 'GET', '/api/reservas/disponibilidad?'.http_build_query($criterios))
            ->assertOk()->json('data.turnos.0');
        $propuesta = $this->navegador($cookies[$reservante], 'POST', '/api/reservas/propuesta', [...$criterios,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => 'RESERVA-PRIVADA'])
            ->assertOk()->json('data.propuesta');
        $cita = $this->navegador($cookies[$reservante], 'POST', '/api/citas', ['propuesta' => $propuesta,
            'motivo_breve' => 'RESERVA-PRIVADA'])->assertCreated()->assertJsonPath('data.estado', 'Programada')->json('data');

        return compact('usuarios', 'cookies', 'medico', 'paciente', 'sala', 'especialidad', 'cita');
    }

    private function snapshot(): array
    {
        $r = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios', 'bitacora'] as $tabla) {
            $r[$tabla] = DB::table($tabla)->orderByRaw('1')->get()->toJson();
        }

        return $r;
    }

    public static function recorridos(): array
    {
        $casos = [];
        foreach (['Administrador', 'Recepcionista', 'Paciente'] as $reservante) {
            foreach (['Administrador', 'Médico'] as $alta) {
                foreach (['Administrador', 'Médico'] as $correccion) {
                    foreach ([false, true] as $llegada) {
                        $casos[] = [$reservante, $alta, $correccion, $llegada];
                    }
                }
            }
        }

        return $casos;
    }

    #[DataProvider('recorridos')]
    public function test_real_reservation_clinical_record_versions_antecedents_attention_and_reconciliation(string $reservante, string $alta, string $correccion, bool $llegada): void
    {
        $r = $this->preparar($reservante);
        $id = $r['cita']['id_cita'];
        $uri = '/api/citas/'.$id.'/consulta';
        $historia = '/api/pacientes/'.$r['paciente'].'/historia-clinica';
        $admin = &$r['cookies']['Administrador'];
        $operador = &$r['cookies'][$alta];
        $corrector = &$r['cookies'][$correccion];
        $paciente = &$r['cookies']['Paciente'];
        foreach (['Administrador', 'Médico', 'Paciente'] as $rol) {
            $this->navegador($r['cookies'][$rol], 'GET', $historia)->assertOk()->assertJsonPath('data.antecedentes', null)
                ->assertJsonPath('data.consultas', [])->assertJsonPath('meta.total', 0);
        }
        $this->navegador($r['cookies']['Recepcionista'], 'GET', $historia)->assertForbidden();
        $inicial = $this->navegador($operador, 'GET', $uri)->assertOk()->assertJsonPath('data.consulta', null)
            ->assertJsonPath('data.edicion.acciones.registrar', false)->json('data');
        $datos = ['originales' => $inicial['edicion']['originales'], 'atencion_real_verificada' => true,
            'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'CLINICO-MOTIVO-PRIVADO']];
        $this->navegador($operador, 'POST', $uri, $datos)->assertConflict()->assertJsonPath('error.code', 'CLINICA_ATENCION_ANTICIPADA');
        if ($llegada) {
            $this->navegador($r['cookies']['Recepcionista'], 'PATCH', '/api/citas/'.$id.'/llegada', [
                'version_programacion_original' => $inicial['cita']['version_programacion'],
                'actualizada_en_original' => $inicial['cita']['actualizada_en'], 'presencia_verificada' => true])->assertOk();
        }
        // Inactivar profesional por API no desactiva su cuenta ni elimina atención ya asignada.
        $profesional = $this->navegador($admin, 'GET', '/api/medicos/'.$r['medico'])->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/medicos/'.$r['medico'].'/estado', ['activo' => false,
            'actualizado_en_original' => $profesional['actualizado_en']])->assertOk();
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => now()]);
        CarbonImmutable::setTestNow($r['cita']['inicio_at']);
        $vigente = $this->navegador($operador, 'GET', $uri)->assertOk()->assertJsonPath('data.edicion.acciones.registrar', true)->json('data');
        $datos['originales'] = $vigente['edicion']['originales'];
        $citaAntes = DB::table('citas')->get()->toJson();
        $post = $this->navegador($operador, 'POST', $uri, $datos)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.cita.estado', $llegada ? 'Confirmada' : 'Programada')
            ->assertJsonPath('data.consulta.iniciada_en', null)->assertJsonPath('data.consulta.version_vigente.numero_version', 1)
            ->assertJsonPath('data.consulta.version_vigente.diagnostico', null)->assertJsonPath('data.antecedentes', null)
            ->assertJsonPath('data.consulta.version_vigente.id_autor', (string) $r['usuarios'][$alta]->getKey())->json('data');
        $this->assertSame($citaAntes, DB::table('citas')->get()->toJson());
        $versionInicial = DB::table('versiones_consulta')->get()->toJson();
        $this->assertNull(DB::table('recordatorios')->value('invalidado_en'));
        $patch = ['originales' => $this->navegador($corrector, 'GET', $uri)->assertOk()->json('data.edicion.originales'),
            'atencion_real_verificada' => true, 'consulta' => ['diagnostico' => 'CLINICO-DIAGNOSTICO-CORREGIDO'],
            'antecedentes' => ['alergias' => 'CLINICO-ALERGIA'], 'motivo_cambio' => 'CLINICO-MOTIVO-CORRECCION'];
        $respuesta = $this->navegador($corrector, 'PATCH', $uri, $patch)->assertOk()->assertJsonPath('data.modificado', true)
            ->assertJsonPath('data.consulta.id_consulta', $post['consulta']['id_consulta'])
            ->assertJsonPath('data.consulta.version_vigente.numero_version', 2)->assertJsonPath('data.antecedentes.numero_version', 1)
            ->assertJsonPath('data.consulta.version_vigente.id_autor', (string) $r['usuarios'][$correccion]->getKey())
            ->assertJsonPath('data.antecedentes.id_consulta_origen', $post['consulta']['id_consulta'])->json('data');
        $this->assertSame($versionInicial, DB::table('versiones_consulta')->where('numero_version', 1)->get()->toJson());
        $this->assertSame($citaAntes, DB::table('citas')->get()->toJson());
        $this->navegador($operador, 'POST', $uri, $datos)->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $this->navegador($corrector, 'PATCH', $uri, $patch)->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $patch['originales'] = $respuesta['edicion']['originales'];
        unset($patch['motivo_cambio']);
        $antesNoop = $this->snapshot();
        $this->navegador($corrector, 'PATCH', $uri, $patch)->assertOk()->assertJsonPath('data.modificado', false);
        $this->assertSame($antesNoop, $this->snapshot());
        // CU-19 explícito y separado: solo este paso termina el estado administrativo y retira aviso.
        $this->navegador($corrector, 'PATCH', '/api/citas/'.$id.'/atencion', ['atencion_realizada' => true,
            'version_programacion_original' => $respuesta['cita']['version_programacion'],
            'actualizada_en_original' => $respuesta['cita']['actualizada_en']])->assertOk()->assertJsonPath('data.estado', 'Atendida');
        $this->assertNotNull(DB::table('recordatorios')->value('invalidado_en'));
        $this->assertNotNull(DB::table('recordatorios')->value('leido_en'));
        $this->navegador($corrector, 'PATCH', $uri, $patch)->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $patch = ['originales' => $this->navegador($corrector, 'GET', $uri)->assertOk()->json('data.edicion.originales'),
            'atencion_real_verificada' => true, 'consulta' => ['observaciones' => 'CLINICO-COMPLETADO'], 'motivo_cambio' => 'CLINICO-COMPLETAR-DOCUMENTACION'];
        $this->navegador($corrector, 'PATCH', $uri, $patch)->assertOk()->assertJsonPath('data.consulta.version_vigente.numero_version', 3)
            ->assertJsonPath('data.cita.estado', 'Atendida')->assertJsonPath('data.antecedentes.numero_version', 1);
        $this->navegador($paciente, 'GET', $uri)->assertOk()->assertJsonPath('data.consulta.version_vigente.numero_version', 3)
            ->assertJsonPath('data.consulta.version_vigente.observaciones', 'CLINICO-COMPLETADO')->assertJsonMissingPath('data.edicion')
            ->assertDontSee('id_autor')->assertDontSee('motivo_cambio')->assertDontSee('CLINICO-MOTIVO-CORRECCION');
        $this->navegador($paciente, 'GET', $uri.'/versiones')->assertForbidden();
        $this->navegador($r['cookies']['Recepcionista'], 'GET', $uri)->assertForbidden();
        $this->navegador($paciente, 'PATCH', $uri, $patch)->assertForbidden();
        $this->navegador($r['cookies']['Recepcionista'], 'PATCH', $uri, $patch)->assertForbidden();
        $this->navegador($admin, 'GET', $uri.'/versiones?per_page=1&page=3')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.numero_version', 1)->assertJsonPath('data.0.diagnostico', null);
        $this->navegador($admin, 'GET', $historia.'/antecedentes/versiones')->assertOk()->assertJsonPath('meta.total', 1);
        $this->navegador($paciente, 'GET', $historia)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.antecedentes.alergias', 'CLINICO-ALERGIA');
        $this->navegador($paciente, 'GET', '/api/mis-citas?periodo=todas')->assertOk()->assertJsonPath('data.0.estado', 'Atendida')->assertDontSee('CLINICO-');
        foreach (['CONSULTA_REGISTRADA' => 1, 'CONSULTA_ACTUALIZADA' => 2, 'ANTECEDENTES_ACTUALIZADOS' => 1] as $tipo => $total) {
            $eventos = $this->navegador($admin, 'GET', '/api/bitacora?tipo_evento='.$tipo.'&entidad_referida=Cita&registro_referido='.$id)
                ->assertOk()->assertJsonPath('meta.total', $total)->assertDontSee('CLINICO-')->json('data');
            foreach ($eventos as $evento) {
                $this->navegador($admin, 'GET', '/api/bitacora/'.$evento['id_evento'])->assertOk()
                    ->assertJsonPath('data.id_usuario_responsable', (string) $r['usuarios'][$tipo === 'CONSULTA_REGISTRADA' ? $alta : $correccion]->getKey())
                    ->assertJsonPath('data.referencias.id_medico', $r['medico'])->assertDontSee('CLINICO-');
            }
        }
        // Reconciliar una operación confirmada por GET tras nueva sesión; no corte de red ni replay real.
        $antesRecarga = $this->snapshot();
        $this->navegador($corrector, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($corrector, 'GET', $uri)->assertUnauthorized();
        $corrector = $this->csrfAuth();
        $this->loginAuth($corrector, $r['usuarios'][$correccion])->assertOk();
        $this->navegador($corrector, 'GET', $uri)->assertOk()->assertJsonPath('data.consulta.id_consulta', $post['consulta']['id_consulta'])
            ->assertJsonPath('data.consulta.version_vigente.numero_version', 3)->assertJsonPath('data.cita.estado', 'Atendida');
        // Login/logout generan eventos de seguridad, no nuevas versiones ni eventos clínicos.
        $this->assertDatabaseCount('consultas_medicas', 1);
        $this->assertDatabaseCount('versiones_consulta', 3);
        $this->assertDatabaseCount('versiones_antecedente', 1);
        $this->assertSame($llegada ? 2 : 1, DB::table('cambios_cita')->count());
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios'] as $tabla) {
            $this->assertSame($antesRecarga[$tabla], $this->snapshot()[$tabla]);
        }
        Mail::assertSentCount(1);
    }

    public function test_actual_account_deactivation_and_reactivation_do_not_restore_previous_clinical_session(): void
    {
        $r = $this->preparar('Administrador');
        $uri = '/api/citas/'.$r['cita']['id_cita'].'/consulta';
        $admin = &$r['cookies']['Administrador'];
        $medico = &$r['cookies']['Médico'];
        $usuario = (string) $r['usuarios']['Médico']->getKey();
        $this->navegador($medico, 'GET', $uri)->assertOk();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario.'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($medico, 'GET', $uri)->assertUnauthorized();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario.'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->navegador($medico, 'GET', $uri)->assertUnauthorized();
        $medico = $this->csrfAuth();
        $this->loginAuth($medico, $r['usuarios']['Médico'])->assertOk();
        $this->navegador($medico, 'GET', $uri)->assertOk();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }
}
