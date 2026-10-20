<?php

namespace Tests\Feature;

use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\shared\utils\InvitacionPersonalMail;
use Clinica\shared\utils\RecuperacionClaveMail;
use Clinica\shared\utils\VerificacionPacienteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class UsuariosAutenticacionIntegracionTest extends TestCase
{
    use AutenticacionFixtures;

    private const CAMBIADA = 'Frase ficticia cambiada 456';

    private const RECUPERADA = 'Frase ficticia recuperada 789';

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
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000006_create_usuarios',
            '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes',
            '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes',
            '000015_create_verificaciones_correo', '000016_create_medicos'] as $nombre) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$nombre.'_table.php'))->up();
        }
        (require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php'))->up();
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            DB::table('roles')->insert(['nombre' => $rol]);
        }
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local'; // El Kernel conserva CSRF real en estos recorridos.
        Mail::fake();
    }

    private function tokenEnCorreo(string $tipo): string
    {
        $mail = Mail::sent($tipo)->last();
        $this->assertNotNull($mail);
        parse_str(parse_url($mail->enlace, PHP_URL_FRAGMENT), $fragmento);

        return $fragmento['token'];
    }

    private function sinSecretosEnBitacora(array $secretos): void
    {
        $eventos = DB::table('bitacora')->get()->toJson();
        foreach ([self::PASSWORD, self::CAMBIADA, self::RECUPERADA, ...$secretos] as $secreto) {
            $this->assertStringNotContainsString($secreto, $eventos);
        }
    }

    public static function escenariosPaciente(): array
    {
        return ['nuevo' => [false], 'ficha histórica inactiva' => [true]];
    }

    #[DataProvider('escenariosPaciente')]
    public function test_patient_activation_change_recovery_and_administrative_access_share_identity_and_revoke_old_contexts(bool $historico): void
    {
        $admin = $this->csrfAuth();
        $this->loginAuth($admin, $this->usuarioAuth())->assertOk();
        $publico = $this->csrfAuth();
        $datos = ['documento_identidad' => 'CI-INTEGRACION', 'correo_acceso' => 'paciente.integracion@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $original = null;
        $principal = null;
        if ($historico) {
            $paciente = new Paciente;
            $paciente->forceFill(['codigo_paciente' => 'PAC-INTEGRACION', 'documento_identidad' => $datos['documento_identidad'],
                'nombres' => 'Paciente', 'apellidos' => 'Histórico ficticio', 'fecha_nacimiento' => '1980-01-01',
                'telefono' => '70000000', 'activo' => false])->saveOrFail();
            $original = $paciente->fresh()->getAttributes();
            $recepcion = $this->csrfAuth();
            $this->loginAuth($recepcion, $this->usuarioAuth('Recepcionista'))->assertOk();
            $principal = $this->navegador($recepcion, 'POST', '/api/pacientes/'.$paciente->getKey().'/codigo-activacion',
                ['identidad_verificada' => true])->assertCreated()->json('data.codigo_activacion');
            $this->navegador($publico, 'POST', '/api/auth/registro/vincular', [...$datos, 'codigo_activacion' => $principal])->assertStatus(202);
        } else {
            $this->navegador($publico, 'POST', '/api/auth/registro', [...$datos, 'nombres' => 'Paciente', 'apellidos' => 'Nuevo ficticio',
                'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'])->assertStatus(202);
        }
        $codigo = Mail::sent(VerificacionPacienteMail::class)->sole()->codigo;
        $this->assertFalse(Usuario::query()->where('correo_acceso', $datos['correo_acceso'])->exists());
        $this->navegador($publico, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $datos['correo_acceso']])->assertStatus(202);
        Mail::assertNotSent(RecuperacionClaveMail::class);
        $this->navegador($publico, 'POST', '/api/auth/registro/verificar', ['codigo' => $codigo])->assertNoContent();
        $this->navegador($publico, 'GET', '/api/auth/me')->assertUnauthorized();

        $usuario = Usuario::query()->where('correo_acceso', $datos['correo_acceso'])->sole();
        $ficha = $usuario->paciente;
        $this->assertSame(! $historico, $ficha->activo);
        $this->assertSame(1, Paciente::query()->count());
        $this->loginAuth($publico, $usuario)->assertOk()->assertExactJson(['data' => [
            'id_usuario' => (string) $usuario->getKey(), 'correo_acceso' => $datos['correo_acceso'],
            'nombre_visible' => $ficha->nombres.' '.$ficha->apellidos, 'rol' => 'Paciente',
        ]]);
        $otro = $this->csrfAuth();
        $this->loginAuth($otro, $usuario)->assertOk();
        $this->navegador($publico, 'GET', '/api/usuarios')->assertForbidden();

        $recuperacion = $this->csrfAuth();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $anterior = $this->tokenEnCorreo(RecuperacionClaveMail::class);
        $sesionAnterior = $publico;
        $this->navegador($publico, 'PUT', '/api/auth/password', ['current_password' => self::PASSWORD,
            'password' => self::CAMBIADA, 'password_confirmation' => self::CAMBIADA])->assertNoContent();
        $this->navegador($publico, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id_usuario', (string) $usuario->getKey());
        $this->navegador($sesionAnterior, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($otro, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/reset', ['token' => $anterior,
            'password' => self::RECUPERADA, 'password_confirmation' => self::RECUPERADA])->assertStatus(422);
        $this->assertTrue(Hash::check(self::CAMBIADA, $usuario->fresh()->password_hash));

        $this->travel(61)->seconds(); // Recorrido humano sin desactivar límites técnicos de producción.
        $this->navegador($recuperacion, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $token = $this->tokenEnCorreo(RecuperacionClaveMail::class);
        $this->navegador($recuperacion, 'POST', '/api/auth/password/reset', ['token' => $token,
            'password' => self::RECUPERADA, 'password_confirmation' => self::RECUPERADA])->assertNoContent();
        $this->navegador($publico, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($recuperacion, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($recuperacion, $usuario, self::CAMBIADA)->assertUnauthorized();
        $this->loginAuth($recuperacion, $usuario, self::RECUPERADA)->assertOk();

        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $desactivada = $recuperacion;
        $this->navegador($recuperacion, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        Mail::assertSent(RecuperacionClaveMail::class, 2);
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->navegador($desactivada, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($recuperacion, $usuario, self::RECUPERADA)->assertOk()->assertJsonPath('data.rol', 'Paciente');
        $this->navegador($recuperacion, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($recuperacion, 'GET', '/api/auth/me')->assertUnauthorized();

        $this->assertSame($ficha->getKey(), $usuario->fresh()->paciente->getKey());
        $this->assertSame(! $historico, $usuario->fresh()->paciente->activo);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        if ($original !== null) {
            $actual = $usuario->fresh()->paciente->getAttributes();
            unset($original['id_usuario'], $original['actualizado_en'], $actual['id_usuario'], $actual['actualizado_en']);
            $this->assertSame($original, $actual);
        }
        $this->sinSecretosEnBitacora(array_filter([$principal, $codigo, $anterior, $token, $usuario->fresh()->password_hash]));
    }

    public function test_invited_staff_temporary_reset_role_change_and_recovery_keep_purposes_and_sessions_isolated(): void
    {
        $admin = $this->csrfAuth();
        $administrador = $this->usuarioAuth();
        $this->loginAuth($admin, $administrador)->assertOk();
        $id = $this->navegador($admin, 'POST', '/api/personal', ['rol' => 'Recepcionista',
            'correo_acceso' => 'personal.integracion@example.test', 'nombres_personal' => 'Profesional', 'apellidos_personal' => 'Ficticio'])
            ->assertCreated()->assertJsonPath('data.estado_cuenta', 'Pendiente')->json('data.id_usuario');
        $usuario = Usuario::query()->findOrFail($id);
        $invitacion = $this->tokenEnCorreo(InvitacionPersonalMail::class);
        $personal = $this->csrfAuth();
        $this->navegador($personal, 'POST', '/api/auth/password/reset', ['token' => $invitacion,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(422);
        $this->assertSame('Pendiente', $usuario->fresh()->estado_cuenta);
        $this->navegador($personal, 'POST', '/api/auth/invitaciones/aceptar', ['token' => $invitacion,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $this->navegador($personal, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($personal, $usuario)->assertOk()->assertJsonPath('data.rol', 'Recepcionista');

        $recuperacion = $this->csrfAuth();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $antiguo = $this->tokenEnCorreo(RecuperacionClaveMail::class);
        $temporal = $this->navegador($admin, 'POST', '/api/usuarios/'.$id.'/credencial-temporal', ['identidad_verificada' => true])
            ->assertCreated()->json('data.credencial_temporal');
        $this->navegador($personal, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/reset', ['token' => $antiguo,
            'password' => self::RECUPERADA, 'password_confirmation' => self::RECUPERADA])->assertStatus(422);
        $this->loginAuth($personal, $usuario, $temporal)->assertOk()->assertExactJson([
            'data' => ['acceso' => 'cambio_clave', 'cambio_clave_obligatorio' => true],
        ]);
        $this->navegador($personal, 'GET', '/api/usuarios')->assertForbidden();
        $this->navegador($personal, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        Mail::assertSent(RecuperacionClaveMail::class, 1);
        $this->navegador($personal, 'PUT', '/api/auth/password/temporal', ['password' => self::CAMBIADA,
            'password_confirmation' => self::CAMBIADA])->assertNoContent();
        $this->navegador($personal, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($personal, $usuario, self::CAMBIADA)->assertOk();

        $medico = DB::table('medicos')->insertGetId(['codigo_medico' => 'MED-INTEGRACION', 'registro_profesional' => 'REG-FICTICIO',
            'nombres' => 'Profesional', 'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'contacto@example.test',
            'activo' => false], 'id_medico');
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$id.'/rol', ['rol' => 'Médico', 'id_medico' => $medico,
            'identidad_verificada' => true])->assertOk()->assertJsonPath('data.rol', 'Médico');
        $this->navegador($personal, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($personal, $usuario, self::CAMBIADA)->assertOk()->assertJsonPath('data.rol', 'Médico');
        $this->navegador($personal, 'GET', '/api/usuarios')->assertForbidden();
        $this->navegador($personal, 'POST', '/api/auth/logout')->assertNoContent();
        $this->travel(61)->seconds();
        $this->navegador($recuperacion, 'POST', '/api/auth/password/forgot', ['correo_acceso' => $usuario->correo_acceso])->assertStatus(202);
        $nuevo = $this->tokenEnCorreo(RecuperacionClaveMail::class);
        $this->navegador($recuperacion, 'POST', '/api/auth/invitaciones/aceptar', ['token' => $nuevo,
            'password' => self::RECUPERADA, 'password_confirmation' => self::RECUPERADA])->assertStatus(422);
        $this->navegador($recuperacion, 'POST', '/api/auth/password/reset', ['token' => $nuevo,
            'password' => self::RECUPERADA, 'password_confirmation' => self::RECUPERADA])->assertNoContent();
        $this->navegador($recuperacion, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->loginAuth($recuperacion, $usuario, self::RECUPERADA)->assertOk()->assertJsonPath('data.rol', 'Médico');
        $this->navegador($admin, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id_usuario', (string) $administrador->getKey());
        $this->assertSame($medico, $usuario->fresh()->medico->getKey());
        $this->assertFalse($usuario->fresh()->medico->activo);
        $this->assertFalse($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertSame(2, Usuario::query()->count());
        $this->assertSame(0, Paciente::query()->count());
        $this->sinSecretosEnBitacora([$invitacion, $antiguo, $temporal, $nuevo, $usuario->fresh()->password_hash]);
    }
}
