<?php

namespace Tests\Feature;

use Clinica\data\models\Usuario;
use Clinica\shared\utils\CambioCorreoMail;
use Clinica\shared\utils\RecuperacionClaveMail;
use Clinica\shared\utils\VerificacionPacienteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class PacientesIntegracionTest extends TestCase
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
        $migraciones = glob(base_path('src/data/database/migrations/*.php'));
        sort($migraciones);
        foreach ($migraciones as $archivo) {
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

    private function tokenRecuperacion(): string
    {
        parse_str(parse_url(Mail::sent(RecuperacionClaveMail::class)->last()->enlace, PHP_URL_FRAGMENT), $fragmento);

        return $fragmento['token'];
    }

    public static function escenarios(): array
    {
        return ['alta administrativa sin CI' => [false], 'ficha histórica con CI no normalizado' => [true]];
    }

    #[DataProvider('escenarios')]
    public function test_reception_linkage_profile_email_recovery_and_access_preserve_one_identity_and_history(bool $historico): void
    {
        $recepcion = $this->csrfAuth();
        $this->loginAuth($recepcion, $this->usuarioAuth('Recepcionista'))->assertOk();
        if ($historico) {
            $c = $this->laboratorioContext();
            DB::table('versiones_consulta')->insert($this->versionConsultaRow($c));
            DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($c));
            $this->resultadoEnRevision($c);
            $id = $c['patient'];
            DB::table('pacientes')->where('id_paciente', $id)->update(['documento_identidad' => ' CI-INTEGRACION ', 'activo' => false]);
            $f = $this->navegador($recepcion, 'GET', '/api/pacientes/'.$id)->assertOk()->json('data');
        } else {
            $f = $this->navegador($recepcion, 'POST', '/api/pacientes', ['nombres' => 'Paciente', 'apellidos' => 'Integración ficticia',
                'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'posibles_duplicados_revisados' => true])->assertCreated()->json('data');
            $id = $f['id_paciente'];
            $this->navegador($recepcion, 'POST', '/api/pacientes/'.$id.'/codigo-activacion', ['identidad_verificada' => true])->assertConflict();
            $f = $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id, ['actualizado_en_original' => $f['actualizado_en'],
                'documento_identidad' => 'CI-INTEGRACION', 'identidad_verificada' => true, 'motivo' => 'Documento presentado presencialmente'])->assertOk()->json('data');
        }
        $codigoFicha = $f['codigo_paciente'];
        $cantidadFichas = DB::table('pacientes')->count();
        $historia = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'solicitudes_laboratorio', 'documentos_laboratorio', 'versiones_resultado', 'revisiones_resultado'] as $tabla) {
            $historia[$tabla] = DB::table($tabla)->get()->toArray();
        }
        $principal = $this->navegador($recepcion, 'POST', '/api/pacientes/'.$id.'/codigo-activacion', ['identidad_verificada' => true])->assertCreated()->json('data.codigo_activacion');
        $publico = $this->csrfAuth();
        $datos = ['documento_identidad' => 'CI-INTEGRACION', 'codigo_activacion' => $principal,
            'correo_acceso' => 'acceso.integracion@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->navegador($publico, 'POST', '/api/auth/registro/vincular', $datos)->assertStatus(202);
        $viejo = Mail::sent(VerificacionPacienteMail::class)->last()->codigo;
        // El mismo CI normalizado es no-op incluso con reserva en curso y almacenamiento histórico con espacios.
        $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id, ['actualizado_en_original' => $f['actualizado_en'],
            'documento_identidad' => '  CI-INTEGRACION  '])->assertOk()->assertJsonPath('data.actualizado_en', $f['actualizado_en']);
        $this->assertNull(DB::table('codigos_activacion_paciente')->value('invalidado_en'));
        $this->assertNull(DB::table('registros_paciente_pendientes')->value('invalidado_en'));
        $this->assertSame($historico ? 0 : 1, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')
            ->whereJsonContains('cambios_seguros->campos', 'documento_identidad')->count());
        // Una corrección real cancela ambos códigos, y la nueva emisión conserva la ficha.
        $f = $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id, ['actualizado_en_original' => $f['actualizado_en'],
            'documento_identidad' => 'CI-CORREGIDO', 'identidad_verificada' => true, 'motivo' => 'Corrección presencial comprobada'])->assertOk()->json('data');
        $this->navegador($publico, 'POST', '/api/auth/registro/verificar', ['codigo' => $viejo])->assertUnprocessable();
        $datos['documento_identidad'] = 'CI-CORREGIDO';
        $datos['codigo_activacion'] = $this->navegador($recepcion, 'POST', '/api/pacientes/'.$id.'/codigo-activacion', ['identidad_verificada' => true])->assertCreated()->json('data.codigo_activacion');
        $this->navegador($publico, 'POST', '/api/auth/registro/vincular', $datos)->assertStatus(202);
        $activacion = Mail::sent(VerificacionPacienteMail::class)->last()->codigo;
        $this->navegador($publico, 'POST', '/api/auth/registro/verificar', ['codigo' => $activacion])->assertNoContent();
        $usuario = Usuario::query()->where('correo_acceso', $datos['correo_acceso'])->sole();
        $this->loginAuth($publico, $usuario)->assertOk();
        $perfil = $this->navegador($publico, 'GET', '/api/mi-perfil')->assertOk()
            ->assertJsonPath('data.id_paciente', (string) $id)->assertJsonPath('data.codigo_paciente', $codigoFicha)->json('data');
        $f = $this->navegador($recepcion, 'GET', '/api/pacientes/'.$id)->assertOk()->json('data');
        $perfil = $this->navegador($publico, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $perfil['actualizado_en'],
            'correo_contacto' => 'contacto.integracion@example.test', 'recordatorios_habilitados' => false,
            'contacto_emergencia' => ['nombre' => 'Familiar ficticio', 'parentesco' => 'Familiar', 'telefono' => '71111111']])->assertOk()->json('data');
        $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id, ['actualizado_en_original' => $f['actualizado_en'], 'telefono' => '72222222'])->assertConflict();
        $estado = $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id.'/estado', ['actualizado_en_original' => $perfil['actualizado_en'],
            'activo' => ! $perfil['activo'], 'motivo' => 'Revisión administrativa de prueba'])->assertOk()->json('data');
        $this->navegador($publico, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $perfil['actualizado_en'], 'direccion' => 'Dirección ficticia'])->assertConflict();
        if ($estado['activo']) {
            $estado = $this->navegador($recepcion, 'PATCH', '/api/pacientes/'.$id.'/estado', ['actualizado_en_original' => $estado['actualizado_en'],
                'activo' => false, 'motivo' => 'Inactividad de ficha de prueba'])->assertOk()->json('data');
        }
        $perfil = $this->navegador($publico, 'GET', '/api/mi-perfil')->assertOk()->assertJsonPath('data.activo', false)->json('data');
        $this->navegador($publico, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $perfil['actualizado_en'], 'telefono' => '73333333'])->assertOk();
        $otraSesion = $this->csrfAuth();
        $this->loginAuth($otraSesion, $usuario)->assertOk();
        $anonimas = $this->csrfAuth();
        $this->navegador($anonimas, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $recuperacionAnterior = $this->tokenRecuperacion();
        $this->navegador($publico, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'acceso.nuevo@example.test', 'current_password' => self::PASSWORD])
            ->assertStatus(202)->assertJsonPath('data.envio', 'simulado');
        $correo = Mail::sent(CambioCorreoMail::class)->last()->codigo;
        $this->navegador($publico, 'POST', '/api/mi-perfil/correo/verificar', ['codigo' => $correo])->assertNoContent();
        $this->navegador($publico, 'GET', '/api/mi-perfil')->assertUnauthorized();
        $this->navegador($otraSesion, 'GET', '/api/auth/me')->assertUnauthorized();
        $nuevaClave = 'Frase integrada recuperada 123';
        $this->navegador($anonimas, 'POST', '/api/auth/password/reset', ['token' => $recuperacionAnterior, 'password' => $nuevaClave,
            'password_confirmation' => $nuevaClave])->assertUnprocessable();
        $usuario->correo_acceso = 'acceso.nuevo@example.test';
        $this->loginAuth($publico, $usuario)->assertOk();
        $this->navegador($anonimas, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $recuperacionNueva = $this->tokenRecuperacion();
        $this->navegador($anonimas, 'POST', '/api/auth/password/reset', ['token' => $recuperacionNueva, 'password' => $nuevaClave,
            'password_confirmation' => $nuevaClave])->assertNoContent();
        $this->navegador($publico, 'GET', '/api/mi-perfil')->assertUnauthorized();
        $this->loginAuth($publico, $usuario, $nuevaClave)->assertOk();
        $perfil = $this->navegador($publico, 'GET', '/api/mi-perfil')->assertOk()->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.correo_contacto', 'contacto.integracion@example.test')->assertJsonPath('data.recordatorios_habilitados', false)->json('data');
        $this->assertSame((string) $id, $perfil['id_paciente']);
        $this->assertSame($codigoFicha, $perfil['codigo_paciente']);
        $this->assertSame($cantidadFichas, DB::table('pacientes')->count());
        $this->assertSame($usuario->getKey(), DB::table('pacientes')->where('id_paciente', $id)->value('id_usuario'));
        foreach ($historia as $tabla => $filas) {
            $this->assertEquals($filas, DB::table($tabla)->get()->toArray(), 'Historia conservada: '.$tabla);
        }
        $administrativo = $this->navegador($recepcion, 'GET', '/api/pacientes/'.$id)->assertOk()->json('data');
        foreach (['correo_acceso', 'id_usuario', 'consultas', 'antecedentes', 'documentos', 'password_hash', 'preferencias_privacidad'] as $campo) {
            $this->assertArrayNotHasKey($campo, $administrativo);
        }
        $eventos = DB::table('bitacora')->get()->toJson();
        foreach ([$principal, $viejo, $activacion, $correo, self::PASSWORD, $nuevaClave, $recuperacionAnterior, $recuperacionNueva,
            'contacto.integracion@example.test', 'Familiar ficticio'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $eventos);
        }
    }
}
