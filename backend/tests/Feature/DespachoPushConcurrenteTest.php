<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\DespachoPushService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Clinica\shared\utils\TransportePush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Fixtures\DespachoPushFixtures;
use Tests\TestCase;

class DespachoPushConcurrenteTest extends TestCase
{
    use DespachoPushFixtures;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEsquemaDespacho();
        $t = Mockery::mock(TransportePush::class);
        $t->shouldNotReceive('enviar');
        $this->app->instance(TransportePush::class, $t);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$p, $input]) {
            $input->close();
            if ($p->isRunning()) {
                $p->stop();
            }
        }
        parent::tearDown();
    }

    private function worker(string $modo, string $tipo = 'Aceptado'): array
    {
        $input = new InputStream;
        $p = new Process([PHP_BINARY, base_path('tests/Fixtures/despacho-push-worker.php'), $this->schema, $modo, $tipo], base_path());
        $p->setInput($input);
        $p->setTimeout(20);
        $p->start();
        $this->workers[] = [$p, $input];

        return [$p, $input];
    }

    private function esperar(Process $p, string $evento): array
    {
        $hasta = microtime(true) + 10;
        do {
            foreach (explode("\n", $p->getOutput()) as $line) {
                $d = json_decode($line, true);
                if (isset($d[$evento])) {
                    return $d;
                }
            }
            if (! $p->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $hasta);
        $this->fail('No alcanzó '.$evento.': '.$p->getOutput().$p->getErrorOutput());
    }

    private function terminarWorker(Process $p, InputStream $input): void
    {
        $input->write("continuar\n");
        $input->close();
        $p->wait();
        $this->assertTrue($p->isSuccessful(), $p->getOutput().$p->getErrorOutput());
    }

    public function test_two_dispatchers_compete_then_only_one_invokes_transport_without_sql_locks(): void
    {
        $this->envioDespacho();
        DB::beginTransaction();
        app(ContinuidadAdministrativaService::class)->coordinar();
        [$a, $ia] = $this->worker('transport');
        [$b, $ib] = $this->worker('transport');
        $pids = [$this->esperar($a, 'ready')['ready'], $this->esperar($b, 'ready')['ready']];
        $this->assertCount(2, array_unique($pids));
        $hasta = microtime(true) + 10;
        do {
            $bloqueados = DB::table('pg_locks')->whereIn('pid', $pids)->where('locktype', 'advisory')->where('granted', false)->count();
            if ($bloqueados === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $hasta);
        $this->assertSame(2, $bloqueados);
        DB::commit();
        $hasta = microtime(true) + 10;
        do {
            if (str_contains($a->getOutput().$b->getOutput(), '"sending":true')) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $hasta);
        $emisor = str_contains($a->getOutput(), '"sending":true') ? $a : $b;
        $otro = $emisor === $a ? $b : $a;
        $this->assertSame(0, $this->esperar($emisor, 'sending')['tx']);
        $otro->wait();
        $this->assertTrue($otro->isSuccessful(), $otro->getOutput().$otro->getErrorOutput());
        $this->assertStringNotContainsString('"sending":true', $otro->getOutput());
        // Durante HTTP simulado se puede adquirir continuidad y planificación desde otra conexión.
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            app(ContinuidadAdministrativaService::class)->coordinar();
            app(PlanificacionRepository::class)->coordinar();
        });
        $this->assertSame('Procesando', DB::table('envios_recordatorio_push')->value('estado'));
        $this->terminarWorker($emisor, $emisor === $a ? $ia : $ib);
        $this->assertSame('Aceptado', DB::table('envios_recordatorio_push')->value('estado'));
        $this->assertSame(1, DB::table('envios_recordatorio_push')->value('intentos'));
    }

    #[DataProvider('cambios')]
    public function test_committed_change_between_claim_and_preflight_prevents_transport(string $caso): void
    {
        $f = $this->envioDespacho();
        [$p, $input] = $this->worker('claim');
        $this->assertSame(0, $this->esperar($p, 'claimed')['tx']);
        DB::transaction(function () use ($caso, $f): void {
            app(ContinuidadAdministrativaService::class)->coordinar();
            match ($caso) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $f['usuario'])->update(['estado_cuenta' => 'Desactivada']),
                'vinculo' => DB::table('pacientes')->where('id_paciente', $f['paciente'])->update(['id_usuario' => null]),
                'cita' => DB::table('citas')->where('id_cita', $f['cita'])->update(['estado' => 'Cancelada']),
                'programacion' => DB::table('citas')->where('id_cita', $f['cita'])->update(['version_programacion' => 2]),
                'preferencia' => DB::table('preferencias_paciente')->insert(['id_paciente' => $f['paciente'], 'recordatorios_habilitados' => false, 'preferencias_privacidad' => '{}']),
                'consentimiento' => app(SuscripcionPushRepository::class)->revocar(app(SuscripcionPushRepository::class)->porId($f['suscripcion'])),
            };
        });
        $this->terminarWorker($p, $input);
        $this->assertStringNotContainsString('"sending":true', $p->getOutput());
        $this->assertSame('Descartado', DB::table('envios_recordatorio_push')->value('estado'));
        $this->assertNull(DB::table('envios_recordatorio_push')->value('codigo_reclamo'));
        $this->assertSame(1, DB::table('envios_recordatorio_push')->value('intentos'));
    }

    public static function cambios(): array
    {
        return array_map(fn ($c) => [$c], ['cuenta', 'vinculo', 'cita', 'programacion', 'preferencia', 'consentimiento']);
    }

    public function test_expired_lease_is_uncertain_and_late_accepted_result_cannot_overwrite_or_replay(): void
    {
        $this->envioDespacho();
        [$p, $input] = $this->worker('transport');
        $this->assertSame(0, $this->esperar($p, 'sending')['tx']);
        CarbonImmutable::setTestNow('2026-10-31T12:01:00Z');
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
        $this->assertSame('Incierto', DB::table('envios_recordatorio_push')->value('estado'));
        $this->terminarWorker($p, $input);
        $this->assertSame('Incierto', DB::table('envios_recordatorio_push')->value('estado'));
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
        $this->assertSame(1, DB::table('envios_recordatorio_push')->value('intentos'));
    }

    public function test_wrong_attempt_token_cannot_persist_late_result_or_revoke_subscription(): void
    {
        $this->envioDespacho();
        [$p, $input] = $this->worker('transport', 'Obsoleta');
        $this->esperar($p, 'sending');
        $nuevo = (string) Str::uuid();
        DB::table('envios_recordatorio_push')->update(['codigo_reclamo' => $nuevo]); // Fixture de token distinto, no segundo envío.
        $this->terminarWorker($p, $input);
        $this->assertSame($nuevo, DB::table('envios_recordatorio_push')->value('codigo_reclamo'));
        $this->assertSame('Procesando', DB::table('envios_recordatorio_push')->value('estado'));
        $this->assertNull(DB::table('suscripciones_push')->value('revocada_en'));
    }

    public function test_old_obsolete_response_after_concurrent_renewal_does_not_revoke_current_consent(): void
    {
        $this->envioDespacho();
        [$p, $input] = $this->worker('transport', 'Obsoleta');
        $this->esperar($p, 'sending');
        DB::transaction(function (): void {
            app(ContinuidadAdministrativaService::class)->coordinar();
            DB::table('suscripciones_push')->update(['version_autorizacion' => 2]);
        });
        $this->terminarWorker($p, $input);
        $this->assertSame('Descartado', DB::table('envios_recordatorio_push')->value('estado'));
        $this->assertNull(DB::table('suscripciones_push')->value('revocada_en'));
        $this->assertSame(2, DB::table('suscripciones_push')->value('version_autorizacion'));
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'PUSH_REVOCADO')->count());
    }
}
