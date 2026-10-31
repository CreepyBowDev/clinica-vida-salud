<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\DespachoPushService;
use Clinica\data\models\EnvioRecordatorioPush;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\recordatorios\EnvioRecordatorioPushRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\TransportePush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\DespachoPushFixtures;
use Tests\TestCase;

class DespachoPushTest extends TestCase
{
    use DespachoPushFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEsquemaDespacho();
        $this->app->instance(TransportePush::class, Mockery::mock(TransportePush::class));
    }

    private function fila(): object
    {
        return DB::table('envios_recordatorio_push')->orderBy('id_envio')->first();
    }

    private function simular(string $tipo, ?int $espera = null): void
    {
        app(TransportePush::class)->shouldReceive('enviar')->once()->andReturnUsing(function ($datos, $inicio) use ($tipo, $espera): array {
            $this->assertSame(0, DB::transactionLevel());
            $e = $this->fila();
            $this->assertSame('Procesando', $e->estado);
            $this->assertNotNull($e->codigo_reclamo);
            $this->assertSame(60, (int) CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::parse($e->reclamado_hasta)));
            $this->assertSame('https://fcm.googleapis.com/fcm/send/ficticio', $datos['endpoint']);
            $this->assertTrue($inicio->isAfter(CarbonImmutable::now('UTC')));

            return ['tipo' => $tipo, 'retry_after' => $espera, 'http' => 201, 'secreto_ignorado' => 'NO_PERSISTIR'];
        });
    }

    #[DataProvider('resultados')]
    public function test_safe_results_and_terminal_states_never_replay(string $tipo, string $estado): void
    {
        $this->envioDespacho();
        $citas = DB::table('citas')->get()->toJson();
        $avisos = DB::table('recordatorios')->get()->toJson();
        $this->simular($tipo);
        $this->assertSame(1, app(DespachoPushService::class)->despachar()['transportes_invocados']);
        $this->assertSame($estado, $this->fila()->estado);
        $this->assertSame(1, $this->fila()->intentos);
        $this->assertNull($this->fila()->codigo_reclamo);
        $this->assertNull($this->fila()->reclamado_hasta);
        $this->assertStringNotContainsString('NO_PERSISTIR', json_encode($this->fila()));
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
        $this->assertSame($citas, DB::table('citas')->get()->toJson());
        $this->assertSame($avisos, DB::table('recordatorios')->get()->toJson());
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        Mail::assertNothingSent();
    }

    public static function resultados(): array
    {
        return [['Aceptado', 'Aceptado'], ['Obsoleta', 'Descartado'], ['Fallido', 'Fallido'],
            ['Configuracion', 'Fallido'], ['Incierto', 'Incierto'], ['otro', 'Incierto']];
    }

    #[DataProvider('contextos')]
    public function test_current_context_is_revalidated_without_sending(string $caso): void
    {
        $f = $this->envioDespacho();
        match ($caso) {
            'cuenta' => DB::table('usuarios')->where('id_usuario', $f['usuario'])->update(['estado_cuenta' => 'Desactivada']),
            'clave' => DB::table('usuarios')->where('id_usuario', $f['usuario'])->update(['password_hash' => 'cambiada']),
            'vinculo' => DB::table('pacientes')->where('id_paciente', $f['paciente'])->update(['id_usuario' => null]),
            'preferencia' => DB::table('preferencias_paciente')->insert(['id_paciente' => $f['paciente'], 'recordatorios_habilitados' => false, 'preferencias_privacidad' => '{}']),
            'cita' => DB::table('citas')->where('id_cita', $f['cita'])->update(['estado' => 'Cancelada']),
            'programacion' => DB::table('citas')->where('id_cita', $f['cita'])->update(['version_programacion' => 2]),
            'aviso' => DB::table('recordatorios')->where('id_recordatorio', $f['aviso'])->update(['invalidado_en' => now()]),
            'revocacion' => DB::table('suscripciones_push')->update(['revocada_en' => now()]),
            'version' => DB::table('suscripciones_push')->update(['version_autorizacion' => 2]),
            'caducidad' => DB::table('suscripciones_push')->update(['expira_en' => now()]),
            'inicio' => CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour()),
        };
        app(TransportePush::class)->shouldNotReceive('enviar');
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
        $this->assertSame('Descartado', $this->fila()->estado);
        $this->assertSame(0, $this->fila()->intentos);
    }

    public static function contextos(): array
    {
        return array_map(fn ($c) => [$c], ['cuenta', 'clave', 'vinculo', 'preferencia', 'cita', 'programacion', 'aviso', 'revocacion', 'version', 'caducidad', 'inicio']);
    }

    #[DataProvider('reintentables')]
    public function test_three_attempts_with_backoff_and_microsecond_due_time(string $tipo): void
    {
        $this->envioDespacho();
        app(TransportePush::class)->shouldReceive('enviar')->times(3)->andReturn(['tipo' => $tipo, 'retry_after' => null]);
        $ahora = CarbonImmutable::now('UTC');
        for ($n = 1; $n <= 3; $n++) {
            $this->assertSame(1, app(DespachoPushService::class)->despachar()['transportes_invocados']);
            $this->assertSame($n, $this->fila()->intentos);
            if ($n < 3) {
                $ahora = $ahora->addSeconds($n === 1 ? 60 : 300);
                $this->assertTrue($ahora->equalTo(CarbonImmutable::parse($this->fila()->proximo_intento_en)));
                CarbonImmutable::setTestNow($ahora->subMicrosecond());
                $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
                CarbonImmutable::setTestNow($ahora);
            }
        }
        $this->assertSame('Fallido', $this->fila()->estado);
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['transportes_invocados']);
    }

    public static function reintentables(): array
    {
        return [['Reintentable'], ['NoEnviado']];
    }

    #[DataProvider('esperas')]
    public function test_retry_after_and_start_cutoff(int $espera, int $minutos, string $estado): void
    {
        $this->envioDespacho($minutos);
        $this->simular('Reintentable', $espera);
        app(DespachoPushService::class)->despachar();
        $this->assertSame($estado, $this->fila()->estado);
        if ($estado === 'Pendiente') {
            $this->assertTrue(CarbonImmutable::now('UTC')->addSeconds(max(60, $espera))->equalTo(CarbonImmutable::parse($this->fila()->proximo_intento_en)));
        }
    }

    public static function esperas(): array
    {
        return [[120, 60, 'Pendiente'], [0, 1, 'Descartado'], [3600, 60, 'Descartado'], [9999999999, 60, 'Descartado']];
    }

    public function test_disabled_channel_preserves_pending_but_recovers_expired_processing(): void
    {
        $this->envioDespacho();
        config(['push.habilitado' => false]);
        app(TransportePush::class)->shouldNotReceive('enviar');
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Pendiente', $this->fila()->estado);
        DB::table('envios_recordatorio_push')->update(['estado' => 'Procesando', 'intentos' => 1,
            'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP')]);
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Incierto', $this->fila()->estado);
        $this->assertSame('LEASE_VENCIDO', $this->fila()->resultado_seguro);
        $this->assertSame(1, $this->fila()->intentos);
    }

    public function test_lease_expiration_at_microsecond_boundary_never_reclaims(): void
    {
        $this->envioDespacho();
        $hasta = CarbonImmutable::now('UTC')->addMicrosecond();
        DB::table('envios_recordatorio_push')->update(['estado' => 'Procesando', 'intentos' => 1,
            'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => $hasta->format('Y-m-d H:i:s.uP')]);
        app(TransportePush::class)->shouldNotReceive('enviar');
        $this->assertSame(0, app(DespachoPushService::class)->despachar()['envios_revisados']);
        CarbonImmutable::setTestNow($hasta);
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Incierto', $this->fila()->estado);
    }

    public function test_second_validation_after_claim_discards_changed_consent(): void
    {
        $this->envioDespacho();
        $repo = Mockery::mock(EnvioRecordatorioPushRepository::class)->makePartial();
        $repo->shouldReceive('guardar')->andReturnUsing(function (EnvioRecordatorioPush $e, array $datos, CarbonImmutable $ahora): void {
            (new EnvioRecordatorioPushRepository)->guardar($e, $datos, $ahora);
            if ($datos['estado'] === 'Procesando') {
                DB::table('suscripciones_push')->update(['version_autorizacion' => 2]);
            }
        });
        $this->app->instance(EnvioRecordatorioPushRepository::class, $repo);
        app(TransportePush::class)->shouldNotReceive('enviar');
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Descartado', $this->fila()->estado);
        $this->assertSame(1, $this->fila()->intentos);
    }

    public function test_result_at_exact_lease_expiration_becomes_uncertain(): void
    {
        $this->envioDespacho();
        app(TransportePush::class)->shouldReceive('enviar')->once()->andReturnUsing(function (): array {
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(60));

            return ['tipo' => 'Aceptado'];
        });
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Incierto', $this->fila()->estado);
        $this->assertSame('LEASE_VENCIDO', $this->fila()->resultado_seguro);
    }

    public function test_obsolete_response_does_not_revoke_new_authorization(): void
    {
        $this->envioDespacho();
        app(TransportePush::class)->shouldReceive('enviar')->once()->andReturnUsing(function (): array {
            DB::table('suscripciones_push')->update(['version_autorizacion' => 2]);

            return ['tipo' => 'Obsoleta'];
        });
        app(DespachoPushService::class)->despachar();
        $this->assertNull(DB::table('suscripciones_push')->value('revocada_en'));
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'PUSH_REVOCADO')->count());
    }

    public function test_obsolete_revokes_matching_subscription_and_pending_pairs_atomically(): void
    {
        $f = $this->envioDespacho();
        // Otro aviso del mismo dispositivo, aún Pendiente; no es otro envío real.
        $r = (array) $this->fila();
        unset($r['id_envio']);
        $aviso = DB::table('recordatorios')->insertGetId(['id_cita' => $f['cita'], 'version_programacion' => 2,
            'generado_en' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP')], 'id_recordatorio');
        DB::table('envios_recordatorio_push')->insert(array_replace($r, ['id_recordatorio' => $aviso]));
        $this->simular('Obsoleta');
        app(DespachoPushService::class)->despachar(1);
        $this->assertNotNull(DB::table('suscripciones_push')->value('revocada_en'));
        $this->assertSame(2, DB::table('envios_recordatorio_push')->where('estado', 'Descartado')->count());
        $evento = DB::table('bitacora')->where('tipo_evento', 'PUSH_REVOCADO')->sole();
        $this->assertSame('SISTEMA', $evento->tipo_actor);
        $this->assertNull($evento->id_usuario_responsable);
        $this->assertStringNotContainsString('fcm.googleapis', json_encode($evento));
    }

    public function test_audit_failure_rolls_back_revocation_and_leaves_processing_for_uncertain_recovery(): void
    {
        $this->envioDespacho();
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('suscripcionPushObsoleta')->once()->andThrow(new RuntimeException('ficticio'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $this->simular('Obsoleta');
        try {
            app(DespachoPushService::class)->despachar();
            $this->fail('Debe propagar fallo SQL/auditoría sin repetir transporte.');
        } catch (RuntimeException $e) {
            $this->assertSame('ficticio', $e->getMessage());
        }
        $this->assertNull(DB::table('suscripciones_push')->value('revocada_en'));
        $this->assertSame('Procesando', $this->fila()->estado);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        app(DespachoPushService::class)->despachar();
        $this->assertSame('Incierto', $this->fila()->estado);
    }

    #[DataProvider('excepciones')]
    public function test_configuration_stops_batch_and_unexpected_transport_exception_is_uncertain(bool $configuracion): void
    {
        $this->envioDespacho();
        if ($configuracion) {
            $this->envioDespacho();
        }
        app(TransportePush::class)->shouldReceive('enviar')->once()->andThrow($configuracion
            ? new AppError('PUSH_NO_CONFIGURADO', 'ficticio') : new RuntimeException('secreto-ficticio'));
        $r = app(DespachoPushService::class)->despachar(1);
        $this->assertSame($configuracion, $r['configuracion_invalida']);
        $this->assertSame($configuracion ? 'Fallido' : 'Incierto', $this->fila()->estado);
        if ($configuracion) {
            $this->assertSame(1, DB::table('envios_recordatorio_push')->where('estado', 'Pendiente')->where('intentos', 0)->count());
        }
    }

    public static function excepciones(): array
    {
        return [[true], [false]];
    }

    public function test_command_validation_scheduler_and_cursor_process_all_batches(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->envioDespacho();
        }
        app(TransportePush::class)->shouldReceive('enviar')->times(3)->andReturn(['tipo' => 'Aceptado']);
        $this->artisan('recordatorios:despachar', ['--lote' => '0'])->assertExitCode(1);
        $this->artisan('recordatorios:despachar', ['--lote' => '1001'])->assertExitCode(1);
        $this->artisan('recordatorios:despachar', ['--lote' => '1'])->assertExitCode(0);
        $this->assertSame(3, DB::table('envios_recordatorio_push')->where('estado', 'Aceptado')->count());
        $events = array_values(array_filter(Schedule::events(), fn ($e) => str_contains($e->command ?? '', 'recordatorios:despachar')));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
