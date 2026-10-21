<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\shared\utils\InvitacionPersonalMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class MedicosEspecialidadesIntegracionTest extends TestCase
{
    use AutenticacionFixtures, LaboratorioFixtures;

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
        $archivos = glob(base_path('src/data/database/migrations/*.php'));
        sort($archivos);
        foreach ($archivos as $archivo) {
            (require $archivo)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            DB::table('roles')->insert(['nombre' => $rol]);
        }
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function catalogo(array &$cookies, string $nombre): array
    {
        return $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => $nombre])->assertCreated()->json('data');
    }

    private function estadoCatalogo(array &$cookies, array $e, bool $activa): array
    {
        return $this->navegador($cookies, 'PATCH', '/api/especialidades/'.$e['id_especialidad'].'/estado',
            ['actualizado_en_original' => $e['actualizado_en'], 'activa' => $activa])->assertOk()->json('data');
    }

    private function ficha(array &$cookies, string $id): array
    {
        return $this->navegador($cookies, 'GET', '/api/medicos/'.$id)->assertOk()->json('data');
    }

    private function estadoProfesional(array &$cookies, array $m, bool $activo): array
    {
        return $this->navegador($cookies, 'PATCH', '/api/medicos/'.$m['id_medico'].'/estado',
            ['actualizado_en_original' => $m['actualizado_en'], 'activo' => $activo])->assertOk()->json('data');
    }

    private function conjunto(array &$cookies, array $m, array $ids): array
    {
        return $this->navegador($cookies, 'PUT', '/api/medicos/'.$m['id_medico'].'/especialidades',
            ['actualizado_en_original' => $m['actualizado_en'], 'especialidades' => $ids])->assertOk()->json('data');
    }

    public function test_catalog_doctor_invitation_sessions_and_account_access_integrate_without_mixing_states(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $a = $this->catalogo($cookies, 'Especialidad Integral A');
        $b = $this->catalogo($cookies, 'Especialidad Integral B');
        $m = $this->navegador($cookies, 'POST', '/api/medicos', ['nombres' => 'Profesional', 'apellidos' => 'Integración Ficticia',
            'registro_profesional' => 'REG-INTEGRACION', 'telefono' => '70000000', 'correo_contacto' => 'contacto@example.test',
            'especialidades' => [$a['id_especialidad'], $b['id_especialidad']]])->assertCreated()->json('data');
        $id = $m['id_medico'];
        $this->assertFalse($m['cuenta_vinculada']);
        $this->assertSame(1, DB::table('usuarios')->count());
        foreach (['citas', 'consultorios', 'horarios_atencion'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count());
        }
        $this->navegador($cookies, 'POST', '/api/personal', ['rol' => 'Médico', 'id_medico' => $id,
            'correo_acceso' => 'acceso.profesional@example.test'])->assertCreated();
        parse_str(parse_url(Mail::sent(InvitacionPersonalMail::class)->sole()->enlace, PHP_URL_FRAGMENT), $fragmento);
        $usuario = Usuario::query()->where('correo_acceso', 'acceso.profesional@example.test')->sole();
        $m = $this->ficha($cookies, $id);
        $this->assertTrue($m['cuenta_vinculada']);
        $this->assertTrue($m['activo']);
        $this->assertSame('Pendiente', $usuario->estado_cuenta);
        $medico = $this->csrfAuth();
        $this->loginAuth($medico, $usuario)->assertUnauthorized();
        $this->navegador($medico, 'POST', '/api/auth/invitaciones/aceptar', ['token' => $fragmento['token'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $this->navegador($medico, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($medico, $usuario)->assertOk()->assertJsonPath('data.rol', 'Médico');
        $otro = $this->csrfAuth();
        $this->loginAuth($otro, $usuario)->assertOk();
        $this->navegador($medico, 'GET', '/api/medicos')->assertForbidden();
        $m = $this->navegador($cookies, 'PATCH', '/api/medicos/'.$id, ['actualizado_en_original' => $m['actualizado_en'],
            'correo_contacto' => 'nuevo.contacto@example.test', 'telefono' => '71111111'])->assertOk()->json('data');
        $this->assertSame('acceso.profesional@example.test', $usuario->fresh()->correo_acceso);
        $b = $this->estadoCatalogo($cookies, $b, false);
        $m = $this->estadoProfesional($cookies, $m, false);
        $m = $this->conjunto($cookies, $m, [$b['id_especialidad']]);
        $this->assertSame([false, true], array_column($m['especialidades'], 'habilitada'));
        $this->assertFalse($m['especialidades'][1]['activa']);
        $this->navegador($cookies, 'GET', '/api/medicos?activo=0&id_especialidad='.$b['id_especialidad'])->assertOk()
            ->assertJsonPath('data.0.id_medico', $id)->assertJsonCount(1, 'data.0.especialidades');
        $this->navegador($cookies, 'GET', '/api/medicos?id_especialidad='.$a['id_especialidad'])->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($medico, 'GET', '/api/auth/me')->assertOk();
        $this->navegador($otro, 'GET', '/api/auth/me')->assertOk();
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($medico, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($otro, 'GET', '/api/auth/me')->assertUnauthorized();
        $m = $this->estadoProfesional($cookies, $m, true);
        $this->assertSame('Desactivada', $usuario->fresh()->estado_cuenta);
        $this->loginAuth($medico, $usuario)->assertUnauthorized();
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->navegador($medico, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($medico, $usuario)->assertOk();
        $b = $this->estadoCatalogo($cookies, $b, true);
        $m = $this->conjunto($cookies, $m, [$a['id_especialidad'], $b['id_especialidad']]);
        $this->assertSame([true, true], array_column($m['especialidades'], 'habilitada'));
        $this->assertSame(2, DB::table('medico_especialidad')->count());
        $this->assertSame($id, (string) $usuario->fresh()->medico->getKey());
        $this->assertSame(1, DB::table('medicos')->count());
        $eventos = DB::table('bitacora')->whereIn('modulo', ['MEDICOS', 'ESPECIALIDADES'])->get();
        $this->assertCount(10, $eventos);
        foreach ($eventos as $evento) {
            $this->assertSame($admin->getKey(), $evento->id_usuario_responsable);
            $this->assertSame('EXITO', $evento->resultado);
            $this->assertStringNotContainsString('nuevo.contacto@example.test', $evento->cambios_seguros);
            $this->assertStringNotContainsString($fragmento['token'], $evento->cambios_seguros);
        }
        Mail::assertSent(InvitacionPersonalMail::class, 1);
        Mail::assertSentCount(1);
    }

    public function test_existing_clinical_history_survives_catalog_changes_blocked_withdrawal_and_later_rehabilitation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $admin = $this->usuarioAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $c = $this->laboratorioContext();
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($c));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($c));
        $this->resultadoEnRevision($c);
        $historia = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'solicitudes_laboratorio',
            'documentos_laboratorio', 'versiones_resultado', 'revisiones_resultado'] as $tabla) {
            $historia[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $m = $this->ficha($cookies, (string) $c['doctors'][0]);
        $codigo = $m['codigo_medico'];
        $idUsuario = DB::table('medicos')->where('id_medico', $m['id_medico'])->value('id_usuario');
        $otra = $this->catalogo($cookies, 'Alternativa Integral');
        $nueva = $this->catalogo($cookies, 'Nueva Integral');
        $m = $this->conjunto($cookies, $m, [(string) $c['specialty'], $otra['id_especialidad']]);
        $this->navegador($cookies, 'PUT', '/api/medicos/'.$m['id_medico'].'/especialidades',
            ['actualizado_en_original' => $m['actualizado_en'], 'especialidades' => [$nueva['id_especialidad']]])->assertConflict()
            ->assertJsonPath('error.code', 'MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS');
        $this->assertSame($m, $this->ficha($cookies, $m['id_medico']));
        $this->assertFalse(DB::table('medico_especialidad')->where('id_medico', $m['id_medico'])->where('id_especialidad', $nueva['id_especialidad'])->exists());
        $catalogo = $this->navegador($cookies, 'GET', '/api/especialidades/'.$c['specialty'])->assertOk()->json('data');
        $catalogo = $this->estadoCatalogo($cookies, $catalogo, false);
        $m = $this->estadoProfesional($cookies, $m, false);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$idUsuario.'/rol', ['rol' => 'Recepcionista'])->assertConflict()
            ->assertJsonPath('error.code', 'MEDICO_OBLIGACIONES_NO_RESUELTAS');
        // Tiempo posterior: la regla de retiro no reabre ni cambia estados de la cita histórica.
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:00:00Z'));
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $m = $this->conjunto($cookies, $m, [$otra['id_especialidad']]);
        $this->assertFalse($m['especialidades'][0]['habilitada']);
        $m = $this->conjunto($cookies, $m, [(string) $c['specialty'], $otra['id_especialidad']]);
        $this->assertTrue($m['especialidades'][0]['habilitada']);
        $this->assertFalse($m['especialidades'][0]['activa']);
        $this->assertFalse($m['activo']);
        $this->assertSame($codigo, $m['codigo_medico']);
        $this->assertSame($idUsuario, DB::table('medicos')->where('id_medico', $m['id_medico'])->value('id_usuario'));
        foreach ($historia as $tabla => $datos) {
            $this->assertSame($datos, DB::table($tabla)->get()->toJson());
        }
        $this->assertSame(3, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
        $eventos = DB::table('bitacora')->whereIn('modulo', ['MEDICOS', 'ESPECIALIDADES'])->get()->toJson();
        $this->assertStringNotContainsString('Motivo clínico ficticio', $eventos);
        $this->assertStringNotContainsString('ruta_privada', $eventos);
        Mail::assertNothingSent();
    }
}
