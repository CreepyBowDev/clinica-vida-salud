<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\data\models\SuscripcionPush;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class GeneracionRecordatoriosConcurrenteTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    #[DataProvider('operaciones')]
    public function test_two_real_generators_wait_then_commit_one_notice_and_one_outbox_row(bool $manual, bool $distintas): void
    {
        $schema = 'prueba_recordatorio_'.strtolower(Str::random(16));
        $previous = DB::selectOne('SHOW search_path')->search_path;
        $workers = [];
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
            foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
                (require $file)->up();
            }
            DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
            CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
            Carbon::setTestNow('2026-10-31T12:00:00Z');
            $r = $this->citaResources();
            $inicio = CarbonImmutable::now('UTC')->addHour();
            DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15)]));
            $u = $this->usuarioAuth('Paciente', vincular: false);
            DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $u->getKey()]);
            $u = $u->fresh(['rol', 'paciente']);
            $s = new SuscripcionPush;
            $s->forceFill(['instalacion_hash' => hash('sha256', 'instalacion-ficticia'), 'endpoint_hash' => hash('sha256', 'endpoint-ficticio'),
                'datos_cifrados' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ficticio'], 'id_usuario' => $u->getKey(), 'id_paciente' => $r['patient'],
                'version_autorizacion' => 1, 'huella_cuenta' => app(AutorizacionPushValidator::class)->huella($u),
                'autorizada_en' => now(), 'actualizada_en' => now(), 'expira_en' => now()->addDay()])->saveOrFail();
            $admin = $manual ? $this->usuarioAuth('Administrador') : null;
            $segundo = $manual && $distintas ? $this->usuarioAuth('Administrador') : $admin;
            $proxima = app(RecordatorioRepository::class)->proxima((string) $r['patient'], CarbonImmutable::now('UTC'));
            $datos = ['id_paciente' => (string) $r['patient'], 'id_cita' => (string) $proxima->id_cita,
                'revision' => hash_hmac('sha256', json_encode($proxima, JSON_THROW_ON_ERROR), (string) config('app.key')),
                'operacion' => (string) Str::uuid()];
            DB::beginTransaction();
            app(ContinuidadAdministrativaService::class)->coordinar();
            for ($i = 0; $i < 2; $i++) {
                $args = [PHP_BINARY, base_path('tests/Fixtures/recordatorios-worker.php'), $schema];
                if ($manual) {
                    $args[] = (string) ($i === 0 ? $admin : $segundo)->getKey();
                    $args[] = base64_encode(json_encode($distintas ? [...$datos, 'operacion' => (string) Str::uuid()] : $datos));
                }
                $p = new Process($args, base_path());
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $pids = [];
            $deadline = microtime(true) + 10;
            do {
                foreach ($workers as $i => $w) {
                    $line = explode("\n", $w->getOutput())[0];
                    $d = json_decode($line, true);
                    if (isset($d['ready'])) {
                        $pids[$i] = $d['ready'];
                    }
                }
                if (count($pids) === 2 && DB::table('pg_locks')->whereIn('pid', $pids)->where('locktype', 'advisory')->where('granted', false)->count() === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, array_unique($pids));
            $this->assertSame(2, DB::table('pg_locks')->whereIn('pid', $pids)->where('locktype', 'advisory')->where('granted', false)->count(),
                implode("\n", array_map(fn ($w) => $w->getOutput().$w->getErrorOutput(), $workers)));
            DB::commit();
            $created = $outbox = 0;
            $resultados = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertTrue($w->isSuccessful(), $w->getErrorOutput().$w->getOutput());
                $lines = array_values(array_filter(explode("\n", trim($w->getOutput()))));
                $r = json_decode(end($lines), true)['result'];
                $resultados[] = $r;
                $created += $r['avisos_creados'] ?? 0;
                $outbox += $r['envios_preparados'] ?? 0;
            }
            if (! $manual) {
                $this->assertSame(1, $created);
                $this->assertSame(1, $outbox);
            } elseif ($distintas) {
                $this->assertCount(1, array_filter($resultados, fn ($r) => ($r['conflicto'] ?? null) === 'RECORDATORIO_MANUAL_EXISTENTE'));
            } else {
                $this->assertSame($resultados[0], $resultados[1]);
            }
            $this->assertSame(1, DB::table('recordatorios')->count());
            $this->assertSame(1, DB::table('envios_recordatorio_push')->where('estado', 'Pendiente')->where('intentos', 0)->count());
            $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'RECORDATORIO_GENERADO')->count());
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $previous]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }

    public static function operaciones(): array
    {
        return [[false, false], [true, false], [true, true]];
    }
}
