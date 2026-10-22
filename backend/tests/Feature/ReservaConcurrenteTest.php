<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReservaConcurrenteTest extends TestCase
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
        DB::table('configuracion_clinica')->insert(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia', 'duracion_turno_minutos' => 15]);
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

    private function worker(array $cookies, string $token, bool $pause = false, string $mode = 'reserva-confirmar', array $extra = []): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(['schema' => $this->schema, 'mode' => $mode, 'cookies' => $cookies,
            'payload' => ['propuesta' => $token], 'pausar_planificacion' => $pause, 'ip' => '192.0.2.70', 'ahora' => '2026-11-01T11:00:00Z', ...$extra]));
        $ready = json_decode($this->line($index), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ready', $ready);
        $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $ready['ready']);
        foreach ($this->workers as $other) {
            if (isset($other['pid'])) {
                $this->assertNotSame($other['pid'], $ready['ready']);
            }
        }
        $this->workers[$index]['pid'] = $ready['ready'];

        return $index;
    }

    private function signal(int $worker, string $text): void
    {
        fwrite($this->workers[$worker]['pipes'][0], $text."\n");
        fflush($this->workers[$worker]['pipes'][0]);
    }

    private function line(int $worker): string
    {
        $deadline = microtime(true) + 15;
        $line = '';
        do {
            $chunk = fgets($this->workers[$worker]['pipes'][1]);
            if ($chunk !== false) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    return trim($line);
                }
            }
            if (stream_get_contents($this->workers[$worker]['pipes'][2]) !== '') {
                throw new RuntimeException('Falló el worker aislado.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Venció la barrera del worker.');
    }

    private function blocked(int $worker): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            if (DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$this->workers[$worker]['pid']])?->wait_event_type === 'Lock') {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('El segundo proceso no esperó un lock PostgreSQL.');
    }

    private function propuesta(array &$cookies, array $r, int $doctor = 0, int $turno = 0): string
    {
        $query = ['id_paciente' => (string) $r['patient'], 'id_medico' => (string) $r['doctors'][$doctor],
            'id_especialidad' => (string) $r['specialty'], 'fecha' => '2026-11-01'];
        $slot = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($query))->assertOk()->json('data.turnos.'.$turno);

        return $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$query,
            'hora_inicio' => $slot['hora_inicio'], 'revision_turno' => $slot['revision_turno']])->assertOk()->json('data.propuesta');
    }

    private function preparar(): array
    {
        $cookies = [];
        foreach ([0, 1] as $index) {
            $actor = $this->usuarioAuth('Recepcionista');
            $cookies[$index] = $this->csrfAuth();
            $this->loginAuth($cookies[$index], $actor)->assertOk();
        }
        $r = $this->citaResources();
        foreach ($r['doctors'] as $id) {
            DB::table('horarios_atencion')->insert(['id_medico' => $id, 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00']);
        }

        return [$cookies, $r];
    }

    public static function carreras(): array
    {
        $cases = [];
        foreach (['medico', 'paciente', 'misma_propuesta', 'resultado', 'contiguas'] as $kind) {
            foreach ([true, false] as $commit) {
                $cases[] = [$kind, $commit];
            }
        }

        return $cases;
    }

    #[DataProvider('carreras')]
    public function test_real_reservations_wait_and_reconcile_commit_or_rollback(string $kind, bool $commit): void
    {
        [$cookies, $r] = $this->preparar();
        $first = $this->propuesta($cookies[0], $r);
        $second = in_array($kind, ['misma_propuesta', 'resultado'], true) ? $first
            : $this->propuesta($cookies[1], $r, $kind === 'paciente' ? 1 : 0, $kind === 'contiguas' ? 1 : 0);
        $w1 = $this->worker($cookies[0], $first, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker(in_array($kind, ['misma_propuesta', 'resultado'], true) ? $cookies[0] : $cookies[1], $second,
            mode: $kind === 'resultado' ? 'reserva-resultado' : 'reserva-confirmar');
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame(0, DB::table('citas')->count());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $a = json_decode($this->line($w1), true);
        $b = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 201 : 500, $a['status']);
        $expected = $kind === 'resultado' || ($kind === 'misma_propuesta' && $commit) ? 200
            : ($commit && in_array($kind, ['medico', 'paciente'], true) ? 409 : 201);
        $this->assertSame($expected, $b['status']);
        if ($kind === 'resultado') {
            $this->assertSame($commit, $b['body']['data']['registrada']);
        }
        if ($kind === 'misma_propuesta' && $commit) {
            $this->assertTrue($b['body']['meta']['repetida']);
            $this->assertSame($a['body']['data']['id_cita'], $b['body']['data']['id_cita']);
        }
        $count = $kind === 'resultado' ? (int) $commit : ($kind === 'contiguas' && $commit ? 2 : 1);
        $this->assertSame($count, DB::table('citas')->count());
        $this->assertSame($count, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }

    public static function sqlCarreras(): array
    {
        $cases = [];
        foreach (['paciente', 'medico', 'consultorio', 'migracion'] as $kind) {
            foreach ([true, false] as $commit) {
                $cases[] = [$kind, $commit];
            }
        }

        return $cases;
    }

    #[DataProvider('sqlCarreras')]
    public function test_independent_sql_and_migration_wait_for_prior_writer_without_application_protocol(string $kind, bool $commit): void
    {
        [, $r] = $this->preparar();
        if ($kind === 'migracion') {
            (require base_path('src/data/database/migrations/2026_10_22_000035_prevent_patient_appointment_overlap.php'))->down();
            DB::table('citas')->insert($this->citaRow($r));
        }
        $other = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'SQL-FICTICIO', 'nombres' => 'Paciente', 'apellidos' => 'SQL',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        DB::beginTransaction();
        DB::table('citas')->insert($this->citaRow($r, $kind === 'migracion'
            ? ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]] : []));
        $fila = $this->citaRow($r, match ($kind) {
            'medico' => ['id_paciente' => $other, 'id_consultorio' => $r['rooms'][1]],
            'consultorio' => ['id_paciente' => $other, 'id_medico' => $r['doctors'][1]],
            default => ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]],
        });
        $w = $this->worker([], '', mode: $kind === 'migracion' ? 'reserva-migracion' : 'reserva-sql', extra: ['fila' => $fila]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $commit ? DB::commit() : DB::rollBack();
        $result = json_decode($this->line($w), true);
        $this->assertSame(! $commit, $result['ok']);
        if ($commit) {
            $this->assertSame($kind === 'migracion' ? 'CITAS_PACIENTE_CONFLICTO_PREVIO' : '23P01', $result[$kind === 'migracion' ? 'code' : 'sqlstate']);
        }
        $this->assertSame($kind === 'migracion' && $commit ? 2 : 1, DB::table('citas')->count());
        if ($kind === 'migracion') {
            $this->assertSame($commit ? 2 : 3, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid='citas'::regclass AND contype='x'")->n);
        }
    }

    public static function revocaciones(): array
    {
        $cases = [];
        foreach (['estado', 'rol', 'sesion', 'clave'] as $kind) {
            foreach ([true, false] as $commit) {
                foreach (['reserva-confirmar', 'reserva-resultado'] as $mode) {
                    $cases[] = [$kind, $commit, $mode];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('revocaciones')]
    public function test_reservation_services_revalidate_actor_after_middleware_and_lock_wait(string $kind, bool $commit, string $mode): void
    {
        [$cookies, $r] = $this->preparar();
        $token = $this->propuesta($cookies[0], $r);
        $actor = DB::table('usuarios')->where('id_rol', DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol'))->orderBy('id_usuario')->value('id_usuario');
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $w = $this->worker($cookies[0], $token, mode: $mode);
        $this->signal($w, 'INICIAR');
        // La barrera administrativa está en el Service, después de los middlewares.
        $this->blocked($w);
        match ($kind) {
            'estado' => DB::table('usuarios')->where('id_usuario', $actor)->update(['estado_cuenta' => 'Desactivada']),
            'rol' => DB::table('usuarios')->where('id_usuario', $actor)->update(['id_rol' => DB::table('roles')->insertGetId(['nombre' => 'Administrador'], 'id_rol')]),
            'sesion' => DB::table('sessions')->where('user_id', $actor)->delete(),
            'clave' => DB::table('usuarios')->where('id_usuario', $actor)->update(['password_hash' => Hash::make('Nueva frase ficticia para pruebas 567')]),
        };
        $commit ? DB::commit() : DB::rollBack();
        $result = json_decode($this->line($w), true);
        $this->assertSame($commit ? 401 : ($mode === 'reserva-confirmar' ? 201 : 200), $result['status']);
        $this->assertSame(! $commit && $mode === 'reserva-confirmar' ? 1 : 0, DB::table('citas')->count());
    }

    public static function cambiosAdministrativos(): array
    {
        $cases = [];
        foreach (['horario', 'duracion', 'asignacion', 'habilitacion', 'medico', 'especialidad', 'paciente'] as $kind) {
            foreach ([true, false] as $reserveFirst) {
                foreach ([true, false] as $commit) {
                    $cases[] = [$kind, $reserveFirst, $commit];
                }
            }
        }

        return $cases;
    }

    private function cambio(string $kind, array $r, array &$cookies): array
    {
        if ($kind === 'paciente') {
            $version = $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['patient'])->assertOk()->json('data.actualizado_en');

            return ['mode' => 'paciente-estado', 'id_objetivo' => $r['patient'], 'payload' => ['activo' => false, 'motivo' => 'Cambio ficticio concurrente', 'actualizado_en_original' => $version]];
        }
        $base = '/api/medicos/'.$r['doctors'][0];
        $version = $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->json('data.actualizado_en');
        $datos = ['actualizado_en_original' => $version];
        if ($kind === 'asignacion') {
            $sala = DB::table('consultorios')->insertGetId(['codigo_consultorio' => 'TERCERA-SALA', 'nombre' => 'Sala ficticia libre'], 'id_consultorio');
            $payload = [...$datos, 'id_consultorio' => (string) $sala];
            $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $payload)->assertOk()->json('data.huella_impacto');

            return ['mode' => 'consultorio-asignar', 'id_objetivo' => $r['doctors'][0],
                'payload' => [...$payload, 'huella_impacto' => $huella, 'confirmar_traslados' => false]];
        }
        if ($kind === 'habilitacion') {
            $extra = DB::table('especialidades')->insertGetId(['nombre' => 'Alternativa ficticia'], 'id_especialidad');
            DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $extra]);

            return ['mode' => 'medico-especialidades', 'id_objetivo' => $r['doctors'][0], 'payload' => [...$datos, 'especialidades' => [(string) $extra]]];
        }
        if ($kind === 'duracion') {
            $config = $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertOk()->json('data');

            return ['mode' => 'configuracion-citas', 'payload' => ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']]];
        }
        if ($kind === 'especialidad') {
            $version = $this->navegador($cookies, 'GET', '/api/especialidades/'.$r['specialty'])->assertOk()->json('data.actualizado_en');

            return ['mode' => 'especialidad-estado', 'id_objetivo' => $r['specialty'], 'payload' => ['activa' => false, 'actualizado_en_original' => $version]];
        }

        return $kind === 'horario'
            ? ['mode' => 'horario-estado', 'id_objetivo' => $r['doctors'][0], 'id_horario' => DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->value('id_horario'), 'payload' => [...$datos, 'activo' => false]]
            : ['mode' => 'medico-estado', 'id_objetivo' => $r['doctors'][0], 'payload' => [...$datos, 'activo' => false]];
    }

    #[DataProvider('cambiosAdministrativos')]
    public function test_real_administrative_changes_and_reservation_in_both_orders(string $kind, bool $reserveFirst, bool $commit): void
    {
        [$cookies, $r] = $this->preparar();
        $admin = $this->usuarioAuth('Administrador');
        $managementCookies = $kind === 'paciente' ? $cookies[1] : $this->csrfAuth();
        if ($kind !== 'paciente') {
            $this->loginAuth($managementCookies, $admin)->assertOk();
        }
        $change = $this->cambio($kind, $r, $managementCookies);
        $token = $this->propuesta($cookies[0], $r);
        $start = fn (bool $reservation, bool $pause): int => $reservation
            ? $this->worker($cookies[0], $token, $pause)
            : $this->worker($managementCookies, '', $pause, $change['mode'], $change);
        $w1 = $start($reserveFirst, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $start(! $reserveFirst, false);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $a = json_decode($this->line($w1), true);
        $b = json_decode($this->line($w2), true);
        $this->assertSame($commit ? ($reserveFirst ? 201 : 200) : 500, $a['status']);
        $expected = ! $commit ? ($reserveFirst ? 200 : 201)
            : (! $reserveFirst || in_array($kind, ['horario', 'asignacion', 'habilitacion'], true) ? 409 : 200);
        $this->assertSame($expected, $b['status']);
        $count = $reserveFirst ? (int) $commit : (int) ! $commit;
        $this->assertSame($count, DB::table('citas')->count());
        $this->assertSame($count, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
        if ($count) {
            $row = DB::table('citas')->sole();
            $this->assertSame('Programada', $row->estado);
            $this->assertSame(15, $row->duracion_minutos);
            $this->assertSame($r['rooms'][0], $row->id_consultorio);
            $this->assertSame(0, DB::table('cambios_cita')->count());
        }
    }
}
