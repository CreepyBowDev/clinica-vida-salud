<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\ConciliacionPagosService;
use Clinica\business\services\pagos\ConfiguracionPayPal;
use Clinica\business\services\pagos\ConfiguracionStripe;
use Clinica\business\services\pagos\EventoPayPalService;
use Clinica\business\services\pagos\EvidenciaPayPalService;
use Clinica\business\services\pagos\EvidenciaStripeService;
use Clinica\business\services\pagos\PayPalPagoService;
use Clinica\business\services\pagos\ProcesamientoEventoPagoService;
use Clinica\business\services\pagos\RecuperacionPayPalService;
use Clinica\business\services\pagos\RecuperacionStripeService;
use Clinica\business\services\pagos\StripeGateway;
use Clinica\data\models\EventoPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\Fixtures\PayPalHttpSimulado;
use Tests\Fixtures\StripeSimulado;
use Tests\TestCase;

class PagosPayPalTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $path;

    private string $obligacion;

    private string $cita;

    private string $actor;

    private array $cookies;

    private PayPalHttpSimulado $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_paypal_'.strtolower(Str::random(16));
        $this->path = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        DB::table('configuracion_clinica')->insert(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'pagos.paypal' => [
            'habilitado' => true, 'environment' => 'sandbox', 'client_id' => 'ClientFicticio', 'client_secret' => 'SecretFicticio',
            'app_id' => 'APP-Ficticia', 'merchant_id' => 'MerchantFicticio', 'webhook_id' => 'WebhookFicticio',
            'origen_retorno' => 'https://clinica.example.test'], 'pagos.stripe.habilitado' => false]);
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://clinica.example.test']]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
        $this->http = new PayPalHttpSimulado;
        $this->http->instalar();
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
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        } finally {
            parent::tearDown();
        }
    }

    private function revision(): string
    {
        return $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)->assertOk()->json('data.revision_pago');
    }

    private function inicio(?string $clave = null, ?string $revision = null, array $extra = []): TestResponse
    {
        return $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'paypal', 'revision_pago' => $revision ?? $this->revision(), ...$extra],
            ['Idempotency-Key' => $clave ?? (string) Str::uuid()]);
    }

    private function operar(string $id, string $tipo = 'resultado', ?string $clave = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetScopedInstances();

        return $this->call('POST', '/api/pagos/intentos/'.$id.'/'.$tipo, [], $this->cookies, [],
            $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json',
                'Origin' => 'http://localhost:5173', 'X-XSRF-TOKEN' => $this->cookies['XSRF-TOKEN'],
                'Idempotency-Key' => $clave ?? (string) Str::uuid()]), '{}');
    }

    private function capturar(string $id, ?string $clave = null): TestResponse
    {
        return $this->operar($id, 'paypal/captura', $clave);
    }

    private function procesar(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(3));
        app(EventoPayPalService::class)->lote(50);
        app(RecuperacionPayPalService::class)->lote(50);
    }

    private function cancelar(): void
    {
        $u = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $u)->assertOk();
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$this->cita.'/gestion')->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$this->cita.'/cancelacion',
            ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en'],
                'motivo' => 'Cancelación ficticia'])->assertOk();
    }

    private function webhook(array $resource, string $tipo = 'CHECKOUT.ORDER.APPROVED', string $id = 'EV-Ficticio', array $headers = []): TestResponse
    {
        $bytes = json_encode(['id' => $id, 'event_type' => $tipo, 'resource' => $resource], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/webhooks/pagos/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-Ficticio',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'Transmission-Ficticia', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'FirmaFicticia',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-11-01T11:00:00Z', 'HTTP_ORIGIN' => 'https://hostil.example.test', ...$headers], $bytes);
    }

    public function test_creation_is_durable_idempotent_and_uses_exact_history_without_locks_during_network(): void
    {
        $revision = $this->revision();
        $clave = (string) Str::uuid();
        $r = $this->inicio($clave, $revision)->assertCreated()->assertJsonPath('data.estado', 'RequiereAccion');
        $this->inicio($clave, $revision)->assertOk()->assertJsonPath('data.id', $r->json('data.id'))->assertJsonPath('meta.repetida', true);
        $this->assertSame(1, count($this->http->ordenes));
        $s = $this->http->ordenes['ORD-1'];
        $this->assertSame(['currency_code' => 'USD', 'value' => '1.01'], $s['purchase_units'][0]['amount']);
        $this->assertSame([0], array_values(array_unique(array_column($this->http->llamadas, 'transaccion'))));
        $this->assertSame(704, DB::table('obligaciones_pago')->value('centavos_bob'));
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertStringNotContainsString('paypal.com', json_encode(DB::table('intentos_pago')->first()));
    }

    public function test_lost_create_response_recovers_with_same_durable_key(): void
    {
        $revision = $this->revision();
        $clave = (string) Str::uuid();
        $this->http->perder = 'Crear';
        $id = $this->inicio($clave, $revision)->assertAccepted()->json('data.id');
        $this->inicio($clave, $revision)->assertAccepted()->assertJsonPath('data.id', $id);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
        $this->inicio($clave, $revision)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertCount(1, $this->http->ordenes);
        $this->assertSame(1, DB::table('operaciones_pago')->where('tipo', 'Crear')->count());
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public function test_approval_event_and_return_never_capture_automatically(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->capturar($id)->assertConflict()->assertJsonPath('error.code', 'PAGO_APROBACION_REQUERIDA');
        $this->http->aprobar();
        $this->webhook($this->http->ordenes['ORD-1'])->assertOk();
        $this->webhook($this->http->ordenes['ORD-1'])->assertOk();
        $this->procesar();
        $this->operar($id)->assertOk()->assertJsonPath('data.aplicado', false);
        $this->assertSame(1, DB::table('eventos_pago')->count());
        $this->assertSame(0, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
        $clave = (string) Str::uuid();
        $this->capturar($id, $clave)->assertOk()->assertJsonPath('data.aplicado', true);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
        $this->capturar($id, $clave)->assertOk()->assertJsonPath('data.aplicado', true);
        $this->assertSame(1, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_capture_timeout_retains_exclusion_and_recovers_by_order_without_repeating_charge(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->perder = 'Capturar';
        $this->capturar($id)->assertAccepted();
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Capturar')->value('estado'));
        $this->inicio(revision: 'invalida')->assertConflict();
        $this->procesar();
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(1, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
        $this->assertSame('Exitoso', DB::table('intentos_pago')->value('estado'));
    }

    public function test_cancel_uncaptured_order_revokes_permission_and_closes_without_capture(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->cancelar();
        $this->assertFalse(DB::table('intentos_pago')->value('captura_permitida'));
        $this->capturar($id)->assertConflict()->assertJsonPath('error.code', 'PAGO_CAPTURA_NO_PERMITIDA');
        $this->procesar();
        $this->assertSame('Cerrado', DB::table('intentos_pago')->value('estado'));
        $this->assertSame(0, count($this->http->capturas));
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
    }

    public function test_cancellation_during_capture_preserves_late_charge_and_refunds_only_once(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->duranteCaptura = function (): void {
            $this->http->duranteCaptura = null;
            $this->cancelar();
            $this->assertSame(0, DB::transactionLevel());
        };
        $this->capturar($id)->assertOk()->assertJsonPath('data.aplicado', true);
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->procesar();
        $this->procesar();
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame(1, count($this->http->devoluciones));
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
        $this->assertFalse(DB::table('obligaciones_pago')->value('exigible'));
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_paid_cancellation_and_refund_timeout_recovers_from_verified_webhook_without_new_post(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($id)->assertOk();
        $this->cancelar();
        $this->http->perder = 'Devolver';
        $this->procesar();
        $this->assertSame('Incierta', DB::table('devoluciones_pago')->value('estado'));
        $this->procesar();
        $this->assertSame(1, $this->http->contar('POST', '/v2/payments/captures/CAP-1/refund'));
        $this->webhook($this->http->devoluciones['REF-1'], 'PAYMENT.CAPTURE.REFUNDED')->assertOk();
        $this->procesar();
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame(1, $this->http->contar('POST', '/v2/payments/captures/CAP-1/refund'));
    }

    public function test_event_before_local_create_response_links_existing_attempt_and_never_creates_obligation(): void
    {
        $this->http->perder = 'Crear';
        $id = $this->inicio()->assertAccepted()->json('data.id');
        $this->http->aprobar();
        $this->webhook($this->http->ordenes['ORD-1'])->assertOk();
        $this->procesar();
        $this->assertSame('ORD-1', DB::table('intentos_pago')->value('referencia_inicio'));
        $this->assertSame('Aplicado', DB::table('eventos_pago')->value('estado'));
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
        $this->assertSame($id, DB::table('eventos_pago')->value('id_intento'));
        $this->assertSame(0, count($this->http->capturas));
    }

    #[DataProvider('incompatibilidades')]
    public function test_incompatible_evidence_never_liquidates(string $campo): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->cobrar('ORD-1');
        match ($campo) {
            'importe' => $this->http->capturas['CAP-1']['amount']['value'] = '1.02',
            'moneda' => $this->http->capturas['CAP-1']['amount']['currency_code'] = 'BOB',
            'bruto' => $this->http->capturas['CAP-1']['seller_receivable_breakdown']['gross_amount']['value'] = '1.00',
            'payee' => $this->http->capturas['CAP-1']['payee']['merchant_id'] = 'OtroMerchant',
            'orden' => $this->http->capturas['CAP-1']['supplementary_data']['related_ids']['order_id'] = 'ORD-Otra',
            'captura' => $this->http->capturas['CAP-1']['id'] = 'CAP-Otra',
            'final' => $this->http->capturas['CAP-1']['final_capture'] = false,
            'unit' => $this->http->ordenes['ORD-1']['purchase_units'][0]['custom_id'] = (string) Str::uuid(),
            'intent' => $this->http->ordenes['ORD-1']['intent'] = 'AUTHORIZE',
            'total' => $this->http->ordenes['ORD-1']['purchase_units'][0]['amount']['value'] = 1.01,
        };
        $this->operar($id)->assertAccepted()->assertJsonPath('data.aplicado', false);
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertSame('Incierto', DB::table('intentos_pago')->value('estado'));
    }

    public static function incompatibilidades(): array
    {
        return array_map(fn ($v) => [$v], ['importe', 'moneda', 'bruto', 'payee', 'orden', 'captura', 'final', 'unit', 'intent', 'total']);
    }

    #[DataProvider('roles')]
    public function test_non_patient_roles_cannot_pay(string $rol): void
    {
        $u = $this->usuarioAuth($rol);
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $u)->assertOk();
        $this->inicio(revision: 'no-valida')->assertForbidden();
        $this->assertSame(0, count($this->http->ordenes));
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    public function test_foreign_patient_cannot_start_query_or_capture(): void
    {
        $revision = $this->revision();
        $id = $this->inicio()->assertCreated()->json('data.id');
        $u = $this->usuarioAuth('Paciente');
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $u)->assertOk();
        $this->inicio(revision: $revision)->assertNotFound();
        $this->operar($id)->assertNotFound();
        $this->capturar($id)->assertNotFound();
        $this->assertSame(0, count($this->http->capturas));
    }

    public function test_unknown_input_price_currency_and_returns_are_rejected(): void
    {
        $revision = $this->revision();
        foreach (['amount' => '1.00', 'currency' => 'USD', 'return_url' => 'https://hostil.example.test'] as $k => $v) {
            $this->inicio(revision: $revision, extra: [$k => $v])->assertUnprocessable();
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
        $this->inicio(revision: 'manipulada')->assertConflict();
        $this->assertSame(0, DB::table('intentos_pago')->count());
    }

    public function test_webhook_requires_real_postback_success_and_preserves_raw_event(): void
    {
        $this->http->firma = 'FAILURE';
        $this->webhook(['id' => 'ORD-1'])->assertBadRequest();
        $this->assertSame(0, DB::table('eventos_pago')->count());
        $this->http->firma = 'SUCCESS';
        $this->http->verificacionCaida = true;
        $this->webhook(['id' => 'ORD-1'])->assertStatus(503);
        $this->assertSame(0, DB::table('eventos_pago')->count());
        $this->http->verificacionCaida = false;
        $this->webhook(['id' => 'ORD-1'])->assertOk();
        $this->assertStringContainsString('"webhook_event":{"id":"EV-Ficticio"', $this->http->verificacionBody);
        $this->webhook(['id' => 'ORD-2'])->assertConflict();
        $this->assertSame(1, DB::table('eventos_pago')->count());
    }

    public function test_cert_url_is_not_downloaded_and_bad_hosts_are_rejected(): void
    {
        foreach (['https://api.paypal.com/v1/notifications/certs/CERT-X', 'http://127.0.0.1/secreto',
            'https://api.sandbox.paypal.com.ejemplo.test/v1/notifications/certs/CERT-X'] as $url) {
            $this->webhook(['id' => 'ORD-1'], headers: ['HTTP_PAYPAL_CERT_URL' => $url])->assertBadRequest();
        }
        $this->assertSame([], $this->http->llamadas);
        $this->assertSame(0, DB::table('eventos_pago')->count());
    }

    public function test_oauth_app_mismatch_is_uncertain_and_does_not_create_order(): void
    {
        $this->http->oauth['app_id'] = 'APP-Ajena';
        $this->inicio()->assertAccepted();
        $this->assertCount(0, $this->http->ordenes);
        $this->assertSame('Incierta', DB::table('operaciones_pago')->value('estado'));
    }

    public function test_incompatible_merchant_configuration_invalidates_review(): void
    {
        $revision = $this->revision();
        config(['pagos.paypal.merchant_id' => 'OtroMerchant']);
        $this->inicio(revision: $revision)->assertConflict()->assertJsonPath('error.code', 'PAGO_REVISION_OBSOLETA');
        $this->assertSame(0, count($this->http->ordenes));
    }

    public function test_capture_prepared_but_cancelled_before_dispatch_never_calls_capture(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $this->cancelar();
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->procesar();
        $this->assertCount(0, $this->http->capturas);
        $this->assertSame('Cerrado', DB::table('intentos_pago')->value('estado'));
    }

    public function test_cancellation_wins_during_pre_capture_get_and_prevents_post(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $this->http->antesOrden = function (): void {
            $this->http->antesOrden = null;
            $this->cancelar();
        };
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->assertCount(0, $this->http->capturas);
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
    }

    public function test_financial_and_provider_flags_remain_off_by_default_and_production_rejected(): void
    {
        $defaults = require base_path('config/pagos.php');
        $this->assertFalse($defaults['incorporacion_habilitada']);
        $this->assertFalse($defaults['stripe']['habilitado']);
        $this->assertFalse($defaults['paypal']['habilitado']);
        config(['pagos.paypal.habilitado' => false]);
        $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)->assertOk()
            ->assertJsonPath('data.puede_iniciar_pago', false)->assertJsonPath('data.revision_pago', null);
        $this->inicio(revision: 'no-valida')->assertStatus(503);
        config(['pagos.paypal.habilitado' => true]);
        $this->app['env'] = 'production';
        $this->expectException(AppError::class);
        app(ConfiguracionPayPal::class)->exigir();
    }

    public function test_rollback_of_cobro_audit_preserves_no_partial_application(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->cobrar('ORD-1');
        $mock = \Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('pago')->with('PAGO_COBRO_APLICADO', \Mockery::any(), \Mockery::any())->andThrow(new \RuntimeException('Auditoría simulada'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->operar($id)->assertAccepted();
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->app->forgetInstance(BitacoraRepository::class);
        $this->procesar();
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_inbox_persistence_failure_does_not_acknowledge_success(): void
    {
        $repo = \Mockery::mock(StripePagoRepository::class)->makePartial();
        $repo->shouldReceive('guardar')->andThrow(new \RuntimeException('Persistencia simulada'));
        $this->app->instance(StripePagoRepository::class, $repo);
        $this->webhook(['id' => 'ORD-1'])->assertStatus(503);
        $this->assertSame(0, DB::table('eventos_pago')->count());
    }

    private function stripe(): void
    {
        config(['pagos.stripe' => ['habilitado' => true, 'secret_key' => 'sk_test_FicticiaNoValida', 'cuenta' => 'acct_Ficticia',
            'webhook_secret' => 'whsec_Ficticio', 'api_version' => '2026-10-28.endive', 'origen_retorno' => 'https://clinica.example.test']]);
        $this->app->instance(StripeGateway::class, new StripeSimulado(app(ConfiguracionStripe::class)));
    }

    public function test_paypal_excludes_stripe_start_using_existing_common_locks(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $this->inicio(revision: $revision)->assertCreated();
        $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => $revision], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('error.code', 'PAGO_OPERACION_ACTIVA');
        $this->assertSame(1, DB::table('intentos_pago')->count());
    }

    public function test_stripe_excludes_paypal_start_without_operational_provider_switch(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => $revision], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->inicio(revision: $revision)->assertConflict()->assertJsonPath('error.code', 'PAGO_OPERACION_ACTIVA');
        $this->assertCount(0, $this->http->ordenes);
    }

    public function test_two_process_capture_lease_and_cancellation_without_network_locks(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $datos = ['schema' => $this->schema, 'operacion' => $r['operacion'],
            'ahora' => CarbonImmutable::now('UTC')->toIso8601String(), 'ordenes' => $this->http->ordenes];
        $procesos = [];
        try {
            foreach ([true, false] as $pausa) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/paypal-worker.php')],
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
                    $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() pid')->pid, $linea['pid']);
                    $this->cancelar();
                    $this->assertFalse(DB::table('intentos_pago')->value('captura_permitida'));
                } else {
                    $this->assertTrue($linea['fin']);
                    $this->assertSame(0, $linea['llamadas']);
                }
            }
            fwrite($procesos[0][1][0], "CONTINUAR\n");
            fflush($procesos[0][1][0]);
            $linea = $this->lineaWorker($procesos[0][1][1]);
            $this->assertTrue($linea['fin']);
            $this->http->ordenes = $linea['ordenes'];
            $this->http->capturas = $linea['capturas'];
            $this->assertSame(1, DB::table('cobros_pago')->count());
            $this->assertSame(1, DB::table('devoluciones_pago')->count());
            $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
            $this->procesar();
            $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
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
        throw new \RuntimeException('Venció la barrera del worker PayPal simulado.');
    }

    public function test_second_exceptional_charge_is_preserved_unapplied_without_extra_refund(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($id)->assertOk();
        $this->http->cobrar('ORD-1');
        $this->operar($id)->assertOk();
        $this->assertSame(2, DB::table('cobros_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->where('aplicado', true)->count());
        $this->assertSame('COBRO_ADICIONAL_NO_APLICADO', DB::table('cobros_pago')->where('aplicado', false)->value('incidencia_codigo'));
        $this->cancelar();
        $this->procesar();
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame(1, $this->http->contar('POST', '/v2/payments/captures/CAP-1/refund'));
        // Nunca se devuelve el segundo cobro excepcional no aplicado.
        $this->assertSame(0, $this->http->contar('POST', '/v2/payments/captures/CAP-2/refund'));
    }

    public function test_external_partial_refund_blocks_another_total_refund(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($id)->assertOk();
        $this->http->capturas['CAP-1']['status'] = 'PARTIALLY_REFUNDED';
        $this->cancelar();
        $this->procesar();
        $this->assertSame('Incierta', DB::table('devoluciones_pago')->value('estado'));
        $this->assertCount(0, $this->http->devoluciones);
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
    }

    public function test_create_window_exhaustion_retains_uncertainty_without_blind_repost(): void
    {
        $this->http->perder = 'Crear';
        $this->inicio()->assertAccepted();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(7));
        app(RecuperacionPayPalService::class)->lote(10);
        $this->assertSame(1, $this->http->contar('POST', '/v2/checkout/orders'));
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Crear')->value('estado'));
        $this->assertNotNull(app(StripePagoRepository::class)->activo($this->obligacion));
    }

    public function test_payload_limit_missing_signature_and_reversed_event_are_safe(): void
    {
        $this->webhook(['id' => 'ORD-1'], headers: ['HTTP_PAYPAL_TRANSMISSION_SIG' => ''])->assertBadRequest();
        $this->webhook(['id' => 'ORD-1', 'texto' => str_repeat('x', 1048576)])->assertStatus(413);
        $this->assertSame(0, DB::table('eventos_pago')->count());
        $this->webhook(['id' => 'CAP-1'], 'PAYMENT.CAPTURE.REVERSED')->assertOk();
        $this->assertSame('Cuarentena', DB::table('eventos_pago')->value('estado'));
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public function test_late_webhook_after_capture_timeout_and_cancellation_prepares_one_refund(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->perder = 'Capturar';
        $this->capturar($id)->assertAccepted();
        $this->cancelar();
        $this->webhook($this->http->capturas['CAP-1'], 'PAYMENT.CAPTURE.COMPLETED')->assertOk();
        $this->webhook($this->http->capturas['CAP-1'], 'PAYMENT.CAPTURE.COMPLETED')->assertOk();
        $this->procesar();
        $this->procesar();
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
    }

    public function test_capture_uses_order_payee_without_inventing_optional_capture_fields(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->cobrar('ORD-1');
        unset($this->http->capturas['CAP-1']['payee'], $this->http->capturas['CAP-1']['supplementary_data']);
        $this->operar($id)->assertOk()->assertJsonPath('data.aplicado', true);
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_verified_closed_order_enables_safe_operational_provider_switch(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $id = $this->inicio(revision: $revision)->assertCreated()->json('data.id');
        $this->operar($id, 'cierre')->assertOk()->assertJsonPath('data.estado', 'Cerrado');
        $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => $revision], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.proveedor', 'stripe');
        $this->assertSame(2, DB::table('intentos_pago')->count());
        $this->assertFalse(DB::table('intentos_pago')->where('id_intento', $id)->value('captura_permitida'));
    }

    public function test_account_disabled_after_preparation_cannot_start_new_capture(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        DB::table('usuarios')->where('id_usuario', $this->actor)->update(['estado_cuenta' => 'Desactivada']);
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->assertCount(0, $this->http->capturas);
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    private function inicioStripe(string $revision): TestResponse
    {
        return $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
            ['proveedor' => 'stripe', 'revision_pago' => $revision], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_f6_verified_stripe_close_allows_paypal_and_switching_back_preserves_price(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $s = $this->inicioStripe($revision)->assertCreated()->json('data.id');
        $this->operar($s, 'cierre')->assertOk()->assertJsonPath('data.estado', 'Cerrado');
        $p = $this->inicio(revision: $this->revision())->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->operar($p, 'cierre')->assertOk()->assertJsonPath('data.estado', 'Cerrado');
        $this->inicioStripe($this->revision())->assertCreated();
        $this->assertSame(3, DB::table('intentos_pago')->count());
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
        $this->assertSame(704, DB::table('obligaciones_pago')->value('centavos_bob'));
        $this->assertSame(101, DB::table('obligaciones_pago')->value('centavos_usd'));
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public function test_f6_terminal_label_without_verified_closure_does_not_allow_switch(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $id = $this->inicio()->assertCreated()->json('data.id');
        // Corrupción simulada de una etiqueta: no constituye prueba de cierre del proveedor.
        DB::table('intentos_pago')->where('id_intento', $id)->update(['estado' => 'Cerrado', 'captura_permitida' => false]);
        $this->inicioStripe($revision)->assertConflict()->assertJsonPath('error.code', 'PAGO_OPERACION_ACTIVA');
        $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->assertJsonPath('data.puede_iniciar_pago', false);
        $this->assertSame(1, DB::table('intentos_pago')->count());
    }

    public function test_f6_closing_handed_off_capture_does_not_repost_or_release_uncertainty(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->http->duranteCaptura = function (): void {
            $this->http->duranteCaptura = null;
            throw new ConnectionException('POST puede no haber llegado: simulación.');
        };
        $this->capturar($id)->assertAccepted();
        $this->operar($id, 'cierre')->assertAccepted();
        $this->procesar();
        $this->procesar();
        $this->assertSame(1, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
        $this->assertSame('Incierta', DB::table('operaciones_pago')->where('tipo', 'Capturar')->value('estado'));
        $this->inicioStripe($revision)->assertConflict();
        $this->assertCount(0, $this->http->capturas);
    }

    public function test_f6_close_before_capture_handoff_revokes_pending_capture_then_allows_switch(): void
    {
        $this->stripe();
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $this->operar($id, 'cierre')->assertAccepted();
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->procesar();
        $this->assertSame('Cerrado', DB::table('intentos_pago')->where('id_intento', $id)->value('estado'));
        $this->inicioStripe($this->revision())->assertCreated();
        $this->assertSame(0, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
    }

    public function test_f6_late_stripe_payment_after_switch_preserves_two_facts_and_refunds_only_applied_paypal(): void
    {
        $this->stripe();
        $id = $this->inicioStripe($this->revision())->assertCreated()->json('data.id');
        $this->operar($id, 'cierre')->assertOk();
        $p = $this->inicio(revision: $this->revision())->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($p)->assertOk();
        $stripe = app(StripeGateway::class);
        $stripe->pagar(array_key_first($stripe->sesiones));
        $this->operar($id)->assertOk();
        $this->assertSame(2, DB::table('cobros_pago')->count());
        $this->assertSame('paypal', DB::table('cobros_pago')->where('aplicado', true)->value('proveedor'));
        $this->assertSame('COBRO_ADICIONAL_NO_APLICADO', DB::table('cobros_pago')->where('aplicado', false)->value('incidencia_codigo'));
        $this->cancelar();
        app(ConciliacionPagosService::class)->lote(50);
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertCount(0, $stripe->reembolsos);
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
    }

    public function test_f6_late_applied_stripe_payment_revokes_prepared_paypal_capture(): void
    {
        $this->stripe();
        $id = $this->inicioStripe($this->revision())->assertCreated()->json('data.id');
        $this->operar($id, 'cierre')->assertOk();
        $p = $this->inicio(revision: $this->revision())->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($p, 'Capturar', (string) Str::uuid());
        $stripe = app(StripeGateway::class);
        $stripe->pagar(array_key_first($stripe->sesiones));
        $this->operar($id)->assertOk();
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->procesar();
        $this->assertSame(0, $this->http->contar('POST', '/v2/checkout/orders/ORD-1/capture'));
        $this->assertFalse(DB::table('intentos_pago')->where('id_intento', $p)->value('captura_permitida'));
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_f6_late_applied_paypal_payment_schedules_stripe_close_without_compensation(): void
    {
        $this->stripe();
        $p = $this->inicio()->assertCreated()->json('data.id');
        $this->operar($p, 'cierre')->assertOk();
        $s = $this->inicioStripe($this->revision())->assertCreated()->json('data.id');
        $this->http->cobrar('ORD-1');
        $this->operar($p)->assertOk();
        // Un resultado/replay no debe volver a ofrecer Checkout mientras el cierre se recupera.
        $this->operar($s)->assertAccepted()->assertJsonPath('data.accion', null);
        app(RecuperacionStripeService::class)->lote(20);
        $this->assertSame('Cerrado', DB::table('intentos_pago')->where('id_intento', $s)->value('estado'));
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
    }

    public function test_f6_old_open_stripe_snapshot_cannot_reopen_a_verified_closed_attempt(): void
    {
        $this->stripe();
        $s = $this->inicioStripe($this->revision())->assertCreated()->json('data.id');
        $stripe = app(StripeGateway::class);
        $vieja = $stripe->sesiones[array_key_first($stripe->sesiones)];
        $this->operar($s, 'cierre')->assertOk();
        $p = $this->inicio(revision: $this->revision())->assertCreated()->json('data.id');
        DB::transaction(function () use ($s, $vieja): void {
            $repo = app(StripePagoRepository::class);
            app(EvidenciaStripeService::class)->sesion($repo->bloquear($this->obligacion), $repo->intento($s), $vieja);
        });
        $this->assertSame('Cerrado', DB::table('intentos_pago')->where('id_intento', $s)->value('estado'));
        $this->assertSame($p, (string) app(StripePagoRepository::class)->activo($this->obligacion)->getKey());
    }

    public function test_f6_expired_capture_lease_can_be_reclaimed_and_stale_owner_cannot_finalize_it(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $this->http->duranteCaptura = function () use ($r): void {
            $this->http->duranteCaptura = null;
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(6));
            app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        };
        app(RecuperacionPayPalService::class)->ejecutar($r['operacion']);
        $this->assertSame(2, DB::table('operaciones_pago')->where('id_operacion', $r['operacion'])->value('ejecuciones'));
        $this->assertSame('Exitosa', DB::table('operaciones_pago')->where('id_operacion', $r['operacion'])->value('estado'));
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertCount(1, $this->http->capturas);
    }

    public function test_f6_inbox_claim_is_exclusive_recovers_expiry_and_rejects_stale_finalization(): void
    {
        $this->webhook(['id' => 'ORD-SinVinculo'])->assertOk();
        $repo = app(StripePagoRepository::class);
        $service = app(ProcesamientoEventoPagoService::class);
        $ev = EventoPago::query()->firstOrFail();
        $primero = $service->reclamar($ev);
        $this->assertNotNull($primero);
        $this->assertNull($service->reclamar($ev));
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(6));
        $segundo = $service->reclamar($ev);
        $this->assertNotNull($segundo);
        $service->finalizar($primero, null, null);
        $this->assertSame($segundo->codigo_reclamo, DB::table('eventos_pago')->value('codigo_reclamo'));
        $service->finalizar($segundo, null, null);
        $this->assertSame('PendienteVinculo', DB::table('eventos_pago')->value('estado'));
        $this->assertSame(2, DB::table('eventos_pago')->value('ejecuciones'));
    }

    public function test_f6_real_process_expired_lease_takeover_ignores_the_old_network_response(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $r = app(PayPalPagoService::class)->solicitar($id, 'Capturar', (string) Str::uuid());
        $procesos = [];
        try {
            foreach ([true, false] as $pausa) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/paypal-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($p);
                $procesos[] = [$p, $pipes];
                fwrite($pipes[0], json_encode(['schema' => $this->schema, 'operacion' => $r['operacion'], 'pausa' => $pausa,
                    'ahora' => CarbonImmutable::now('UTC')->addMinutes($pausa ? 0 : 6)->toIso8601String(),
                    'ordenes' => $this->http->ordenes], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $linea = $this->lineaWorker($pipes[1]);
                if ($pausa) {
                    $this->assertTrue($linea['red']);
                    $this->assertSame(0, $linea['transaccion']);
                    $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() pid')->pid, $linea['pid']);
                } else {
                    $this->assertTrue($linea['fin']);
                    $this->assertGreaterThan(0, $linea['llamadas']);
                    $this->http->ordenes = $linea['ordenes'];
                    $this->http->capturas = $linea['capturas'];
                }
            }
            $this->assertSame('Exitosa', DB::table('operaciones_pago')->where('id_operacion', $r['operacion'])->value('estado'));
            fwrite($procesos[0][1][0], "CONTINUAR\n");
            fflush($procesos[0][1][0]);
            $this->assertTrue($this->lineaWorker($procesos[0][1][1])['fin']);
            $this->assertSame(2, DB::table('operaciones_pago')->where('id_operacion', $r['operacion'])->value('ejecuciones'));
            $this->assertSame('Exitosa', DB::table('operaciones_pago')->where('id_operacion', $r['operacion'])->value('estado'));
            $this->assertSame(1, DB::table('cobros_pago')->count());
            $this->assertSame(1, DB::table('cobros_pago')->where('aplicado', true)->count());
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

    public function test_f6_uncertain_refund_is_included_in_detail_and_filter_with_terminal_attempt(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($id)->assertOk();
        $this->cancelar();
        $this->http->perder = 'Devolver';
        $this->procesar();
        $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->obligacion)
            ->assertOk()->assertJsonPath('data.operacion_en_verificacion', true);
        $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones?operacion_en_verificacion=1')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones?operacion_en_verificacion=0')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_f6_recovery_dispatches_durable_refund_even_after_attempt_became_terminal(): void
    {
        $id = $this->inicio()->assertCreated()->json('data.id');
        $this->http->aprobar();
        $this->capturar($id)->assertOk();
        // Un slot ajeno temporalmente ocupado fuerza a conservar solo la devolución lógica.
        $repo = app(StripePagoRepository::class);
        $r = DB::transaction(fn () => app(PayPalPagoService::class)->preparar($repo->intento($id), 'Capturar'));
        $this->cancelar();
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->assertSame(0, DB::table('operaciones_pago')->where('tipo', 'Devolver')->count());
        DB::transaction(fn () => app(EvidenciaPayPalService::class)->terminar($r, 'Fallida'));
        app(RecuperacionPayPalService::class)->lote(20);
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame(1, $this->http->contar('POST', '/v2/payments/captures/CAP-1/refund'));
    }

    public function test_f6_disabled_conciliation_does_not_query_financial_tables_or_make_network_calls(): void
    {
        config(['pagos.paypal.habilitado' => false, 'pagos.stripe.habilitado' => false]);
        $consultas = [];
        DB::listen(function ($q) use (&$consultas): void {
            $consultas[] = $q->sql;
        });
        $this->artisan('pagos:conciliar')->assertExitCode(0);
        $this->assertSame([], $consultas);
        $this->assertSame([], $this->http->llamadas);
        $this->artisan('pagos:conciliar --lote=1001')->assertExitCode(1);
    }

    public static function proveedoresF6(): array
    {
        return [['stripe', 'paypal'], ['paypal', 'stripe']];
    }

    #[DataProvider('proveedoresF6')]
    public function test_f6_real_process_cross_provider_starts_are_serialized(string $primero, string $segundo): void
    {
        $this->stripe();
        $revision = $this->revision();
        $ruta = '/api/pagos/obligaciones/'.$this->obligacion.'/intentos';
        [$a, $b] = $this->carreraF6(['ruta' => $ruta, 'cuerpo' => ['proveedor' => $primero, 'revision_pago' => $revision]],
            ['ruta' => $ruta, 'cuerpo' => ['proveedor' => $segundo, 'revision_pago' => $revision]]);
        $this->assertSame(201, $a['status']);
        $this->assertSame(409, $b['status']);
        $this->assertSame('PAGO_OPERACION_ACTIVA', $b['codigo']);
        $this->assertSame(1, DB::table('intentos_pago')->count());
    }

    #[DataProvider('proveedoresF6')]
    public function test_f6_real_process_close_and_other_provider_start_never_skip_verification(string $primero, string $segundo): void
    {
        $this->stripe();
        $revision = $this->revision();
        $id = ($primero === 'stripe' ? $this->inicioStripe($revision) : $this->inicio(revision: $revision))->assertCreated()->json('data.id');
        [$a, $b] = $this->carreraF6(['ruta' => '/api/pagos/intentos/'.$id.'/cierre'],
            ['ruta' => '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
                'cuerpo' => ['proveedor' => $segundo, 'revision_pago' => $revision]]);
        $this->assertSame(200, $a['status']);
        $this->assertContains($b['status'], [201, 409]);
        $this->assertSame('Cerrado', DB::table('intentos_pago')->where('id_intento', $id)->value('estado'));
        if ($b['status'] === 409) {
            ($segundo === 'stripe' ? $this->inicioStripe($this->revision()) : $this->inicio(revision: $this->revision()))->assertCreated();
        }
        $this->assertSame(2, DB::table('intentos_pago')->count());
    }

    public function test_f6_real_process_verified_webhook_and_start_converge_without_a_second_capture(): void
    {
        $this->stripe();
        $revision = $this->revision();
        $id = $this->inicioStripe($revision)->assertCreated()->json('data.id');
        $this->operar($id, 'cierre')->assertOk();
        $stripe = app(StripeGateway::class);
        $pagada = $stripe->pagar(array_key_first($stripe->sesiones));
        $bytes = json_encode(['id' => 'evt_CarreraF6', 'object' => 'event', 'livemode' => false,
            'api_version' => '2026-10-28.endive', 'type' => 'checkout.session.completed', 'data' => ['object' => $pagada]], JSON_THROW_ON_ERROR);
        $t = time();
        $this->call('POST', '/api/webhooks/pagos/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$bytes, 'whsec_Ficticio')], $bytes)->assertNoContent();
        [$a, $b] = $this->carreraF6(['modo' => 'evento-stripe'],
            ['ruta' => '/api/pagos/obligaciones/'.$this->obligacion.'/intentos',
                'cuerpo' => ['proveedor' => 'paypal', 'revision_pago' => $revision]]);
        $this->assertSame(200, $a['status']);
        $this->assertContains($b['status'], [200, 201, 202, 409]);
        app(ConciliacionPagosService::class)->lote(20);
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->where('aplicado', true)->count());
        $this->assertSame(0, DB::table('intentos_pago')->where('proveedor', 'paypal')->where('captura_permitida', true)->count());
        $this->assertCount(0, $this->http->capturas);
        $this->assertSame('Aplicado', DB::table('eventos_pago')->value('estado'));
    }

    private function carreraF6(array $primero, array $segundo): array
    {
        $procesos = [];
        try {
            foreach ([$primero, $segundo] as $n => $entrada) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/pagos-coordinacion-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($p);
                $procesos[] = [$p, $pipes];
                fwrite($pipes[0], json_encode([...$entrada, 'schema' => $this->schema, 'pausa' => $n === 0,
                    'ahora' => CarbonImmutable::now('UTC')->toIso8601String(), 'cookies' => $this->cookies,
                    'clave' => (string) Str::uuid(), 'ordenes' => $this->http->ordenes, 'capturas' => $this->http->capturas,
                    'sesiones' => app(StripeGateway::class)->sesiones], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $ready = $this->lineaWorker($pipes[1]);
                $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() pid')->pid, $ready['ready']);
                fwrite($pipes[0], "INICIAR\n");
                fflush($pipes[0]);
                if ($n === 0) {
                    $this->assertTrue($this->lineaWorker($pipes[1])['lock']);
                } else {
                    $fin = microtime(true) + 15;
                    do {
                        $bloqueado = DB::selectOne('SELECT cardinality(pg_blocking_pids(?)) > 0 bloqueado', [$ready['ready']])->bloqueado;
                        if ($bloqueado) {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $fin);
                    $this->assertTrue($bloqueado, 'El segundo proceso debe esperar un lock real PostgreSQL.');
                }
            }
            fwrite($procesos[0][1][0], "CONTINUAR\n");
            fflush($procesos[0][1][0]);
            $a = $this->lineaWorker($procesos[0][1][1]);
            $b = $this->lineaWorker($procesos[1][1][1]);
            foreach ([$a, $b] as $resultado) {
                $this->http->ordenes = array_replace($this->http->ordenes, $resultado['ordenes']);
                $this->http->capturas = array_replace($this->http->capturas, $resultado['capturas']);
                app(StripeGateway::class)->sesiones = array_replace(app(StripeGateway::class)->sesiones, $resultado['sesiones']);
            }

            return [$a, $b];
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
}
