<?php

namespace Tests\Feature;

use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\utils\InvitacionPersonalMail;
use Clinica\shared\utils\VerificacionPacienteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class BitacoraIntegracionTest extends TestCase
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

    private function sesion(string $rol = 'Administrador'): array
    {
        $usuario = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();

        return [$cookies, $usuario];
    }

    private function historial(array &$cookies, array $filtros = []): array
    {
        $antes = DB::table('bitacora')->orderBy('id_evento')->get()->toJson();
        $filas = [];
        $pagina = 1;
        do {
            $r = $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query([...$filtros, 'per_page' => 2, 'page' => $pagina]))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            foreach ($r->json('data') as $fila) {
                $detalle = $this->navegador($cookies, 'GET', '/api/bitacora/'.$fila['id_evento'])->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')->json('data');
                $this->assertSame($fila, array_intersect_key($detalle, $fila));
                $filas[] = $detalle;
            }
            $pagina++;
        } while ($pagina <= $r->json('meta.last_page'));
        $this->assertCount($r->json('meta.total'), $filas);
        $this->assertSame($antes, DB::table('bitacora')->orderBy('id_evento')->get()->toJson());

        return $filas;
    }

    private function sinSecretos(array $filas, array $secretos): void
    {
        $json = json_encode($filas);
        foreach ($secretos as $secreto) {
            $this->assertStringNotContainsString($secreto, $json);
        }
    }

    public function test_catalog_doctor_and_staff_account_events_are_committed_filtered_and_safely_readable(): void
    {
        [$admin, $actor] = $this->sesion();
        $especialidades = [];
        foreach (['Especialidad Auditoria A', 'Especialidad Auditoria B'] as $nombre) {
            $especialidades[] = $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => $nombre])->assertCreated()->json('data');
        }
        $m = $this->navegador($admin, 'POST', '/api/medicos', ['nombres' => 'ProfesionalPrivadoFicticio', 'apellidos' => 'Auditoria',
            'registro_profesional' => 'REG-PRIVADO-FICTICIO', 'telefono' => '70000000', 'correo_contacto' => 'contacto.auditoria@example.test',
            'especialidades' => array_column($especialidades, 'id_especialidad')])->assertCreated()->json('data');
        $original = $m;
        $m = $this->navegador($admin, 'PATCH', '/api/medicos/'.$m['id_medico'],
            ['actualizado_en_original' => $m['actualizado_en'], 'telefono' => '71111111'])->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/medicos/'.$m['id_medico'],
            ['actualizado_en_original' => $original['actualizado_en'], 'telefono' => '72222222'])->assertConflict();
        $m = $this->navegador($admin, 'PUT', '/api/medicos/'.$m['id_medico'].'/especialidades',
            ['actualizado_en_original' => $m['actualizado_en'], 'especialidades' => [$especialidades[0]['id_especialidad']]])->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/medicos/'.$m['id_medico'].'/estado',
            ['actualizado_en_original' => $m['actualizado_en'], 'activo' => false])->assertOk();
        $this->navegador($admin, 'POST', '/api/personal', ['rol' => 'Médico', 'id_medico' => $m['id_medico'],
            'correo_acceso' => 'acceso.auditoria@example.test'])->assertCreated();
        parse_str(parse_url(Mail::sent(InvitacionPersonalMail::class)->sole()->enlace, PHP_URL_FRAGMENT), $fragmento);
        $usuario = Usuario::query()->where('correo_acceso', 'acceso.auditoria@example.test')->sole();
        $medico = $this->csrfAuth();
        $this->navegador($medico, 'POST', '/api/auth/invitaciones/aceptar', ['token' => $fragmento['token'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $this->loginAuth($medico, $usuario)->assertOk();
        $this->navegador($medico, 'GET', '/api/auth/me')->assertOk();
        $this->navegador($medico, 'GET', '/api/bitacora')->assertForbidden();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($medico, 'GET', '/api/bitacora')->assertUnauthorized();

        $profesional = $this->historial($admin, ['entidad_referida' => 'Medico', 'registro_referido' => $m['id_medico'],
            'modulo' => 'MEDICOS', 'id_usuario_responsable' => (string) $actor->getKey()]);
        $this->assertSame(['MEDICO_ESTADO_CAMBIADO', 'MEDICO_ESPECIALIDADES_CAMBIADAS', 'MEDICO_ACTUALIZADO', 'MEDICO_CREADO'], array_column($profesional, 'tipo_evento'));
        $this->assertSame(['telefono'], $profesional[2]['cambios_seguros']['campos']);
        $this->assertSame([$especialidades[1]['id_especialidad']], $profesional[1]['cambios_seguros']['habilitaciones']['retiradas']);
        $cuenta = $this->historial($admin, ['id_usuario_objetivo' => (string) $usuario->getKey()]);
        $tipos = array_column($cuenta, 'tipo_evento');
        foreach (['PERSONAL_CREADO', 'INVITACION_EMITIDA', 'INVITACION_ENVIO', 'INVITACION_ACEPTADA', 'CUENTA_DESACTIVADA'] as $tipo) {
            $this->assertContains($tipo, $tipos);
        }
        $desactivacion = collect($cuenta)->firstWhere('tipo_evento', 'CUENTA_DESACTIVADA');
        $this->assertSame('Activa', $desactivacion['cambios_seguros']['antes']['estado_cuenta']);
        $this->assertSame('Desactivada', $desactivacion['cambios_seguros']['despues']['estado_cuenta']);
        $this->assertSame((string) $actor->getKey(), $desactivacion['id_usuario_responsable']);
        $this->assertSame('Médico', $desactivacion['rol_objetivo']);
        $catalogo = $this->historial($admin, ['entidad_referida' => 'Especialidad', 'registro_referido' => $especialidades[0]['id_especialidad']]);
        $this->assertCount(1, $catalogo);
        $this->assertSame('ESPECIALIDAD_CREADA', $catalogo[0]['tipo_evento']);
        $this->sinSecretos([...$profesional, ...$cuenta, ...$catalogo], [self::PASSWORD, $fragmento['token'], $usuario->fresh()->password_hash,
            'ProfesionalPrivadoFicticio', 'REG-PRIVADO-FICTICIO', 'acceso.auditoria@example.test', 'contacto.auditoria@example.test']);
    }

    public function test_reception_patient_linking_and_security_attempts_are_audited_without_exposing_codes_or_profile(): void
    {
        [$admin] = $this->sesion();
        [$recepcion, $actor] = $this->sesion('Recepcionista');
        $p = $this->navegador($recepcion, 'POST', '/api/pacientes', ['documento_identidad' => 'CI-AUDITORIA-PRIVADO',
            'nombres' => 'PacientePrivadoFicticio', 'apellidos' => 'Auditoria', 'fecha_nacimiento' => '1990-01-01',
            'telefono' => '70000000'])->assertCreated()->json('data');
        $motivo = 'Correccion administrativa ficticia reservada';
        $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$p['id_paciente'], ['actualizado_en_original' => $p['actualizado_en'],
            'telefono' => '71111111', 'motivo' => $motivo])->assertOk();
        $codigoRecepcion = $this->navegador($recepcion, 'POST', '/api/pacientes/'.$p['id_paciente'].'/codigo-activacion',
            ['identidad_verificada' => true])->assertCreated()->json('data.codigo_activacion');
        $paciente = $this->csrfAuth();
        $this->navegador($paciente, 'POST', '/api/auth/registro/vincular', ['documento_identidad' => 'CI-AUDITORIA-PRIVADO',
            'codigo_activacion' => $codigoRecepcion, 'correo_acceso' => 'paciente.auditoria@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(202);
        $codigoCorreo = Mail::sent(VerificacionPacienteMail::class)->sole()->codigo;
        $this->navegador($paciente, 'POST', '/api/auth/registro/verificar', ['codigo' => $codigoCorreo])->assertNoContent();
        $usuario = Usuario::query()->where('correo_acceso', 'paciente.auditoria@example.test')->sole();
        $this->loginAuth($paciente, $usuario)->assertOk();
        $this->navegador($paciente, 'GET', '/api/bitacora')->assertForbidden();
        $this->navegador($paciente, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($paciente, $usuario, 'ClaveIncorrectaFicticia')->assertUnauthorized();

        $filas = $this->historial($admin, ['entidad_referida' => 'Paciente', 'registro_referido' => $p['id_paciente']]);
        $this->assertSame(['PACIENTE_CUENTA_VINCULADA', 'VINCULACION_PACIENTE_INICIADA', 'ACTIVACION_PACIENTE_EMITIDA', 'PACIENTE_ACTUALIZADO', 'PACIENTE_CREADO'], array_column($filas, 'tipo_evento'));
        $this->assertSame((string) $actor->getKey(), $filas[2]['id_usuario_responsable']);
        $this->assertSame('Recepcionista', $filas[2]['rol_responsable']);
        $this->assertSame('ANONIMO', $filas[1]['tipo_actor']);
        $this->assertNull($filas[1]['id_usuario_responsable']);
        $this->assertSame($p['id_paciente'], $filas[0]['cambios_seguros']['id_paciente']);
        $this->assertSame(['telefono'], $filas[3]['cambios_seguros']['campos']);
        $this->assertSame([], $filas[3]['metadatos_seguros']);
        $this->assertSame(1, DB::table('pacientes')->count());
        $this->assertSame($usuario->getKey(), DB::table('pacientes')->value('id_usuario'));
        $denegados = $this->historial($admin, ['tipo_evento' => 'AUTENTICACION_LOGIN', 'resultado' => 'DENEGADO', 'tipo_actor' => 'ANONIMO']);
        $this->assertCount(1, $denegados);
        $this->assertNull($denegados[0]['id_usuario_responsable']);
        $this->sinSecretos([...$filas, ...$denegados], [self::PASSWORD, $codigoRecepcion, $codigoCorreo, $usuario->password_hash,
            'PacientePrivadoFicticio', 'CI-AUDITORIA-PRIVADO', 'paciente.auditoria@example.test', $motivo]);
        $this->navegador($recepcion, 'GET', '/api/bitacora/'.$filas[0]['id_evento'])->assertForbidden();
    }

    public function test_failed_transaction_and_conflict_do_not_publish_false_success_and_filters_match_persisted_events(): void
    {
        [$admin] = $this->sesion();
        $real = new BitacoraRepository;
        $fallo = Mockery::mock(BitacoraRepository::class);
        $fallo->shouldReceive('especialidadModificada')->once()->andReturnUsing(function (...$argumentos) use ($real): void {
            $real->especialidadModificada(...$argumentos);
            throw new RuntimeException('Fallo ficticio posterior a insertar auditoria.');
        });
        $this->app->instance(BitacoraRepository::class, $fallo);
        $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => 'Especialidad Revertida'])->assertInternalServerError();
        $this->app->instance(BitacoraRepository::class, $real);
        $this->assertSame(0, DB::table('especialidades')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_CREADA')->count());
        $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => 'Especialidad Confirmada'])->assertCreated();
        $this->navegador($admin, 'POST', '/api/especialidades', ['nombre' => 'Especialidad Confirmada'])->assertConflict();
        $filas = $this->historial($admin, ['modulo' => 'ESPECIALIDADES', 'resultado' => 'EXITO']);
        $persistidos = DB::table('bitacora')->where('modulo', 'ESPECIALIDADES')->where('resultado', 'EXITO')
            ->orderByDesc('registrado_en')->orderByDesc('id_evento')->pluck('id_evento')->map(strval(...))->all();
        $this->assertSame($persistidos, array_column($filas, 'id_evento'));
        $this->assertCount(1, $filas);
        $this->assertSame(1, DB::table('especialidades')->count());
    }
}
