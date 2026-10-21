<?php

// Router de servidor loopback exclusivo de pruebas: no se registra en public/ ni Artisan.
use Clinica\business\services\usuarios\CorreoSeguridadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT current_database() AS db, current_user AS username');
$schema = getenv('RECUPERACION_TEST_SCHEMA');
$barrera = getenv('RECUPERACION_TEST_BARRERA');
if (! $app->environment('testing') || $identity->db !== 'clinica_vida_salud_test' || $identity->username !== 'clinica_vida_salud_test'
    || ! preg_match('/^prueba_auth_[a-z0-9]{16}$/D', $schema) || ! is_dir($barrera)) {
    throw new RuntimeException('El servidor requiere base, rol y barrera aislados de pruebas.');
}
Http::preventStrayRequests();
DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
$app->instance(CorreoSeguridadService::class, new class($barrera) extends CorreoSeguridadService
{
    public function __construct(private string $barrera) {}

    public function enviarRecuperacion(string $correo, #[SensitiveParameter] string $token): string
    {
        file_put_contents($this->barrera.'/envio', 'iniciado');
        $limite = microtime(true) + 10;
        while (! is_file($this->barrera.'/continuar')) {
            if (microtime(true) >= $limite) {
                throw new RuntimeException('Falta liberación de la barrera HTTP.');
            }
            usleep(10000);
        }

        return 'simulado';
    }
});
$app->handleRequest(Request::capture());
