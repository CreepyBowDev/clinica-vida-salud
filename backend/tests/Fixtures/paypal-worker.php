<?php

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\RecuperacionPayPalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PayPalHttpSimulado;

// Solo carrera local sobre esquema propio y HTTP íntegramente simulado.
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
$datos = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
if (! preg_match('/\Aprueba_paypal_[a-z0-9]{16}\z/', $datos['schema'] ?? '')) {
    exit(2);
}
DB::select('SELECT set_config(?, ?, false)', ['search_path', $datos['schema'].', public, pg_temp']);
config(['pagos.paypal' => ['habilitado' => true, 'environment' => 'sandbox', 'client_id' => 'ClientFicticio',
    'client_secret' => 'SecretFicticio', 'app_id' => 'APP-Ficticia', 'merchant_id' => 'MerchantFicticio',
    'webhook_id' => 'WebhookFicticio', 'origen_retorno' => 'https://clinica.example.test'],
    'cors.allowed_origins' => ['https://clinica.example.test']]);
$app['env'] = 'local';
CarbonImmutable::setTestNow($datos['ahora']);
$http = new PayPalHttpSimulado;
$http->ordenes = $datos['ordenes'];
$http->instalar();
if ($datos['pausa']) {
    $http->duranteCaptura = function () use ($http): void {
        $http->duranteCaptura = null;
        echo json_encode(['red' => true, 'transaccion' => DB::transactionLevel(),
            'pid' => DB::selectOne('SELECT pg_backend_pid() pid')->pid]), "\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'CONTINUAR') {
            exit(3);
        }
    };
}
app(RecuperacionPayPalService::class)->ejecutar($datos['operacion']);
echo json_encode(['fin' => true, 'llamadas' => count($http->llamadas), 'ordenes' => $http->ordenes,
    'capturas' => $http->capturas]), "\n";
fflush(STDOUT);
