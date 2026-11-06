<?php

// Exclusivamente pruebas aisladas. El doble de autenticación nunca pertenece al código funcional.
require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) { exit(2); }
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = config('database.connections.pgsql');
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || ! empty($c['url'])
    || $c['database'] !== 'clinica_vida_salud_test' || $c['username'] !== 'clinica_vida_salud_test'
    || ! in_array($c['host'], ['127.0.0.1', 'localhost', '::1'], true)) { exit(2); }
$d = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
if (! preg_match('/\Aprueba_simulado_[a-z0-9]{16}\z/', $d['schema'] ?? '')) { exit(2); }
Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['search_path', $d['schema'].', public, pg_temp']);
Illuminate\Support\Facades\DB::statement("SET statement_timeout = '15000ms'");
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['pagos.simulacion_habilitada' => true]);
Carbon\CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
$actor = Clinica\data\models\Usuario::findOrFail($d['actor']);
$auth = Mockery::mock(Clinica\business\services\usuarios\AutenticacionService::class);
$auth->shouldReceive('exigirSesionNormal')->andReturn($actor);
$app->instance(Clinica\business\services\usuarios\AutenticacionService::class, $auth);
try {
    $service = app(Clinica\business\services\pagos\SimulacionPagoService::class);
    if (($d['accion'] ?? null) === 'confirmar') {
        $r = $service->actuar(['objetivo' => $d['objetivo'], 'clave' => $d['clave']], 'confirmar');
        echo json_encode(['resultado' => $r['estado']])."\n";
    } else {
        $service->iniciar(['objetivo' => $d['objetivo'], 'revision' => $d['revision'], 'clave' => $d['clave'],
            'modalidad' => 'stripe', 'escenario' => 'completado']);
        echo json_encode(['resultado' => 'creado'])."\n";
    }
} catch (Clinica\shared\errors\AppError $e) { echo json_encode(['resultado' => $e->codigo])."\n"; }
