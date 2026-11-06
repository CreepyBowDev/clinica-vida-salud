<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class GestionCitaConcurrenteTest extends TestCase
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
            // Solo el esquema aleatorio creado por este caso, nunca esquemas ajenos/public.
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        } finally {
            parent::tearDown();
        }
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
                throw new RuntimeException('Falló el worker aislado de gestión.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Venció la barrera del worker de gestión.');
    }

    private function worker(array $cookies, string $ruta, array $payload = [], bool $pause = false, array $extra = []): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso de gestión.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(['schema' => $this->schema, 'mode' => 'gestion-http',
            'metodo' => 'PATCH', 'ruta' => $ruta, 'cookies' => $cookies, 'payload' => $payload,
            'pausar_planificacion' => $pause, 'ip' => '192.0.2.72', 'ahora' => '2026-11-01T11:00:00Z', ...$extra]));
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
        $this->fail('El proceso no esperó un lock real PostgreSQL.');
    }

    private function preparar(bool $finanzas = false): array
    {
        $cookies = [];
        $actores = [];
        foreach (['Administrador', 'Recepcionista'] as $i => $rol) {
            $actores[$i] = $this->usuarioAuth($rol);
            $cookies[$i] = $this->csrfAuth();
            $this->loginAuth($cookies[$i], $actores[$i])->assertOk();
        }
        $r = $this->citaResources();
        foreach ($r['doctors'] as $medico) {
            DB::table('horarios_atencion')->insert(['id_medico' => $medico, 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00']);
        }
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-11-01T12:30:00Z',
            'fin_at' => '2026-11-01T12:45:00Z', 'estado' => 'Confirmada', 'actualizada_en' => '2026-11-01T10:00:00Z',
            'incorporada_finanzas' => $finanzas]), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);

        return [$cookies, $r, $id, $actores];
    }

    private function datos(array &$cookies, string $id, string $operacion, int $turno = 0): array
    {
        if ($operacion === 'cancelacion') {
            $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');

            return ['version_programacion_original' => $vista['version_programacion'],
                'actualizada_en_original' => $vista['actualizada_en'], 'motivo' => 'MOTIVO-PRIVADO-CONCURRENCIA'];
        }
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->json('data');

        return ['version_programacion_original' => $vista['cita_original']['version_programacion'],
            'actualizada_en_original' => $vista['cita_original']['actualizada_en'], 'fecha' => '2026-11-01',
            'hora_inicio' => $vista['turnos'][$turno]['hora_inicio'], 'revision_turno' => $vista['turnos'][$turno]['revision_turno']];
    }

    public static function carrerasFinancieras(): array
    {
        return [['monto', 'monto', true], ['monto', 'monto', false], ['monto', 'cancelacion', true],
            ['monto', 'cancelacion', false], ['cancelacion', 'monto', true], ['cancelacion', 'monto', false]];
    }

    #[DataProvider('carrerasFinancieras')]
    public function test_financial_assignment_serializes_with_assignment_and_cancellation(string $primera, string $segunda, bool $commit): void
    {
        config(['pagos.incorporacion_habilitada' => true]);
        [$cookies, $r, $id] = $this->preparar(true);
        $payloads = [];
        foreach ([$primera, $segunda] as $i => $operacion) {
            $payloads[$i] = $operacion === 'cancelacion' ? $this->datos($cookies[$i], $id, 'cancelacion')
                : ['propuesta' => $this->navegador($cookies[$i], 'POST', '/api/citas/'.$id.'/monto/propuesta',
                    ['monto_bob' => $i === 0 ? '70.00' : '140.00'])->assertOk()->json('data.propuesta')];
        }
        $w1 = $this->worker($cookies[0], '/api/citas/'.$id.'/'.$primera, $payloads[0], true,
            ['metodo' => $primera === 'monto' ? 'POST' : 'PATCH', 'habilitar_pagos' => true]);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($cookies[1], '/api/citas/'.$id.'/'.$segunda, $payloads[1], false,
            ['metodo' => $segunda === 'monto' ? 'POST' : 'PATCH', 'habilitar_pagos' => true]);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $a = json_decode($this->line($w1), true);
        $b = json_decode($this->line($w2), true);
        $this->assertSame($commit ? ($primera === 'monto' ? 201 : 200) : 500, $a['status']);
        $conflicto = $commit && ($segunda === 'monto');
        $this->assertSame($conflicto ? 409 : ($segunda === 'monto' ? 201 : 200), $b['status']);
        $cancelada = ($commit && $primera === 'cancelacion') || (! $conflicto && $segunda === 'cancelacion');
        $this->assertSame($cancelada ? 'Cancelada' : 'Confirmada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $obligacion = DB::table('obligaciones_pago')->first();
        if ($commit && $primera === 'cancelacion' || ! $commit && $segunda === 'cancelacion') {
            $this->assertNull($obligacion);
        } else {
            $this->assertSame(1, DB::table('obligaciones_pago')->count());
            $this->assertSame(! $cancelada, $obligacion->exigible);
            $this->assertSame($commit ? 7000 : 14000, $obligacion->centavos_bob);
            $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PAGO_OBLIGACION_EMITIDA')->count());
            if ($cancelada) {
                $this->assertNotNull($obligacion->id_cambio_anulacion);
            }
        }
        $this->assertSame(0, DB::table('intentos_pago')->count());
        $this->assertSame(0, DB::table('cobros_pago')->count());
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
    }

    public static function mismaCita(): array
    {
        $casos = [];
        foreach (['reprogramacion', 'cancelacion'] as $primera) {
            foreach (['reprogramacion', 'cancelacion'] as $segunda) {
                foreach ([true, false] as $commit) {
                    foreach ([0, 1] as $operador) {
                        $casos[] = [$primera, $segunda, $commit, $operador];
                    }
                }
            }
        }

        return $casos;
    }

    #[DataProvider('mismaCita')]
    public function test_two_real_management_writers_preserve_one_atomic_result(string $primera, string $segunda, bool $commit, int $operador): void
    {
        [$cookies, $r, $id, $actores] = $this->preparar();
        $a = $this->datos($cookies[$operador], $id, $primera);
        $b = $this->datos($cookies[1 - $operador], $id, $segunda);
        $w1 = $this->worker($cookies[$operador], '/api/citas/'.$id.'/'.$primera, $a, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($cookies[1 - $operador], '/api/citas/'.$id.'/'.$segunda, $b);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame('Confirmada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->assertNull(DB::table('recordatorios')->value('invalidado_en'));
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame($commit ? 409 : 200, $rb['status']);
        if ($commit) {
            $this->assertSame('CITA_EDICION_OBSOLETA', $rb['body']['error']['code']);
        }
        $ganadora = $commit ? $primera : $segunda;
        $fila = DB::table('citas')->sole();
        $this->assertSame($ganadora === 'cancelacion' ? 'Cancelada' : 'Programada', $fila->estado);
        $this->assertSame($ganadora === 'cancelacion' ? 1 : 2, $fila->version_programacion);
        $this->assertSame(1, DB::table('cambios_cita')->count());
        $cambio = DB::table('cambios_cita')->sole();
        $this->assertSame((string) $actores[$commit ? $operador : 1 - $operador]->getKey(), (string) $cambio->id_actor);
        $this->assertNotNull(DB::table('recordatorios')->value('invalidado_en'));
        $this->assertSame(1, DB::table('bitacora')->whereIn('tipo_evento', ['CITA_REPROGRAMADA', 'CITA_CANCELADA'])->count());
    }

    public static function revocaciones(): array
    {
        $casos = [];
        foreach (['gestion', 'disponibilidad-reprogramacion', 'reprogramacion', 'cancelacion'] as $i => $ruta) {
            foreach (['estado', 'rol', 'sesion', 'clave'] as $j => $cambio) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$ruta, $cambio, $commit, ($i + $j) % 2];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('revocaciones')]
    public function test_all_four_endpoints_revalidate_security_after_middleware_and_a_real_wait(string $ruta, string $cambio, bool $commit, int $operador): void
    {
        [$cookies, $r, $id, $actores] = $this->preparar();
        $escritura = in_array($ruta, ['reprogramacion', 'cancelacion'], true);
        $payload = $escritura ? $this->datos($cookies[$operador], $id, $ruta) : [];
        $uri = '/api/citas/'.$id.'/'.$ruta.($ruta === 'disponibilidad-reprogramacion' ? '?fecha=2026-11-01' : '');
        $w = $this->worker($cookies[$operador], $uri, $payload, extra: ['metodo' => $escritura ? 'PATCH' : 'GET', 'pausar_antes' => true]);
        $this->signal($w, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($w));
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $actores[$operador]->getKey())->lockForUpdate()->first();
        $this->signal($w, 'CONTINUAR');
        $this->blocked($w);
        $actor = $actores[$operador]->getKey();
        match ($cambio) {
            'estado' => DB::table('usuarios')->where('id_usuario', $actor)->update(['estado_cuenta' => 'Desactivada']),
            'rol' => DB::table('usuarios')->where('id_usuario', $actor)->update(['id_rol' => $actores[1 - $operador]->id_rol]),
            'sesion' => DB::table('sessions')->where('user_id', $actor)->delete(),
            'clave' => DB::table('usuarios')->where('id_usuario', $actor)->update(['password_hash' => Hash::make('Frase nueva ficticia segura 123!')]),
        };
        $commit ? DB::commit() : DB::rollBack();
        $resultado = json_decode($this->line($w), true);
        $this->assertSame($commit ? 401 : 200, $resultado['status']);
        if ($commit) {
            $this->assertSame('AUTH_NO_AUTENTICADO', $resultado['body']['error']['code']);
            $this->assertArrayNotHasKey('data', $resultado['body']);
        }
        $this->assertSame(! $commit && $escritura ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame(! $commit && $escritura ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', ['CITA_REPROGRAMADA', 'CITA_CANCELADA'])->count());
    }

    public static function planificacion(): array
    {
        $casos = [];
        foreach (['reprogramacion', 'cancelacion'] as $operacion) {
            foreach (['duracion', 'horario', 'asignacion', 'habilitacion', 'medico', 'especialidad', 'paciente', 'consulta'] as $cambio) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$operacion, $cambio, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('planificacion')]
    public function test_management_waits_for_independent_planning_or_clinical_protocol(string $operacion, string $cambio, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $payload = $this->datos($cookies[0], $id, $operacion);
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        // Escritor transaccional simulado del protocolo; no acredita endpoint CU-23 ni permiso para cambios incompatibles.
        match ($cambio) {
            'duracion' => DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 20]),
            'horario' => DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->update(['hora_inicio' => '08:15:00']),
            'asignacion' => DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $r['rooms'][1]]),
            'habilitacion' => DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]),
            'medico' => DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false]),
            'especialidad' => DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]),
            'paciente' => DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]),
            'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-PROTOCOLO', 'iniciada_en' => null]),
        };
        $w = $this->worker($cookies[0], '/api/citas/'.$id.'/'.$operacion, $payload);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $commit ? DB::commit() : DB::rollBack();
        $resultado = json_decode($this->line($w), true);
        $rechazo = $commit && ($cambio === 'consulta' || $operacion === 'reprogramacion');
        $this->assertSame($rechazo ? 409 : 200, $resultado['status']);
        $this->assertSame($rechazo ? 0 : 1, DB::table('cambios_cita')->count());
        $this->assertSame($rechazo ? 0 : 1, DB::table('bitacora')->whereIn('tipo_evento', ['CITA_REPROGRAMADA', 'CITA_CANCELADA'])->count());
        $this->assertSame($rechazo, DB::table('recordatorios')->value('invalidado_en') === null);
    }

    public static function destinos(): array
    {
        return [['medico', true], ['medico', false], ['paciente', true], ['paciente', false], ['contiguas', true], ['contiguas', false]];
    }

    #[DataProvider('destinos')]
    public function test_two_distinct_citas_cannot_take_one_destination_but_can_be_contiguous(string $tipo, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $fila = ['inicio_at' => '2026-11-02T12:30:00Z', 'fin_at' => '2026-11-02T12:45:00Z'];
        if ($tipo === 'paciente') {
            $fila += ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]];
        } else {
            $fila['id_paciente'] = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'OTRA-FICHA', 'nombres' => 'Otro',
                'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        }
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, $fila), 'id_cita');
        $a = $this->datos($cookies[0], $id, 'reprogramacion');
        $b = $this->datos($cookies[1], $otro, 'reprogramacion', $tipo === 'contiguas' ? 1 : 0);
        $w1 = $this->worker($cookies[0], '/api/citas/'.$id.'/reprogramacion', $a, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($cookies[1], '/api/citas/'.$otro.'/reprogramacion', $b);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame($commit && $tipo !== 'contiguas' ? 409 : 200, $rb['status']);
        $this->assertSame($commit && $tipo === 'contiguas' ? 2 : 1, DB::table('cambios_cita')->count());
    }

    public static function reservas(): array
    {
        $casos = [];
        foreach (['medico', 'paciente'] as $tipo) {
            foreach ([true, false] as $gestionPrimero) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$tipo, $gestionPrimero, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('reservas')]
    public function test_real_booking_and_reprogramming_compete_in_both_orders(string $tipo, bool $gestionPrimero, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $gestion = $this->datos($cookies[0], $id, 'reprogramacion');
        $query = ['id_paciente' => (string) $r['patient'], 'id_medico' => (string) $r['doctors'][1],
            'id_especialidad' => (string) $r['specialty'], 'fecha' => '2026-11-01'];
        if ($tipo === 'medico') {
            $query['id_medico'] = (string) $r['doctors'][0];
            $query['id_paciente'] = (string) DB::table('pacientes')->insertGetId(['codigo_paciente' => 'FICHA-RESERVA',
                'nombres' => 'Reserva', 'apellidos' => 'Ficticia', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        }
        $turno = $this->navegador($cookies[1], 'GET', '/api/reservas/disponibilidad?'.http_build_query($query))->assertOk()->json('data.turnos.0');
        $token = $this->navegador($cookies[1], 'POST', '/api/reservas/propuesta', [...$query,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']])->assertOk()->json('data.propuesta');
        $start = fn (bool $esGestion, bool $pausa): int => $esGestion
            ? $this->worker($cookies[0], '/api/citas/'.$id.'/reprogramacion', $gestion, $pausa)
            : $this->worker($cookies[1], '/api/citas', ['propuesta' => $token], $pausa,
                ['mode' => 'reserva-confirmar', 'metodo' => 'POST']);
        $w1 = $start($gestionPrimero, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $start(! $gestionPrimero, false);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? ($gestionPrimero ? 200 : 201) : 500, $ra['status']);
        $this->assertSame($commit ? 409 : ($gestionPrimero ? 201 : 200), $rb['status']);
        $gestionGana = $commit ? $gestionPrimero : ! $gestionPrimero;
        $this->assertSame($gestionGana ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($gestionGana ? 1 : 2, DB::table('citas')->count());
        $this->assertSame($gestionGana ? 1 : 0, DB::table('bitacora')->where('tipo_evento', 'CITA_REPROGRAMADA')->count());
        $this->assertSame($gestionGana ? 0 : 1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }

    public static function sql(): array
    {
        return [['medico', true], ['medico', false], ['sala', true], ['sala', false], ['paciente', true], ['paciente', false]];
    }

    #[DataProvider('sql')]
    public function test_sql_without_application_protocol_waits_for_reprogramming_exclusion(string $tipo, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $gestion = $this->datos($cookies[0], $id, 'reprogramacion');
        $otro = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'SQL-FICHA', 'nombres' => 'SQL',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $fila = $this->citaRow($r, ['id_medico' => $r['doctors'][$tipo === 'medico' ? 0 : 1],
            'id_consultorio' => $r['rooms'][$tipo === 'sala' ? 0 : 1], 'id_paciente' => $tipo === 'paciente' ? $r['patient'] : $otro,
            'inicio_at' => '2026-11-01T12:00:00Z', 'fin_at' => '2026-11-01T12:15:00Z']);
        $w1 = $this->worker($cookies[0], '/api/citas/'.$id.'/reprogramacion', $gestion, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker([], '', extra: ['mode' => 'reserva-sql', 'fila' => $fila]);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame(! $commit, $rb['ok']);
        if ($commit) {
            $this->assertSame('23P01', $rb['sqlstate']);
        }
        $this->assertSame($commit ? 1 : 2, DB::table('citas')->count());
        $this->assertSame($commit ? 1 : 0, DB::table('cambios_cita')->count());
    }

    #[DataProvider('sql')]
    public function test_sql_waits_until_cancellation_commits_before_reusing_original_interval(string $tipo, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $gestion = $this->datos($cookies[0], $id, 'cancelacion');
        $otro = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'SQL-CANCELACION', 'nombres' => 'SQL',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $fila = $this->citaRow($r, ['id_medico' => $r['doctors'][$tipo === 'medico' ? 0 : 1],
            'id_consultorio' => $r['rooms'][$tipo === 'sala' ? 0 : 1], 'id_paciente' => $tipo === 'paciente' ? $r['patient'] : $otro,
            'inicio_at' => '2026-11-01T12:30:00Z', 'fin_at' => '2026-11-01T12:45:00Z']);
        $w1 = $this->worker($cookies[0], '/api/citas/'.$id.'/cancelacion', $gestion, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker([], '', extra: ['mode' => 'reserva-sql', 'fila' => $fila]);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame('Confirmada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame($commit, $rb['ok']);
        if (! $commit) {
            $this->assertSame('23P01', $rb['sqlstate']);
        }
        $this->assertSame($commit ? 2 : 1, DB::table('citas')->count());
        $this->assertSame($commit ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame(1, DB::table('citas')->where('id_cita', $id)->value('version_programacion'));
    }

    public static function planificacionReal(): array
    {
        $casos = [];
        foreach (['duracion', 'horario'] as $tipo) {
            foreach ([true, false] as $gestionPrimero) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$tipo, $gestionPrimero, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('planificacionReal')]
    public function test_real_planning_endpoints_and_management_in_both_orders(string $tipo, bool $gestionPrimero, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $gestion = $this->datos($cookies[1], $id, 'reprogramacion');
        if ($tipo === 'duracion') {
            $version = $this->navegador($cookies[0], 'GET', '/api/configuracion-citas')->assertOk()->json('data.actualizado_en');
            $cambio = ['mode' => 'configuracion-citas', 'payload' => ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $version]];
        } else {
            $version = $this->navegador($cookies[0], 'GET', '/api/medicos/'.$r['doctors'][0].'/horarios')->assertOk()->json('data.actualizado_en');
            $cambio = ['mode' => 'horario-editar', 'id_objetivo' => $r['doctors'][0],
                'id_horario' => DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->value('id_horario'),
                'payload' => ['actualizado_en_original' => $version, 'dia_semana' => 7, 'hora_inicio' => '08:15:00', 'hora_fin' => '09:00:00']];
        }
        $start = fn (bool $esGestion, bool $pausa): int => $esGestion
            ? $this->worker($cookies[1], '/api/citas/'.$id.'/reprogramacion', $gestion, $pausa)
            : $this->worker($cookies[0], '', pause: $pausa, extra: $cambio);
        $w1 = $start($gestionPrimero, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $start(! $gestionPrimero, false);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($w1), true);
        $rb = json_decode($this->line($w2), true);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame($commit && (! $gestionPrimero || $tipo === 'horario') ? 409 : 200, $rb['status']);
        $gestionGana = $commit ? $gestionPrimero : ! $gestionPrimero;
        $this->assertSame($gestionGana ? 1 : 0, DB::table('cambios_cita')->count());
        if ($gestionGana) {
            // Un cambio de duración posterior no reescribe la duración histórica ya revisada/guardada.
            $this->assertSame(15, DB::table('citas')->where('id_cita', $id)->value('duracion_minutos'));
        }
    }
}
