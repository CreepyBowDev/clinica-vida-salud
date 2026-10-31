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

class AsistenciaIntegracionTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_auth_'.strtolower(Str::random(16));
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
        // Identidades verificadas y ficha ya vinculada son fixtures, no un nuevo flujo CU-04.
        foreach (['Administrador', 'Recepcionista', 'Paciente'] as $rol) {
            $usuarios[$rol] = $this->usuarioAuth($rol);
            $cookies[$rol] = $this->csrfAuth();
            $this->loginAuth($cookies[$rol], $usuarios[$rol])->assertOk();
        }
        $admin = &$cookies['Administrador'];
        $especialidad = $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => 'Asistencia integral ficticia'])
            ->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($admin, 'POST', '/api/medicos', ['registro_profesional' => 'ASISTENCIA-FICTICIA',
            'nombres' => 'Profesional', 'apellidos' => 'Asistencia Ficticia', 'telefono' => '70000000',
            'correo_contacto' => 'contacto.asistencia@example.test', 'especialidades' => [$especialidad]])->assertCreated()->json('data.id_medico');
        $sala = $this->navegador($admin, 'POST', '/api/consultorios', ['codigo_consultorio' => 'ASIS-INT', 'nombre' => 'Sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($admin, 'GET', '/api/medicos/'.$medico.'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $huella = $this->navegador($admin, 'POST', '/api/medicos/'.$medico.'/consultorio/impacto', $seleccion)->assertOk()->json('data.huella_impacto');
        $asignacion = $this->navegador($admin, 'PUT', '/api/medicos/'.$medico.'/consultorio',
            [...$seleccion, 'huella_impacto' => $huella, 'confirmar_traslados' => false])->assertOk()->json('data');
        $this->navegador($admin, 'POST', '/api/medicos/'.$medico.'/horarios', ['dia_semana' => 7,
            'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();
        // Alta profesional, invitación/aceptación y login reales, correo siempre simulado.
        $this->navegador($admin, 'POST', '/api/personal', ['rol' => 'Médico', 'id_medico' => $medico,
            'correo_acceso' => 'acceso.asistencia@example.test'])->assertCreated();
        parse_str(parse_url(Mail::sent(InvitacionPersonalMail::class)->sole()->enlace, PHP_URL_FRAGMENT), $fragmento);
        $usuarios['Médico'] = Usuario::query()->where('correo_acceso', 'acceso.asistencia@example.test')->sole();
        $cookies['Médico'] = $this->csrfAuth();
        $this->navegador($cookies['Médico'], 'POST', '/api/auth/invitaciones/aceptar', ['token' => $fragmento['token'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $this->loginAuth($cookies['Médico'], $usuarios['Médico'])->assertOk();
        $paciente = (string) $usuarios['Paciente']->paciente->getKey();
        $ficha = $this->navegador($admin, 'GET', '/api/pacientes/'.$paciente)->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/pacientes/'.$paciente.'/estado', ['activo' => true,
            'motivo' => 'Habilitación administrativa ficticia', 'actualizado_en_original' => $ficha['actualizado_en']])->assertOk();
        $criterios = ['id_medico' => $medico, 'id_especialidad' => $especialidad, 'fecha' => '2026-11-01',
            ...($reservante === 'Paciente' ? [] : ['id_paciente' => $paciente])];
        $turno = $this->navegador($cookies[$reservante], 'GET', '/api/reservas/disponibilidad?'.http_build_query($criterios))
            ->assertOk()->json('data.turnos.0');
        $propuesta = $this->navegador($cookies[$reservante], 'POST', '/api/reservas/propuesta', [...$criterios,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => 'MOTIVO-ASISTENCIA-PRIVADO'])
            ->assertOk()->json('data.propuesta');
        $cita = $this->navegador($cookies[$reservante], 'POST', '/api/citas', ['propuesta' => $propuesta,
            'motivo_breve' => 'MOTIVO-ASISTENCIA-PRIVADO'])->assertCreated()->assertJsonPath('data.estado', 'Programada')->json('data');

        return compact('usuarios', 'cookies', 'medico', 'paciente', 'sala', 'especialidad', 'cita');
    }

    private function vista(array &$cookies, string $id): array
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('MOTIVO-ASISTENCIA-PRIVADO')->json('data');
    }

    private function originales(array $vista): array
    {
        return ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en']];
    }

    private function comprobarLecturas(array &$r, string $estado): void
    {
        $id = $r['cita']['id_cita'];
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $cookies = &$r['cookies'][$rol];
            $uri = match ($rol) {
                'Médico' => '/api/agenda?fecha=2026-11-01',
                'Paciente' => '/api/mis-citas?periodo=todas',
                default => '/api/agenda?id_medico='.$r['medico'].'&fecha=2026-11-01',
            };
            $this->navegador($cookies, 'GET', $uri)->assertOk()->assertJsonPath('data.0.id_cita', $id)
                ->assertJsonPath('data.0.estado', $estado)->assertDontSee('MOTIVO-ASISTENCIA-PRIVADO');
            $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.estado', $estado)
                ->assertJsonPath('data.medico.id_medico', $r['medico']);
            if (in_array($rol, ['Administrador', 'Recepcionista'], true)) {
                $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'].'/citas?periodo=todas&estado='.$estado)
                    ->assertOk()->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('data.0.estado', $estado);
            }
        }
    }

    private function auditoria(array &$cookies, string $id, string $tipo, string $actor, string $rol, string $medico): void
    {
        $evento = $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query(['tipo_evento' => $tipo,
            'entidad_referida' => 'Cita', 'registro_referido' => $id]))->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento)->assertOk()
            ->assertJsonPath('data.id_usuario_responsable', $actor)->assertJsonPath('data.rol_responsable', $rol)
            ->assertJsonPath('data.referencias.id_medico', $medico)->assertDontSee('MOTIVO-ASISTENCIA-PRIVADO')
            ->assertDontSee('atencion_realizada')->assertDontSee('presencia_verificada')->assertDontSee('ausencia_verificada');
    }

    public static function atenciones(): array
    {
        $casos = [];
        foreach (['Administrador', 'Recepcionista', 'Paciente'] as $reservante) {
            foreach (['Administrador', 'Médico'] as $actor) {
                foreach ([true, false] as $llegada) {
                    $casos[] = [$reservante, $actor, $llegada];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('atenciones')]
    public function test_real_reservation_invitation_arrival_attention_history_and_reconciliation(string $reservante, string $actor, bool $llegada): void
    {
        $r = $this->preparar($reservante);
        $id = $r['cita']['id_cita'];
        $antes = (array) DB::table('citas')->where('id_cita', $id)->sole();
        // Solo avisos son fixtures: no generador ni consulta de CU-28.
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => now()]);
        $this->comprobarLecturas($r, 'Programada');
        $recepcion = &$r['cookies']['Recepcionista'];
        $admin = &$r['cookies']['Administrador'];
        $vistaInicial = $this->vista($admin, $id);
        $this->navegador($r['cookies']['Paciente'], 'GET', '/api/citas/'.$id.'/asistencia')->assertForbidden();
        $this->navegador($recepcion, 'PATCH', '/api/citas/'.$id.'/atencion', [])->assertForbidden();
        $this->navegador($r['cookies']['Médico'], 'PATCH', '/api/citas/'.$id.'/llegada', [])->assertForbidden();
        if ($llegada) {
            $datosLlegada = [...$this->originales($vistaInicial), 'presencia_verificada' => true];
            $this->navegador($recepcion, 'PATCH', '/api/citas/'.$id.'/llegada', $datosLlegada)->assertOk()
                ->assertJsonPath('data.estado', 'Confirmada')->assertJsonPath('data.version_programacion', 1);
            $this->assertNull(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'));
            $this->comprobarLecturas($r, 'Confirmada');
            $this->auditoria($admin, $id, 'CITA_LLEGADA_CONFIRMADA', (string) $r['usuarios']['Recepcionista']->getKey(), 'Recepcionista', $r['medico']);
            $this->navegador($recepcion, 'PATCH', '/api/citas/'.$id.'/llegada', $datosLlegada)->assertConflict();
        }
        CarbonImmutable::setTestNow($r['cita']['inicio_at']);
        $operador = &$r['cookies'][$actor];
        $vista = $this->vista($operador, $id);
        $this->assertTrue($vista['acciones']['marcar_atendida']);
        $datos = [...$this->originales($vista), 'atencion_realizada' => true];
        $this->navegador($operador, 'PATCH', '/api/citas/'.$id.'/atencion', $datos)->assertOk()
            ->assertJsonPath('data.estado', 'Atendida')->assertJsonPath('data.id_medico', $r['medico']);
        $this->comprobarLecturas($r, 'Atendida');
        $this->auditoria($admin, $id, 'CITA_ATENDIDA', (string) $r['usuarios'][$actor]->getKey(), $actor, $r['medico']);
        $this->assertNotNull(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'));
        $this->assertNotNull(DB::table('recordatorios')->where('id_cita', $id)->value('leido_en'));
        // Pérdida simulada de la respuesta: logout/login y GET, nunca replay automático.
        $this->navegador($operador, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($operador, 'GET', '/api/citas/'.$id.'/asistencia')->assertUnauthorized();
        $operador = $this->csrfAuth();
        $this->loginAuth($operador, $r['usuarios'][$actor])->assertOk();
        $vigente = $this->vista($operador, $id);
        $this->assertSame('Atendida', $vigente['estado']);
        $this->assertSame('ATENCION', $vigente['ultimo_cambio']['operacion']);
        $this->navegador($operador, 'PATCH', '/api/citas/'.$id.'/atencion', $datos)->assertConflict()
            ->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->navegador($operador, 'PATCH', '/api/citas/'.$id.'/atencion', [...$this->originales($vigente), 'atencion_realizada' => true])
            ->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->assertSame($llegada ? 2 : 1, DB::table('cambios_cita')->where('id_cita', $id)->count());
        $despues = (array) DB::table('citas')->where('id_cita', $id)->sole();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['estado', 'actualizada_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $this->assertSame(0, DB::table('consultas_medicas')->count());
        $this->assertSame(0, DB::table('versiones_consulta')->count());
        Mail::assertSentCount(1);
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_arrival_reprogramming_and_absence_follow_same_record(string $rol): void
    {
        $r = $this->preparar($rol);
        $cookies = &$r['cookies'][$rol];
        $admin = &$r['cookies']['Administrador'];
        $id = $r['cita']['id_cita'];
        $vieja = [...$this->originales($this->vista($cookies, $id)), 'presencia_verificada' => true];
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/llegada', $vieja)->assertOk();
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->json('data');
        $nueva = $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', [
            ...$this->originales($vista['cita_original']), 'fecha' => '2026-11-01', 'hora_inicio' => $vista['turnos'][0]['hora_inicio'],
            'revision_turno' => $vista['turnos'][0]['revision_turno']])->assertOk()->assertJsonPath('data.estado', 'Programada')
            ->assertJsonPath('data.version_programacion', 2)->json('data');
        $this->assertNotNull(DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 1)->value('invalidado_en'));
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/llegada', $vieja)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        // La nueva Programada no se confirma automáticamente; ausencia explícita desde el nuevo fin.
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 2, 'generado_en' => now()]);
        CarbonImmutable::setTestNow($r['cita']['fin_at']);
        $datos = [...$this->originales($this->vista($cookies, $id)), 'ausencia_verificada' => true];
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos)->assertConflict()
            ->assertJsonPath('error.code', 'CITA_INASISTENCIA_ANTICIPADA');
        CarbonImmutable::setTestNow($nueva['fin_at']);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos)->assertOk()->assertJsonPath('data.estado', 'Inasistencia')
            ->assertJsonPath('data.version_programacion', 2);
        $this->comprobarLecturas($r, 'Inasistencia');
        $this->auditoria($admin, $id, 'CITA_INASISTENCIA_REGISTRADA', (string) $r['usuarios'][$rol]->getKey(), $rol, $r['medico']);
        $this->assertSame(0, DB::table('recordatorios')->where('id_cita', $id)->whereNull('invalidado_en')->count());
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos)->assertConflict();
        $this->assertSame(3, DB::table('cambios_cita')->where('id_cita', $id)->count());
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(0, DB::table('consultas_medicas')->count());
    }
}
