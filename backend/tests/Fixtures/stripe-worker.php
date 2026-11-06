<?php

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\ConfiguracionStripe;
use Clinica\business\services\pagos\RecuperacionStripeService;
use Clinica\business\services\pagos\StripeGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StripeSimulado;

// Worker solo para carreras PostgreSQL locales; no acepta credenciales ni realiza tráfico externo.
require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    exit(2);
}
$app->make(Kernel::class)->bootstrap();
$c = config('database.connections.pgsql');
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || ! empty($c['url'])
    || $c['database'] !== 'clinica_vida_salud_test' || $c['username'] !== 'clinica_vida_salud_test') {
    exit(2);
}
$datos = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
if (! preg_match('/\Aprueba_stripe_[a-z0-9]{16}\z/', $datos['schema'] ?? '')) {
    exit(2);
}
DB::select('SELECT set_config(?, ?, false)', ['search_path', $datos['schema'].', public, pg_temp']);
config(['pagos.stripe' => ['habilitado' => true, 'secret_key' => 'sk_test_FicticiaNoValida', 'cuenta' => 'acct_Ficticia',
    'webhook_secret' => 'whsec_Ficticio', 'api_version' => '2026-10-28.endive', 'origen_retorno' => 'https://clinica.example.test']]);
config(['cors.allowed_origins' => ['https://clinica.example.test']]);
$app['env'] = 'local';
CarbonImmutable::setTestNow($datos['ahora']);
$gateway = new StripeSimulado(app(ConfiguracionStripe::class));
$gateway->sesiones = $datos['sesiones'];
if ($datos['pausa']) {
    $gateway->duranteRed = function () use ($gateway): void {
        $gateway->duranteRed = null;
        echo json_encode(['red' => true, 'transaccion' => DB::transactionLevel(),
            'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]), "\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'CONTINUAR') {
            exit(3);
        }
    };
}
$app->instance(StripeGateway::class, $gateway);
app(RecuperacionStripeService::class)->ejecutar($datos['operacion']);
echo json_encode(['fin' => true, 'llamadas' => count($gateway->llamadas)]), "\n";
fflush(STDOUT);
