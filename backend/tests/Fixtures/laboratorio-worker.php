<?php

// Worker exclusivo para carreras HTTP/SQL de laboratorio, sin ruta ni comando productivo.
use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::selectOne('SELECT current_database() AS db, current_user AS username');
    if (! $app->environment('testing') || $db->db !== 'clinica_vida_salud_test' || $db->username !== 'clinica_vida_salud_test') {
        throw new RuntimeException('Requiere base/rol exclusivos de tests.');
    }
    $data = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/\Aprueba_lab_http_[a-z0-9]{16}\z/', $data['schema'])) {
        throw new RuntimeException('Esquema no permitido.');
    }
    DB::select('SELECT set_config(?, ?, false)', ['search_path', $data['schema'].', public, pg_temp']);
    config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'session.lifetime' => 4320,
        'seguridad.correo.mailer' => 'array', 'laboratorio.raiz' => storage_path('framework/testing/'.$data['schema'])]);
    $app['env'] = 'local'; // CSRF real después de verificar entorno aislado.
    CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    Http::preventStrayRequests();
    Mail::fake();
    if ($data['pausar_evento'] ?? false) {
        $app->instance(BitacoraRepository::class, new class extends BitacoraRepository
        {
            private bool $pausado = false;

            public function documentoLaboratorioRegistrado(Usuario $actor, array $contexto, string $tipo, array $referencias, CarbonImmutable $ahora): void
            {
                parent::documentoLaboratorioRegistrado($actor, $contexto, $tipo, $referencias, $ahora);
                if (! $this->pausado) {
                    $this->pausado = true;
                    echo "CAMBIO_PENDIENTE\n";
                    fflush(STDOUT);
                    if (trim(fgets(STDIN)) !== 'CONFIRMAR') {
                        throw new RuntimeException('Rollback simulado de laboratorio.');
                    }
                }
            }
        });
    }
    echo json_encode(['ready' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]), "\n";
    fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'INICIAR') {
        throw new RuntimeException('Falta barrera de inicio.');
    }
    $request = Request::create('http://localhost:8000'.$data['ruta'], $data['metodo'], [], $data['cookies'], [], [
        'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '192.0.2.74', 'HTTP_X_XSRF_TOKEN' => $data['cookies']['XSRF-TOKEN'] ?? '',
    ], json_encode($data['payload'] ?? []));
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $salida = ['status' => $response->getStatusCode()];
    if ($response instanceof StreamedResponse) {
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $salida += ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    } else {
        $salida['body'] = json_decode($response->getContent(), true);
    }
    $kernel->terminate($request, $response);
    echo json_encode($salida), "\n";
    fflush(STDOUT);
} catch (Throwable $error) {
    fwrite(STDERR, 'Worker de laboratorio: '.$error::class."\n");
    exit(1);
}
