<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\shared\utils\TransportePush;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class RecordatoriosIntegracionTest extends TestCase
{
    use AutenticacionFixtures { navegador as private navegadorBase; }
    use CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $identity = DB::selectOne('SELECT current_database() AS db, current_user AS usuario');
        $this->assertSame('clinica_vida_salud_test', $identity->db);
        $this->assertSame('clinica_vida_salud_test', $identity->usuario);
        $schemas = DB::select('SELECT nspname FROM pg_namespace ORDER BY nspname');
        $schema = 'prueba_recordatorios_integracion_'.strtolower(Str::random(16));
        $previous = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        $this->beforeApplicationDestroyed(function () use ($schema, $previous, $schemas): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $previous]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
            $this->assertEquals($schemas, DB::select('SELECT nspname FROM pg_namespace ORDER BY nspname'));
            Http::assertNothingSent();
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
        });
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
        Carbon::setTestNow('2026-10-31T12:00:00Z');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100],
            'push.habilitado' => true, 'push.vapid_publica' => $this->publicKey()]);
        $this->app['env'] = 'local';
        Mail::fake();
        // Ninguna ruta de esta integración puede llegar al adaptador real (cURL no usa Http::fake).
        $this->app->instance(TransportePush::class, Mockery::mock(TransportePush::class));
    }

    private function navegador(array &$cookies, string $metodo, string $uri, array $datos = [], array $headers = [], bool $csrf = true): TestResponse
    {
        Cookie::unqueue(config('push.cookie'), '/');

        return $this->navegadorBase($cookies, $metodo, $uri, $datos, $headers, $csrf);
    }

    private function publicKey(): string
    {
        // Punto público P-256 de prueba; no se genera ni persiste clave privada.
        return rtrim(strtr(base64_encode(hex2bin('046b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c2964fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5')), '+/', '-_'), '=');
    }

    private function contexto(): array
    {
        // Identidad ya activada y cita inicial son fixtures; los cambios posteriores sí usan APIs.
        $r = $this->citaResources();
        $u = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $u->getKey()]);
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'inicio_at' => '2026-11-01T12:10:00Z', 'fin_at' => '2026-11-01T12:25:00Z',
            'motivo_breve' => 'CONTENIDO-CLINICO-FICTICIO',
        ]), 'id_cita');
        $c = $this->csrfAuth();
        $this->loginAuth($c, $u)->assertOk();
        $payload = ['permiso_otorgado' => true, 'suscripcion' => [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/integracion-ficticia', 'expirationTime' => null,
            'keys' => ['p256dh' => $this->publicKey(), 'auth' => 'AAAAAAAAAAAAAAAAAAAAAA'],
        ]];
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $payload)->assertCreated();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $payload)->assertOk();
        $this->assertSame(1, DB::table('suscripciones_push')->count());
        $this->assertStringNotContainsString($payload['suscripcion']['endpoint'], DB::table('suscripciones_push')->sole()->datos_cifrados);
        // Avance hasta el umbral exacto: generación sin sesión ni temporizador de navegador.
        CarbonImmutable::setTestNow('2026-10-31T12:10:00Z');
        Carbon::setTestNow('2026-10-31T12:10:00Z');

        return [$r, $id, $c];
    }

    private function generar(): void
    {
        $this->artisan('recordatorios:generar', ['--lote' => '1'])->assertSuccessful();
        $this->assertSame(0, DB::transactionLevel());
    }

    private function sinTransporte(): void
    {
        app(TransportePush::class)->shouldNotReceive('enviar');
        $this->artisan('recordatorios:despachar', ['--lote' => '1'])->assertSuccessful();
        $this->assertSame('Descartado', DB::table('envios_recordatorio_push')->sole()->estado);
        $this->assertSame(0, DB::table('envios_recordatorio_push')->sole()->intentos);
    }

    public function test_api_consent_commands_and_authorized_reads_share_one_committed_outbox(): void
    {
        [$r, $id, $c] = $this->contexto();
        $before = DB::table('citas')->sole();
        $this->generar();
        $this->generar();
        $this->assertSame(1, DB::table('recordatorios')->count());
        $this->assertSame(1, DB::table('envios_recordatorio_push')->count());
        $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_cita', $id)->assertHeader('Cache-Control', 'no-store, private');
        $other = $this->usuarioAuth('Paciente');
        $otherCookies = $this->csrfAuth();
        $this->loginAuth($otherCookies, $other)->assertOk();
        $this->navegador($otherCookies, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($otherCookies, 'DELETE', '/api/push/suscripciones/'.DB::table('suscripciones_push')->sole()->id_suscripcion)->assertNotFound();
        app(TransportePush::class)->shouldReceive('enviar')->once()->andReturnUsing(function ($datos, $inicio): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('Procesando', DB::table('envios_recordatorio_push')->sole()->estado);
            $this->assertSame('https://fcm.googleapis.com/fcm/send/integracion-ficticia', $datos['endpoint']);
            $this->assertTrue($inicio->isAfter(CarbonImmutable::now('UTC')));

            return ['tipo' => 'Aceptado', 'http' => 201, 'retry_after' => null];
        });
        $this->artisan('recordatorios:despachar', ['--lote' => '1'])->assertSuccessful();
        $this->generar();
        $this->artisan('recordatorios:despachar', ['--lote' => '1'])->assertSuccessful();
        $this->assertSame('Aceptado', DB::table('envios_recordatorio_push')->sole()->estado);
        $this->assertSame(1, DB::table('envios_recordatorio_push')->sole()->intentos);
        $this->assertEquals($before, DB::table('citas')->sole());
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        $admin = $this->usuarioAuth();
        $a = $this->csrfAuth();
        $this->loginAuth($a, $admin)->assertOk();
        $this->navegador($a, 'GET', '/api/recordatorios')->assertUnprocessable();
        $this->navegador($a, 'GET', '/api/recordatorios?id_paciente='.$r['patient'])->assertOk()->assertJsonPath('data.0.id_cita', $id);
        $audit = $this->navegador($a, 'GET', '/api/bitacora?tipo_evento=PUSH_AUTORIZADO')->assertOk()->assertJsonPath('meta.total', 1);
        foreach ([$audit->getContent(), DB::table('bitacora')->get()->toJson(), DB::table('envios_recordatorio_push')->get()->toJson()] as $safe) {
            foreach (['integracion-ficticia', 'AAAAAAAAAAAAAAAAAAAAAA', 'CONTENIDO-CLINICO-FICTICIO'] as $secret) {
                $this->assertStringNotContainsString($secret, $safe);
            }
        }
    }

    public static function cambiosCita(): array
    {
        return [['cancelacion'], ['reprogramacion']];
    }

    #[DataProvider('cambiosCita')]
    public function test_appointment_api_changes_retire_old_delivery_without_resurrecting_it(string $operacion): void
    {
        [$r, $id, $c] = $this->contexto();
        $this->generar();
        $old = DB::table('recordatorios')->sole()->id_recordatorio;
        $a = $this->csrfAuth();
        $this->loginAuth($a, $this->usuarioAuth())->assertOk();
        $vista = $this->navegador($a, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $datos = ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en']];
        if ($operacion === 'reprogramacion') {
            DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
            DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
                'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00']);
            $turnos = $this->navegador($a, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->json('data.turnos');
            $turno = collect($turnos)->first(fn ($t) => $t['hora_inicio'] !== '08:10:00');
            $datos += ['fecha' => '2026-11-01', 'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']];
        }
        $this->navegador($a, 'PATCH', '/api/citas/'.$id.'/'.$operacion, $datos)->assertOk();
        $this->assertNotNull(DB::table('recordatorios')->where('id_recordatorio', $old)->value('invalidado_en'));
        // Despacho directamente tras la API: no depende de que el generador descarte primero.
        $this->sinTransporte();
        $this->generar();
        $this->generar();
        $this->assertNotNull(DB::table('recordatorios')->where('id_recordatorio', $old)->value('invalidado_en'));
        $this->assertSame('Descartado', DB::table('envios_recordatorio_push')->where('id_recordatorio', $old)->sole()->estado);
        if ($operacion === 'cancelacion') {
            $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(0, 'data');
        } else {
            $this->assertSame(2, DB::table('recordatorios')->count());
            $this->assertSame(2, DB::table('envios_recordatorio_push')->count());
            $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_cita', $id);
        }
        $this->assertSame(1, DB::table('cambios_cita')->count());
    }

    public static function retiros(): array
    {
        return [['revocacion'], ['logout'], ['preferencia']];
    }

    #[DataProvider('retiros')]
    public function test_patient_api_opt_out_prevents_pending_transport(string $retiro): void
    {
        [, , $c] = $this->contexto();
        $this->generar();
        $before = DB::table('citas')->sole();
        if ($retiro === 'preferencia') {
            $perfil = $this->navegador($c, 'GET', '/api/mi-perfil')->assertOk()->json('data');
            $this->navegador($c, 'PATCH', '/api/mi-perfil', ['actualizado_en_original' => $perfil['actualizado_en'],
                'recordatorios_habilitados' => false])->assertOk();
        } elseif ($retiro === 'logout') {
            $this->navegador($c, 'POST', '/api/auth/logout')->assertNoContent();
            $this->navegador($c, 'GET', '/api/recordatorios')->assertUnauthorized();
        } else {
            $this->navegador($c, 'DELETE', '/api/push/suscripcion')->assertNoContent();
            $this->navegador($c, 'DELETE', '/api/push/suscripcion')->assertNoContent();
        }
        $this->sinTransporte();
        $this->generar();
        $this->assertSame(1, DB::table('envios_recordatorio_push')->count());
        $this->assertEquals($before, DB::table('citas')->sole());
        if ($retiro === 'revocacion') {
            $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PUSH_REVOCADO')->count());
        }
    }
}
