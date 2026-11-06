<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\ConfiguracionStripe;
use Clinica\business\services\pagos\EventoStripeService;
use Clinica\business\services\pagos\RecuperacionStripeService;
use Clinica\business\services\pagos\StripeGateway;
use Clinica\business\services\pagos\StripePagoService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\Fixtures\PayPalHttpSimulado;
use Tests\Fixtures\StripeGatewayHttpSimulado;
use Tests\Fixtures\StripeHttpSimulado;
use Tests\Fixtures\StripeSimulado;
use Tests\TestCase;

class PagosStripeTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $path;

    private StripeSimulado $stripe;

    private array $cookies;

    private string $obligacion;

    private string $cita;

    private string $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_stripe_'.strtolower(Str::random(16));
        $this->path = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        DB::table('configuracion_clinica')->insert(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'pagos.stripe' => [
            'habilitado' => true, 'secret_key' => 'sk_test_FicticiaNoValida', 'cuenta' => 'acct_Ficticia',
            'webhook_secret' => 'whsec_Ficticio', 'api_version' => '2026-10-28.endive', 'origen_retorno' => 'https://clinica.example.test']]);
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://clinica.example.test']]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
        $this->stripe = new StripeSimulado(app(ConfiguracionStripe::class));
        $this->app->instance(StripeGateway::class, $this->stripe);
        $r = $this->citaResources();
        $u = $this->usuarioAuth('Paciente', vincular: false);
        $this->actor = (string) $u->getKey();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $u->getKey()]);
        $this->cita = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['incorporada_finanzas' => true]), 'id_cita');
        $this->obligacion = (string) Str::uuid();
        DB::table('obligaciones_pago')->insert(['id_obligacion' => $this->obligacion, 'id_cita' => $this->cita,
            'centavos_bob' => 704, 'centavos_usd' => 101, 'emitida_por' => $r['actor'],
            'emitida_en' => '2026-11-01T10:00:00Z', 'vencimiento_at' => '2026-11-01T12:00:00Z']);
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $u)->assertOk();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            CarbonImmutable::setTestNow();
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->path]);
            // Únicamente el esquema aleatorio creado por este caso, nunca el esquema antiguo ajeno.
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        } finally {
            parent::tearDown();
        }
    }

    private function inicio(?string $clave = null, ?string $revision = null): TestResponse
    {
        $revision ??= $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->json('data.revision_pago');

        return $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => $revision], ['Idempotency-Key' => $clave ?? (string) Str::uuid()]);
    }

    private function procesar(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(3));
        app(RecuperacionStripeService::class)->lote(50);
    }

    private function cancelar(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$this->cita.'/gestion')->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$this->cita.'/cancelacion',
            ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en'],
                'motivo' => 'Cancelación ficticia'])->assertOk();
    }

    private function webhook(array $obj, string $tipo = 'checkout.session.completed', string $id = 'evt_Ficticio', array $extra = []): TestResponse
    {
        $bytes = json_encode(array_replace(['id' => $id, 'object' => 'event', 'livemode' => false,
            'api_version' => '2026-10-28.endive', 'type' => $tipo, 'data' => ['object' => $obj]], $extra), JSON_THROW_ON_ERROR);
        $t = time();
        $firma = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$bytes, 'whsec_Ficticio');

        return $this->call('POST', '/api/webhooks/pagos/stripe', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $firma, 'HTTP_ORIGIN' => 'https://hostil.example.test'], $bytes);
    }

    public function test_checkout_is_durable_idempotent_and_uses_historical_cents(): void
    {
        $clave = (string) Str::uuid();
        $revision = $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)->assertOk()->json('data.revision_pago');
        $r = $this->inicio($clave, $revision)->assertCreated()->assertJsonPath('data.estado', 'RequiereAccion');
        $this->inicio($clave, $revision)->assertOk()->assertJsonPath('meta.repetida', true)->assertJsonPath('data.id', $r->json('data.id'));
        $this->assertSame(1, DB::table('intentos_pago')->count());
        $creaciones = array_values(array_filter($this->stripe->llamadas, fn ($x) => $x[0] === 'crear'));
        $this->assertCount(1, $creaciones);
        $this->assertSame(101, $creaciones[0][1][0]['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $creaciones[0][1][0]['line_items'][0]['price_data']['currency']);
        $this->assertNotSame($clave, $creaciones[0][1][1]);
        $this->inicio($clave, $revision.'alterada')->assertConflict();
    }

    public function test_foreign_obligation_and_roles_cannot_start_payments(): void
    {
        $revision = 'no-importa';
        $u = $this->usuarioAuth('Paciente');
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $u)->assertOk();
        $this->inicio(revision: $revision)->assertNotFound();
        foreach (['Administrador', 'Recepcionista', 'Médico'] as $rol) {
            $this->cookies = $this->csrfAuth();
            $this->loginAuth($this->cookies, $this->usuarioAuth($rol))->assertOk();
            $this->inicio(revision: $revision)->assertForbidden();
        }
        $this->assertCount(0, $this->stripe->llamadas);
    }

    public static function configuraciones(): array
    {
        return [['secret_key', 'sk_live_Prohibida'], ['habilitado', false], ['cuenta', 'incorrecta'],
            ['api_version', 'latest'], ['origen_retorno', 'https://host.test/ruta']];
    }

    #[DataProvider('configuraciones')]
    public function test_unsafe_or_disabled_configuration_is_rejected(string $k, mixed $v): void
    {
        config(['pagos.stripe.'.$k => $v]);
        $this->inicio(revision: 'ficticia')->assertStatus(503);
        $this->assertSame(0, DB::table('intentos_pago')->count());
    }

    public function test_webhook_signature_original_bytes_limits_and_live_rejection(): void
    {
        $this->call('POST', '/api/webhooks/pagos/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=0,v1=falsa'], '{}')->assertBadRequest();
        $this->webhook([], extra: ['livemode' => true])->assertBadRequest();
        $this->webhook([], extra: ['account' => 'acct_Ajena'])->assertBadRequest();
        $this->call('POST', '/api/webhooks/pagos/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', 1048577))->assertStatus(413);
        $this->assertSame(0, DB::table('eventos_pago')->count());
    }

    public function test_duplicate_event_applies_a_single_verified_payment(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $sesion = DB::table('intentos_pago')->where('id_intento', $id)->value('referencia_inicio');
        $s = $this->stripe->pagar($sesion);
        $this->webhook($s)->assertNoContent();
        $this->webhook($s)->assertNoContent();
        app(EventoStripeService::class)->lote();
        app(EventoStripeService::class)->lote();
        $this->assertSame(1, DB::table('eventos_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->where('aplicado', true)->count());
        $this->assertSame('Exitoso', DB::table('intentos_pago')->value('estado'));
        $this->assertSame('Programada', DB::table('citas')->value('estado'));
    }

    public function test_signed_unknown_event_is_durably_ignored_and_unlinked_is_recoverable(): void
    {
        $this->webhook([], 'customer.created')->assertNoContent();
        $this->assertSame('Ignorado', DB::table('eventos_pago')->value('estado'));
        $this->webhook(['object' => 'checkout.session', 'id' => 'cs_test_sinVinculo'], id: 'evt_SinVinculo')->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->assertSame('PendienteVinculo', DB::table('eventos_pago')->where('id_externo', 'evt_SinVinculo')->value('estado'));
    }

    public function test_event_preceding_create_response_recovers_without_another_creation(): void
    {
        $this->stripe->fallos['crear_despues'] = 1;
        $this->inicio()->assertStatus(202);
        $s = $this->stripe->pagar(array_key_first($this->stripe->sesiones));
        $this->webhook($s)->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame('Exitosa', DB::table('operaciones_pago')->where('tipo', 'Crear')->value('estado'));
        $this->assertCount(1, array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'crear'));
    }

    public static function incompatibilidades(): array
    {
        return [['amount_total', 999], ['currency', 'bob'], ['livemode', true], ['client_reference_id', 'ajena']];
    }

    #[DataProvider('incompatibilidades')]
    public function test_incompatible_evidence_does_not_settle(string $campo, mixed $valor): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $ref = array_key_first($this->stripe->sesiones);
        $this->stripe->pagar($ref);
        $this->stripe->sesiones[$ref][$campo] = $valor;
        $op = DB::transaction(fn () => app(StripePagoService::class)->consulta(app(StripePagoRepository::class)->intento($id)));
        app(RecuperacionStripeService::class)->ejecutar((string) $op->getKey());
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertSame('Incierta', $op->refresh()->estado);
    }

    public function test_cancel_open_checkout_closes_it_without_a_refund(): void
    {
        $this->inicio()->assertCreated();
        $this->cancelar();
        $this->procesar();
        $this->assertSame('Cerrado', DB::table('intentos_pago')->value('estado'));
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
        $this->assertFalse(DB::table('obligaciones_pago')->value('exigible'));
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
    }

    public function test_confirmed_late_payment_after_cancellation_is_refunded_once(): void
    {
        $this->inicio()->assertCreated();
        $this->cancelar();
        $this->stripe->pagar(array_key_first($this->stripe->sesiones));
        $this->procesar();
        $this->procesar();
        $this->procesar();
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
        $this->assertCount(0, array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'cerrar'));
        $this->assertCount(1, array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'devolver'));
    }

    public function test_cancel_paid_checkout_prepares_refund_and_recovers_a_lost_response(): void
    {
        $this->inicio()->assertCreated();
        $s = $this->stripe->pagar(array_key_first($this->stripe->sesiones));
        $this->webhook($s)->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->cancelar();
        $this->stripe->fallos['devolucion'] = 1;
        $this->procesar();
        $this->assertSame('Incierta', DB::table('devoluciones_pago')->value('estado'));
        $this->procesar();
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertCount(1, array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'devolver'));
    }

    public function test_timeout_keeps_exclusion_and_recovery_reuses_the_external_key(): void
    {
        $revision = $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->json('data.revision_pago');
        $this->stripe->fallos['crear_despues'] = 1;
        $this->inicio(revision: $revision)->assertStatus(202);
        $this->inicio(revision: $revision)->assertConflict();
        $this->procesar();
        $creaciones = array_values(array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'crear'));
        $this->assertCount(2, $creaciones);
        $this->assertSame($creaciones[0][1], $creaciones[1][1]);
        $this->assertCount(1, $this->stripe->sesiones);
    }

    public function test_close_timeout_never_undoes_cancellation(): void
    {
        $this->inicio()->assertCreated();
        $this->cancelar();
        $this->stripe->fallos['cerrar'] = 1;
        $this->procesar();
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Cerrar')->value('estado'));
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
        $this->procesar();
        $this->assertSame('Cerrado', DB::table('intentos_pago')->value('estado'));
    }

    public function test_uncertain_creation_is_not_reissued_outside_the_key_window(): void
    {
        $this->stripe->fallos['crear'] = 1;
        $this->inicio()->assertStatus(202);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(25));
        app(RecuperacionStripeService::class)->lote();
        $this->assertCount(1, array_filter($this->stripe->llamadas, fn ($l) => $l[0] === 'crear'));
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Crear')->value('estado'));
    }

    public function test_failed_audit_rolls_back_verified_payment_and_is_recoverable(): void
    {
        $this->inicio()->assertCreated();
        $s = $this->stripe->pagar(array_key_first($this->stripe->sesiones));
        $this->webhook($s)->assertNoContent();
        $mock = \Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('stripe')->andThrow(new \RuntimeException('Fallo simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        app(EventoStripeService::class)->lote();
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertSame('Incierto', DB::table('eventos_pago')->value('estado'));
        $this->app->instance(BitacoraRepository::class, new BitacoraRepository);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(3));
        app(EventoStripeService::class)->lote();
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_official_sdk_uses_fixed_endpoint_version_account_and_idempotency(): void
    {
        $http = new StripeHttpSimulado;
        $http->respuestas = [['id' => 'acct_Ficticia', 'object' => 'account'],
            ['id' => 'cs_test_Ficticia', 'object' => 'checkout.session', 'livemode' => false]];
        $gateway = new StripeGatewayHttpSimulado(app(ConfiguracionStripe::class), $http);
        $clave = (string) Str::uuid();
        $gateway->crear(['mode' => 'payment'], $clave);
        $this->assertCount(2, $http->peticiones);
        $this->assertSame('https://api.stripe.com/v1/account', $http->peticiones[0]['absUrl']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $http->peticiones[1]['absUrl']);
        $headers = implode("\n", $http->peticiones[1]['headers']);
        $this->assertStringContainsString('2026-10-28.endive', $headers);
        $this->assertStringContainsString($clave, $headers);
        $this->assertSame(0, $http->peticiones[1]['maxNetworkRetries']);
        $http->respuestas = [['id' => 'acct_Ajena', 'object' => 'account']];
        $this->expectException(AppError::class);
        $gateway->crear(['mode' => 'payment'], (string) Str::uuid());
    }

    public function test_human_routes_still_require_origin_session_and_csrf(): void
    {
        $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => 'ficticia'], ['Origin' => 'https://hostil.example.test'])->assertForbidden();
        $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => 'ficticia'], csrf: false)->assertStatus(419);
        $this->assertCount(0, $this->stripe->llamadas);
    }

    public function test_duplicate_event_with_different_content_preserves_original_receipt(): void
    {
        $this->webhook([], 'customer.created')->assertNoContent();
        $this->webhook(['id' => 'distinto'], 'customer.created')->assertConflict();
        $this->assertSame(1, DB::table('eventos_pago')->count());
    }

    public function test_pending_refund_is_not_reported_as_success(): void
    {
        $this->inicio()->assertCreated();
        $this->webhook($this->stripe->pagar(array_key_first($this->stripe->sesiones)))->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->cancelar();
        $this->stripe->duranteRed = function (string $metodo, array $datos): void {
            if ($metodo === 'devolucion') {
                $this->stripe->reembolsos[$datos[0]]['status'] = 'pending';
            }
        };
        $this->procesar();
        $this->assertSame('Procesando', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Devolver')->value('estado'));
        $this->stripe->duranteRed = null;
        foreach ($this->stripe->reembolsos as &$r) {
            $r['status'] = 'succeeded';
        }
        $this->procesar();
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
    }

    public function test_default_flags_are_off_and_production_environment_is_rejected(): void
    {
        $defaults = require base_path('config/pagos.php');
        $this->assertFalse($defaults['incorporacion_habilitada']);
        $this->assertFalse($defaults['stripe']['habilitado']);
        $this->app['env'] = 'production';
        $this->expectException(AppError::class);
        app(ConfiguracionStripe::class)->exigir();
    }

    public function test_real_process_lease_excludes_another_worker_and_cancellation_does_not_wait_for_network(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->stripe->pagar(array_key_first($this->stripe->sesiones));
        $op = DB::transaction(fn () => app(StripePagoService::class)->consulta(app(StripePagoRepository::class)->intento($id)));
        $datos = ['schema' => $this->schema, 'operacion' => (string) $op->getKey(),
            'ahora' => CarbonImmutable::now('UTC')->toIso8601String(), 'sesiones' => $this->stripe->sesiones];
        $procesos = [];
        try {
            foreach ([true, false] as $pausa) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/stripe-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($p);
                $procesos[] = [$p, $pipes];
                fwrite($pipes[0], json_encode([...$datos, 'pausa' => $pausa], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $linea = $this->lineaWorker($pipes[1]);
                if ($pausa) {
                    $this->assertTrue($linea['red']);
                    $this->assertSame(0, $linea['transaccion']);
                    $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $linea['pid']);
                    // Puede confirmar cancelación mientras el otro proceso está detenido en red.
                    $this->cancelar();
                } else {
                    $this->assertTrue($linea['fin']);
                    $this->assertSame(0, $linea['llamadas']);
                }
            }
            fwrite($procesos[0][1][0], "CONTINUAR\n");
            fflush($procesos[0][1][0]);
            $this->assertTrue($this->lineaWorker($procesos[0][1][1])['fin']);
            $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
            $this->assertSame(1, DB::table('cobros_pago')->count());
            $this->assertSame(1, DB::table('devoluciones_pago')->count());
        } finally {
            foreach ($procesos as [$p, $pipes]) {
                proc_terminate($p);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($p);
            }
        }
    }

    private function lineaWorker($pipe): array
    {
        $fin = microtime(true) + 15;
        $texto = '';
        do {
            $texto .= fgets($pipe) ?: '';
            if (str_ends_with($texto, "\n")) {
                return json_decode($texto, true, 32, JSON_THROW_ON_ERROR);
            }
            usleep(10000);
        } while (microtime(true) < $fin);
        throw new \RuntimeException('Venció la barrera del worker Stripe simulado.');
    }

    public function test_client_cannot_override_price_provider_or_return_urls(): void
    {
        $revision = $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->json('data.revision_pago');
        foreach (['amount' => 1, 'currency' => 'bob', 'success_url' => 'https://hostil.example.test'] as $k => $v) {
            $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
                ['proveedor' => 'stripe', 'revision_pago' => $revision, $k => $v],
                ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('operaciones_pago')->count());
    }

    public function test_cancellation_before_first_send_never_creates_an_external_checkout(): void
    {
        $revision = $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->json('data.revision_pago');
        $r = app(StripePagoService::class)->iniciar($this->obligacion, $revision, (string) Str::uuid());
        $this->cancelar();
        app(RecuperacionStripeService::class)->ejecutar($r['operacion']);
        $this->procesar();
        $this->assertSame('Cerrado', DB::table('intentos_pago')->value('estado'));
        $this->assertCount(0, $this->stripe->llamadas);
        $this->assertSame(0, DB::table('operaciones_pago')->whereIn('estado', ['Pendiente', 'Procesando', 'Incierta'])->count());
    }

    public function test_completed_event_without_corroborating_charge_does_not_settle(): void
    {
        $this->inicio()->assertCreated();
        $ref = array_key_first($this->stripe->sesiones);
        $this->stripe->sesiones[$ref]['status'] = 'complete';
        $this->stripe->sesiones[$ref]['payment_status'] = 'paid';
        $this->webhook($this->stripe->sesiones[$ref])->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertSame('Incierto', DB::table('eventos_pago')->value('estado'));
    }

    public function test_receipt_is_not_acknowledged_when_durable_write_fails(): void
    {
        $mock = \Mockery::mock(StripePagoRepository::class)->makePartial();
        $mock->shouldReceive('guardar')->andThrow(new \RuntimeException('Fallo de persistencia simulado'));
        $this->app->instance(StripePagoRepository::class, $mock);
        $this->webhook([], 'customer.created')->assertStatus(503)->assertJsonPath('error.code', 'PAGO_EVENTO_NO_PERSISTIDO');
        $this->assertSame(0, DB::table('eventos_pago')->count());
    }

    public function test_cancellation_audit_failure_rolls_back_its_refund_preparation(): void
    {
        $this->inicio()->assertCreated();
        $this->webhook($this->stripe->pagar(array_key_first($this->stripe->sesiones)))->assertNoContent();
        app(EventoStripeService::class)->lote();
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$this->cita.'/gestion')->assertOk()->json('data');
        $mock = \Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('citaCancelada')->andThrow(new \RuntimeException('Fallo de auditoría simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$this->cita.'/cancelacion', [
            'version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en']])->assertStatus(500);
        $this->assertSame('Programada', DB::table('citas')->value('estado'));
        $this->assertTrue(DB::table('obligaciones_pago')->value('exigible'));
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    #[DataProvider('proveedoresFaseOcho')]
    public function test_f8_payment_posts_require_csrf_and_allowed_origin_without_creating_effects(string $proveedor): void
    {
        $uri = '/api/pagos/obligaciones/'.$this->obligacion.'/intentos';
        $datos = ['proveedor' => $proveedor, 'revision_pago' => 'revision-no-utilizada'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->navegador($this->cookies, 'POST', $uri, $datos, $headers, csrf: false)
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_INVALIDO');
        $this->navegador($this->cookies, 'POST', $uri, $datos,
            [...$headers, 'X-XSRF-TOKEN' => 'token-invalido'], csrf: false)
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_INVALIDO');
        $this->navegador($this->cookies, 'POST', $uri, $datos,
            [...$headers, 'Origin' => 'https://origen-hostil.example.test'])
            ->assertForbidden()->assertJsonPath('error.code', 'ORIGEN_NO_PERMITIDO');
        $this->assertSame(0, DB::table('intentos_pago')->count());
        $this->assertSame(0, DB::table('operaciones_pago')->count());
        $this->assertSame([], $this->stripe->llamadas);
    }

    #[DataProvider('proveedoresFaseOcho')]
    public function test_f8_real_throttle_preserves_retry_after_and_same_key_without_duplicate_effects(string $proveedor): void
    {
        config(['pagos.paypal' => [
            'habilitado' => true, 'environment' => 'sandbox', 'client_id' => 'ClientFicticio',
            'client_secret' => 'SecretFicticio', 'app_id' => 'APP-Ficticia',
            'merchant_id' => 'MerchantFicticio', 'webhook_id' => 'WebhookFicticio',
            'origen_retorno' => 'https://clinica.example.test',
        ]]);
        $paypal = new PayPalHttpSimulado;
        $paypal->instalar();
        $revision = $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->json('data.revision_pago');
        $datos = ['proveedor' => $proveedor, 'revision_pago' => $revision];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $uri = '/api/pagos/obligaciones/'.$this->obligacion.'/intentos';
        $id = $this->navegador($this->cookies, 'POST', $uri, $datos, $headers)->assertCreated()->json('data.id');
        for ($i = 0; $i < 2; $i++) {
            $this->navegador($this->cookies, 'POST', $uri, $datos, $headers)
                ->assertOk()->assertJsonPath('meta.repetida', true)->assertJsonPath('data.id', $id);
        }
        $limitada = $this->navegador($this->cookies, 'POST', $uri, $datos, $headers)
            ->assertStatus(429)->assertJsonPath('error.code', 'AUTH_LIMITE_SOLICITUDES')->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $limitada->headers->get('Retry-After'));
        $this->assertSame(1, DB::table('intentos_pago')->count());
        $this->assertSame(1, DB::table('operaciones_pago')->where('tipo', 'Crear')->count());
        if ($proveedor === 'paypal') {
            $this->assertSame(1, $paypal->contar('POST', '/v2/checkout/orders'));
        } else {
            $this->assertCount(1, array_filter($this->stripe->llamadas, fn ($r) => $r[0] === 'crear'));
        }
    }

    public static function proveedoresFaseOcho(): array
    {
        return ['Stripe' => ['stripe'], 'PayPal' => ['paypal']];
    }

    public function test_exceptional_second_charge_is_retained_but_only_applied_charge_is_refunded(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->webhook($this->stripe->pagar(array_key_first($this->stripe->sesiones)))->assertNoContent();
        app(EventoStripeService::class)->lote();
        $original = app(StripePagoRepository::class)->intento($id);
        // Fixture excepcional: intento histórico distinto; no se abre por el endpoint de pagos normal.
        $otro = $original->replicate();
        $otro->forceFill(['id_intento' => (string) Str::uuid(), 'estado' => 'Cerrado', 'referencia_inicio' => null,
            'referencia_pago' => null])->saveOrFail();
        $cuerpo = DB::table('operaciones_pago')->where('tipo', 'Crear')->value('id_operacion');
        $cuerpo = app(StripePagoRepository::class)->operacion($cuerpo)->datos_solicitud_cifrados;
        $cuerpo['client_reference_id'] = (string) $otro->getKey();
        $cuerpo['metadata']['intento'] = (string) $otro->getKey();
        $cuerpo['payment_intent_data']['metadata']['intento'] = (string) $otro->getKey();
        unset($cuerpo['_handoff']);
        // Anomalía exclusivamente externa simulada: el worker normal NO puede abrir otro cobro tras el aplicado.
        $creada = $this->stripe->crear($cuerpo, (string) Str::uuid());
        $ref = $creada['id'];
        $this->webhook($this->stripe->pagar($ref), id: 'evt_Segundo')->assertNoContent();
        app(EventoStripeService::class)->lote();
        $this->assertSame(2, DB::table('cobros_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->where('aplicado', true)->count());
        $this->assertSame('COBRO_ADICIONAL_NO_APLICADO', DB::table('cobros_pago')->where('aplicado', false)->value('incidencia_codigo'));
        $this->cancelar();
        $this->procesar();
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame(DB::table('cobros_pago')->where('aplicado', true)->value('id_cobro'), DB::table('devoluciones_pago')->value('id_cobro'));
    }
}
