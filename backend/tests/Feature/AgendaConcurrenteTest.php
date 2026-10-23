<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AgendaConcurrenteTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $previousPath;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_auth_'.strtolower(Str::random(16));
        $this->previousPath = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($worker['process']);
            }
            CarbonImmutable::setTestNow();
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->previousPath]);
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        } finally {
            parent::tearDown();
        }
    }

    private function signal(int $index, string $text): void
    {
        fwrite($this->workers[$index]['pipes'][0], $text."\n");
        fflush($this->workers[$index]['pipes'][0]);
    }

    private function line(int $index): string
    {
        $end = microtime(true) + 15;
        $line = '';
        do {
            $chunk = fgets($this->workers[$index]['pipes'][1]);
            if ($chunk !== false) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    return trim($line);
                }
            }
            if (stream_get_contents($this->workers[$index]['pipes'][2]) !== '') {
                throw new RuntimeException('Falló el worker aislado.');
            }
            usleep(10000);
        } while (microtime(true) < $end);
        throw new RuntimeException('Venció la barrera del worker.');
    }

    private function worker(array $cookies, string $ruta): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(['schema' => $this->schema, 'mode' => 'agenda-antes', 'ruta' => $ruta,
            'cookies' => $cookies, 'ip' => '192.0.2.71', 'ahora' => '2026-11-01T11:00:00Z']));
        $ready = json_decode($this->line($index), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ready', $ready);
        $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $ready['ready']);
        $this->workers[$index]['pid'] = $ready['ready'];

        return $index;
    }

    private function blocked(int $index): void
    {
        $end = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            if (DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$this->workers[$index]['pid']])?->wait_event_type === 'Lock') {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $end);
        $this->fail('La consulta no esperó el lock real de PostgreSQL.');
    }

    public static function carreras(): array
    {
        $cases = [];
        foreach (['selector', 'agenda', 'mis-citas', 'historial', 'detalle-medico', 'detalle-paciente'] as $endpoint) {
            foreach (['estado', 'rol', 'sesion', 'clave', 'vinculo'] as $change) {
                if ($change === 'vinculo' && in_array($endpoint, ['selector', 'historial'], true)) {
                    continue;
                }
                foreach ([true, false] as $commit) {
                    $cases[] = [$endpoint, $change, $commit];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('carreras')]
    public function test_queries_revalidate_committed_revocation_after_middleware_and_real_lock_wait(string $endpoint, string $change, bool $commit): void
    {
        $rol = match ($endpoint) {
            'agenda', 'detalle-medico' => 'Médico',
            'mis-citas', 'detalle-paciente' => 'Paciente',
            default => 'Recepcionista',
        };
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Médico') {
            $r['doctors'][0] = $actor->medico->getKey();
            DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']]);
        } elseif ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['motivo_breve' => 'MOTIVO_CONCURRENTE_FICTICIO']), 'id_cita');
        $ruta = match ($endpoint) {
            'selector' => '/api/agenda/medicos', 'agenda' => '/api/agenda', 'mis-citas' => '/api/mis-citas',
            'historial' => '/api/pacientes/'.$r['patient'].'/citas', default => '/api/citas/'.$id,
        };
        $worker = $this->worker($cookies, $ruta);
        $this->signal($worker, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($worker)); // Ya pasó middleware de sesión/rol.
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->lockForUpdate()->first();
        $this->signal($worker, 'CONTINUAR');
        $this->blocked($worker);
        if ($change === 'estado') {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        } elseif ($change === 'rol') {
            $role = DB::table('roles')->insertGetId(['nombre' => 'Administrador'], 'id_rol');
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['id_rol' => $role]);
        } elseif ($change === 'sesion') {
            DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        } elseif ($change === 'clave') {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['password_hash' => Hash::make('Frase nueva ficticia 123')]);
        } else {
            DB::table($rol === 'Médico' ? 'medicos' : 'pacientes')->where('id_usuario', $actor->getKey())->update(['id_usuario' => null]);
        }
        $commit ? DB::commit() : DB::rollBack();
        $result = json_decode($this->line($worker), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($commit ? 401 : 200, $result['status']);
        if ($commit) {
            $this->assertSame('AUTH_NO_AUTENTICADO', $result['body']['error']['code']);
            $this->assertArrayNotHasKey('data', $result['body']);
        } elseif (str_starts_with($endpoint, 'detalle-')) {
            $this->assertSame('MOTIVO_CONCURRENTE_FICTICIO', $result['body']['data']['motivo_breve']);
        } else {
            $this->assertStringNotContainsString('motivo_breve', json_encode($result['body']));
        }
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame('Programada', DB::table('citas')->value('estado'));
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->assertSame(0, DB::table('recordatorios')->count());
    }
}
