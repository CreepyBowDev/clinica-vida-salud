<?php

// Proceso exclusivo de pruebas: transporte sustituido, nunca DNS/HTTP ni claves privadas.
use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\DespachoPushService;
use Clinica\data\models\EnvioRecordatorioPush;
use Clinica\data\repositories\recordatorios\EnvioRecordatorioPushRepository;
use Clinica\shared\utils\TransportePush;
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
$modo = $argv[2] ?? '';
$tipo = $argv[3] ?? 'Aceptado';
if ($app->configurationIsCached() || ! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'clinica_vida_salud_test'
    || config('database.connections.pgsql.username') !== 'clinica_vida_salud_test'
    || config('database.connections.pgsql.url') || ! preg_match('/\Aprueba_despacho_[a-z0-9]{16}\z/', $schema)
    || ! in_array($modo, ['claim', 'transport', 'normal'], true) || ! in_array($tipo, ['Aceptado', 'Obsoleta', 'Incierto'], true)) {
    throw new RuntimeException('Worker solo permitido en esquema de tests.');
}
$identity = DB::selectOne('SELECT current_database() AS db,current_user AS usuario,pg_backend_pid() AS pid');
if ($identity->db !== 'clinica_vida_salud_test' || $identity->usuario !== 'clinica_vida_salud_test') {
    throw new RuntimeException('Identidad no permitida.');
}
DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
Http::preventStrayRequests();
Mail::fake();
config(['push.habilitado' => true]);
CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
if ($modo === 'claim') {
    $app->instance(EnvioRecordatorioPushRepository::class, new class extends EnvioRecordatorioPushRepository
    {
        public function guardar(EnvioRecordatorioPush $envio, array $datos, CarbonImmutable $ahora): void
        {
            parent::guardar($envio, $datos, $ahora);
            if ($datos['estado'] === 'Procesando') {
                DB::afterCommit(function (): void {
                    echo json_encode(['claimed' => true, 'tx' => DB::transactionLevel()]), "\n";
                    flush();
                    if (trim((string) fgets(STDIN)) !== 'continuar') {
                        throw new RuntimeException('Barrera de test interrumpida.');
                    }
                });
            }
        }
    });
}
$app->instance(TransportePush::class, new class($modo, $tipo) extends TransportePush
{
    public function __construct(private string $modo, private string $tipo) {}

    public function enviar(#[SensitiveParameter] array $suscripcion, DateTimeInterface $inicio): array
    {
        echo json_encode(['sending' => true, 'tx' => DB::transactionLevel()]), "\n";
        flush();
        if ($this->modo === 'transport' && trim((string) fgets(STDIN)) !== 'continuar') {
            throw new RuntimeException('Barrera de test interrumpida.');
        }

        return ['tipo' => $this->tipo, 'http' => null, 'retry_after' => null];
    }
});
echo json_encode(['ready' => $identity->pid]), "\n";
flush();
echo json_encode(['result' => $app->make(DespachoPushService::class)->despachar(1)]), "\n";
