<?php

namespace Tests\Fixtures;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\data\models\SuscripcionPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

trait DespachoPushFixtures
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private function prepararEsquemaDespacho(): void
    {
        $identity = DB::selectOne('SELECT current_database() AS db,current_user AS usuario');
        $this->assertSame('clinica_vida_salud_test', $identity->db);
        $this->assertSame('clinica_vida_salud_test', $identity->usuario);
        $this->schema = 'prueba_despacho_'.strtolower(Str::random(16));
        $previous = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        $this->beforeApplicationDestroyed(function () use ($previous): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $previous]);
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        });
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
        Carbon::setTestNow('2026-10-31T12:00:00Z');
        config(['push.habilitado' => true, 'push.vapid_publica' => self::publicaDespacho()]);
        Mail::fake();
    }

    private static function publicaDespacho(): string
    {
        return rtrim(strtr(base64_encode(hex2bin('046b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c2964fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5')), '+/', '-_'), '=');
    }

    private function envioDespacho(int $minutos = 60): array
    {
        $r = $this->citaResources();
        $inicio = CarbonImmutable::now('UTC')->addMinutes($minutos);
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => $inicio,
            'fin_at' => $inicio->addMinutes(15)]), 'id_cita');
        $u = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $u->getKey(), 'activo' => false]);
        $u = $u->fresh(['rol', 'paciente']);
        $s = new SuscripcionPush;
        $s->forceFill(['instalacion_hash' => hash('sha256', Str::random(32)), 'endpoint_hash' => hash('sha256', Str::random(32)),
            'datos_cifrados' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ficticio', 'expirationTime' => null,
                'keys' => ['p256dh' => self::publicaDespacho(), 'auth' => 'AAAAAAAAAAAAAAAAAAAAAA']],
            'id_usuario' => $u->getKey(), 'id_paciente' => $r['patient'], 'version_autorizacion' => 1,
            'huella_cuenta' => app(AutorizacionPushValidator::class)->huella($u), 'autorizada_en' => now()->subDay(),
            'actualizada_en' => now()->subDay(), 'expira_en' => now()->addDays(30)])->saveOrFail();
        app(RecordatorioService::class)->generar();
        $e = DB::table('envios_recordatorio_push')->where('id_suscripcion', $s->getKey())->sole();

        return ['envio' => (string) $e->id_envio, 'cita' => $id, 'paciente' => (string) $r['patient'],
            'usuario' => (string) $u->getKey(), 'suscripcion' => (string) $s->getKey(), 'aviso' => (string) $e->id_recordatorio];
    }
}
