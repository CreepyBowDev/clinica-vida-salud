<?php

// Solo procesos de pruebas, esquema explícito y sin HTTP/correo real.
use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Usuario;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$schema = $argv[1] ?? '';
if ($app->configurationIsCached() || ! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'clinica_vida_salud_test'
    || config('database.connections.pgsql.username') !== 'clinica_vida_salud_test'
    || config('database.connections.pgsql.url') || ! preg_match('/\Aprueba_recordatorio_[a-z0-9]{16}\z/', $schema)) {
    throw new RuntimeException('Worker solo permitido en esquema de tests.');
}
$identity = DB::selectOne('SELECT current_database() AS db,current_user AS usuario,pg_backend_pid() AS pid');
if ($identity->db !== 'clinica_vida_salud_test' || $identity->usuario !== 'clinica_vida_salud_test') {
    throw new RuntimeException('Identidad no permitida.');
}
DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
Http::preventStrayRequests();
Mail::fake();
config(['push.habilitado' => true, 'push.vapid_publica' => rtrim(strtr(base64_encode(hex2bin('046b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c2964fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5')), '+/', '-_'), '=')]);
CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
echo json_encode(['ready' => $identity->pid, 'citas' => DB::table('citas')->count(), 'ahora' => CarbonImmutable::now('UTC')->format('c'),
    'inicio' => DB::table('citas')->value('inicio_at')]), "\n";
flush();
if (isset($argv[2], $argv[3])) {
    $admin = $argv[2];
    $datos = json_decode(base64_decode($argv[3], true), true, flags: JSON_THROW_ON_ERROR);
    $auth = Mockery::mock(AutenticacionService::class);
    $auth->shouldReceive('exigirSesionNormal')->andReturnUsing(fn () => Usuario::query()->whereKey($admin)->lockForUpdate()->firstOrFail()->load('rol'));
    $app->instance(AutenticacionService::class, $auth);
    try {
        echo json_encode(['result' => app(RecordatorioService::class)->enviarManual($datos)]), "\n";
    } catch (AppError $e) {
        echo json_encode(['result' => ['conflicto' => $e->codigo]]), "\n";
    }
} else {
    echo json_encode(['result' => app(RecordatorioService::class)->generar(1)]), "\n";
}
