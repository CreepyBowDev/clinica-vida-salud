<?php

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\ConfiguracionStripe;
use Clinica\business\services\pagos\EventoStripeService;
use Clinica\business\services\pagos\StripeGateway;
use Clinica\data\models\ObligacionPago;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PayPalHttpSimulado;
use Tests\Fixtures\StripeSimulado;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    exit(2);
}
$app->make(Kernel::class)->bootstrap();
$c = config('database.connections.pgsql');
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || ! empty($c['url'])
    || $c['database'] !== 'clinica_vida_salud_test' || $c['username'] !== 'clinica_vida_salud_test'
    || ! in_array($c['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    exit(2);
}
$d = json_decode(trim(fgets(STDIN)), true, 64, JSON_THROW_ON_ERROR);
if (! preg_match('/\Aprueba_paypal_[a-z0-9]{16}\z/', $d['schema'] ?? '')) {
    exit(2);
}
DB::select('SELECT set_config(?, ?, false)', ['search_path', $d['schema'].', public, pg_temp']);
config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'cache.default' => 'array',
    'cors.allowed_origins' => ['http://localhost:5173', 'https://clinica.example.test'],
    'pagos.paypal' => ['habilitado' => true, 'environment' => 'sandbox', 'client_id' => 'ClientFicticio',
        'client_secret' => 'SecretFicticio', 'app_id' => 'APP-Ficticia', 'merchant_id' => 'MerchantFicticio',
        'webhook_id' => 'WebhookFicticio', 'origen_retorno' => 'https://clinica.example.test'],
    'pagos.stripe' => ['habilitado' => true, 'secret_key' => 'sk_test_FicticiaNoValida', 'cuenta' => 'acct_Ficticia',
        'webhook_secret' => 'whsec_Ficticio', 'api_version' => '2026-10-28.endive', 'origen_retorno' => 'https://clinica.example.test']]);
$app['env'] = 'local';
CarbonImmutable::setTestNow($d['ahora']);
$http = new PayPalHttpSimulado;
$http->ordenes = $d['ordenes'] ?? [];
$http->capturas = $d['capturas'] ?? [];
$http->instalar();
$stripe = new StripeSimulado(app(ConfiguracionStripe::class));
$stripe->sesiones = $d['sesiones'] ?? [];
$app->instance(StripeGateway::class, $stripe);
if ($d['pausa'] ?? false) {
    $app->instance(StripePagoRepository::class, new class extends StripePagoRepository
    {
        private bool $pausar = true;

        public function bloquear(string $id): ObligacionPago
        {
            $o = parent::bloquear($id);
            if ($this->pausar) {
                $this->pausar = false;
                echo json_encode(['lock' => true]), "\n";
                fflush(STDOUT);
                if (trim(fgets(STDIN)) !== 'CONTINUAR') {
                    exit(3);
                }
            }

            return $o;
        }
    });
}
echo json_encode(['ready' => DB::selectOne('SELECT pg_backend_pid() pid')->pid]), "\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'INICIAR') {
    exit(3);
}
if (($d['modo'] ?? '') === 'evento-stripe') {
    app(EventoStripeService::class)->lote(10);
    echo json_encode(['fin' => true, 'status' => 200, 'sesiones' => $stripe->sesiones, 'ordenes' => $http->ordenes,
        'capturas' => $http->capturas]), "\n";
    fflush(STDOUT);
    exit(0);
}
$request = Request::create($d['ruta'], 'POST', [], $d['cookies'], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_X_XSRF_TOKEN' => $d['cookies']['XSRF-TOKEN'],
    'HTTP_IDEMPOTENCY_KEY' => $d['clave'], 'REMOTE_ADDR' => '192.0.2.10',
], json_encode($d['cuerpo'] ?? (object) [], JSON_THROW_ON_ERROR));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$body = json_decode($response->getContent(), true);
echo json_encode(['fin' => true, 'status' => $response->getStatusCode(), 'codigo' => $body['error']['code'] ?? null,
    'id' => $body['data']['id'] ?? null, 'estado' => $body['data']['estado'] ?? null,
    'ordenes' => $http->ordenes, 'capturas' => $http->capturas, 'sesiones' => $stripe->sesiones]), "\n";
fflush(STDOUT);
