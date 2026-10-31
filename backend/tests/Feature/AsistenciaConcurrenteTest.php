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

class AsistenciaConcurrenteTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $previousPath;

    private array $workers = [];

    private const EVENTOS = ['CITA_LLEGADA_CONFIRMADA', 'CITA_INASISTENCIA_REGISTRADA', 'CITA_ATENDIDA', 'CITA_CANCELADA', 'CITA_REPROGRAMADA'];

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
            // Exclusivamente el esquema aleatorio propiedad de este caso aislado.
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
                throw new RuntimeException('Falló el worker aislado de asistencia.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Venció la barrera del worker de asistencia.');
    }

    private function worker(array $cookies, string $id, string $accion, array $payload = [], bool $pause = false, array $extra = []): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso de asistencia.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(['schema' => $this->schema, 'mode' => 'gestion-http',
            'metodo' => $accion === 'asistencia' ? 'GET' : 'PATCH', 'ruta' => '/api/citas/'.$id.'/'.$accion,
            'cookies' => $cookies, 'payload' => $payload, 'pausar_planificacion' => $pause,
            'ip' => '192.0.2.73', 'ahora' => $this->instante($accion), ...$extra]));
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
            $block = DB::selectOne('SELECT wait_event_type, cardinality(pg_blocking_pids(pid)) AS blockers FROM pg_stat_activity WHERE pid = ?', [$this->workers[$worker]['pid']]);
            if ($block?->wait_event_type === 'Lock' && $block->blockers > 0) {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('La asistencia no esperó un lock real PostgreSQL con bloqueador identificable.');
    }

    private function preparar(): array
    {
        $r = $this->citaResources();
        $cookies = $actores = [];
        foreach (['Administrador', 'Recepcionista', 'Médico'] as $i => $rol) {
            $actores[$i] = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
            if ($rol === 'Médico') {
                DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actores[$i]->getKey()]);
            }
            $cookies[$i] = $this->csrfAuth();
            $this->loginAuth($cookies[$i], $actores[$i])->assertOk();
        }
        foreach ($r['doctors'] as $medico) {
            DB::table('horarios_atencion')->insert(['id_medico' => $medico, 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00']);
        }
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'actualizada_en' => '2026-11-01T10:00:00Z', 'motivo_breve' => 'CLINICA-PRIVADA-CONCURRENCIA']), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);

        return [$cookies, $r, $id, $actores];
    }

    private function instante(string $accion): string
    {
        return match ($accion) {
            'llegada', 'asistencia' => '2026-11-01T12:05:00Z',
            'inasistencia' => '2026-11-01T12:15:00Z',
            'atencion' => '2026-11-01T12:05:00Z',
            default => '2026-11-01T11:00:00Z',
        };
    }

    private function datos(array &$cookies, string $id, string $accion): array
    {
        if ($accion === 'asistencia') {
            return [];
        }
        if ($accion === 'reprogramacion') {
            $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->json('data');

            return ['version_programacion_original' => $vista['cita_original']['version_programacion'],
                'actualizada_en_original' => $vista['cita_original']['actualizada_en'], 'fecha' => '2026-11-01',
                'hora_inicio' => $vista['turnos'][0]['hora_inicio'], 'revision_turno' => $vista['turnos'][0]['revision_turno']];
        }
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia')->assertOk()->json('data');
        $declaracion = match ($accion) {
            'llegada' => ['presencia_verificada' => true],
            'inasistencia' => ['ausencia_verificada' => true],
            'atencion' => ['atencion_realizada' => true],
            default => ['motivo' => 'MOTIVO-PRIVADO-GESTION'],
        };

        return ['version_programacion_original' => $vista['version_programacion'],
            'actualizada_en_original' => $vista['actualizada_en'], ...$declaracion];
    }

    private function resultado(int $worker): array
    {
        $resultado = json_decode($this->line($worker), true, 512, JSON_THROW_ON_ERROR);
        $texto = json_encode($resultado);
        foreach (['CLINICA-PRIVADA-CONCURRENCIA', 'MOTIVO-PRIVADO-GESTION', 'password_hash', 'SQLSTATE', 'stack_trace'] as $privado) {
            $this->assertStringNotContainsString($privado, $texto);
        }

        return $resultado;
    }

    private function verificarGanadora(string $id, string $accion, string $actor): void
    {
        $estado = match ($accion) {
            'llegada' => 'Confirmada', 'inasistencia' => 'Inasistencia', 'atencion' => 'Atendida',
            'cancelacion' => 'Cancelada', default => 'Programada',
        };
        $fila = DB::table('citas')->where('id_cita', $id)->sole();
        $this->assertSame($estado, $fila->estado);
        $this->assertSame($accion === 'reprogramacion' ? 2 : 1, $fila->version_programacion);
        $this->assertSame(1, DB::table('cambios_cita')->count());
        $this->assertSame($actor, (string) DB::table('cambios_cita')->sole()->id_actor);
        $eventos = DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->get();
        $this->assertCount(1, $eventos);
        $this->assertSame($actor, (string) $eventos[0]->id_usuario_responsable);
        $this->assertSame($accion === 'llegada', DB::table('recordatorios')->value('invalidado_en') === null);
        $this->assertSame(0, DB::table('consultas_medicas')->count());
        $this->assertSame(0, DB::table('versiones_consulta')->count());
        if ($accion !== 'reprogramacion') {
            $this->assertTrue(CarbonImmutable::parse($fila->inicio_at)->equalTo('2026-11-01T12:00:00Z'));
            $this->assertTrue(CarbonImmutable::parse($fila->fin_at)->equalTo('2026-11-01T12:15:00Z'));
        }
    }

    public static function carreras(): array
    {
        $casos = [];
        foreach (['llegada', 'inasistencia', 'atencion'] as $a) {
            foreach (['llegada', 'inasistencia', 'atencion', 'cancelacion', 'reprogramacion'] as $b) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$a, $b, $commit];
                    if (in_array($b, ['cancelacion', 'reprogramacion'], true)) {
                        $casos[] = [$b, $a, $commit];
                    }
                }
            }
        }

        return $casos;
    }

    #[DataProvider('carreras')]
    public function test_two_writers_commit_or_rollback_without_partial_or_duplicate_effects(string $a, string $b, bool $commit): void
    {
        [$cookies, $r, $id, $actores] = $this->preparar();
        // Relojes por participante para los cortes incompatibles (no tolerancias nuevas).
        $i = $a === 'atencion' ? 2 : 1;
        $j = 0;
        $pa = $this->datos($cookies[$i], $id, $a);
        $pb = $this->datos($cookies[$j], $id, $b);
        $original = (array) DB::table('citas')->where('id_cita', $id)->sole();
        $w1 = $this->worker($cookies[$i], $id, $a, $pa, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($cookies[$j], $id, $b, $pb);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame('Programada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->assertSame(0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        $this->assertNull(DB::table('recordatorios')->value('invalidado_en'));
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = $this->resultado($w1);
        $rb = $this->resultado($w2);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $this->assertSame($commit ? 409 : 200, $rb['status']);
        if ($commit) {
            $this->assertSame('CITA_EDICION_OBSOLETA', $rb['body']['error']['code']);
        }
        $this->verificarGanadora($id, $commit ? $a : $b, (string) $actores[$commit ? $i : $j]->getKey());
        $final = (array) DB::table('citas')->where('id_cita', $id)->sole();
        $ganadora = $commit ? $a : $b;
        foreach ($original as $campo => $valor) {
            $permitidos = $ganadora === 'reprogramacion'
                ? ['estado', 'actualizada_en', 'inicio_at', 'fin_at', 'duracion_minutos', 'id_consultorio', 'sede_atencion', 'consultorio_atencion', 'version_programacion']
                : ['estado', 'actualizada_en'];
            if (! in_array($campo, $permitidos, true)) {
                $this->assertSame($valor, $final[$campo], $campo);
            }
        }
        $this->assertTrue(CarbonImmutable::parse($final['actualizada_en'])->greaterThan(CarbonImmutable::parse($original['actualizada_en'])));
        $evento = DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->sole();
        $this->assertSame((string) $r['doctors'][0], json_decode($evento->referencias)->id_medico);
        // Reconciliación explícita: observa el estado confirmado, no reenvía la escritura incierta.
        $this->navegador($cookies[0], 'GET', '/api/citas/'.$id.'/asistencia')->assertOk()
            ->assertJsonPath('data.estado', DB::table('citas')->where('id_cita', $id)->value('estado'))
            ->assertDontSee('CLINICA-PRIVADA-CONCURRENCIA');
        $this->assertSame(1, DB::table('cambios_cita')->count());
    }

    public static function revocaciones(): array
    {
        $casos = [];
        foreach (['asistencia', 'llegada', 'inasistencia', 'atencion'] as $ruta) {
            $operadores = match ($ruta) {
                'asistencia' => [0, 1, 2], 'atencion' => [0, 2], default => [0, 1]
            };
            foreach ($operadores as $i) {
                foreach (['estado', 'rol', 'sesion', 'clave', ...($i === 2 ? ['vinculo', 'dual'] : [])] as $cambio) {
                    foreach ([true, false] as $commit) {
                        $casos[] = [$ruta, $i, $cambio, $commit];
                    }
                }
            }
        }

        return $casos;
    }

    #[DataProvider('revocaciones')]
    public function test_revocation_after_middleware_is_revalidated_after_a_real_lock(string $ruta, int $i, string $cambio, bool $commit): void
    {
        [$cookies, $r, $id, $actores] = $this->preparar();
        $payload = $this->datos($cookies[$i], $id, $ruta);
        $w = $this->worker($cookies[$i], $id, $ruta, $payload, extra: ['pausar_antes' => true]);
        $this->signal($w, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($w));
        DB::beginTransaction();
        $actor = $actores[$i]->getKey();
        DB::table('usuarios')->where('id_usuario', $actor)->lockForUpdate()->first();
        $this->signal($w, 'CONTINUAR');
        $this->blocked($w);
        match ($cambio) {
            'estado' => DB::table('usuarios')->where('id_usuario', $actor)->update(['estado_cuenta' => 'Desactivada']),
            'rol' => DB::table('usuarios')->where('id_usuario', $actor)->update(['id_rol' => $actores[$i === 0 ? 1 : 0]->id_rol]),
            'sesion' => DB::table('sessions')->where('user_id', $actor)->delete(),
            'clave' => DB::table('usuarios')->where('id_usuario', $actor)->update(['password_hash' => Hash::make('Frase ficticia nueva segura 123!')]),
            'vinculo' => DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => null]),
            'dual' => DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actor]),
        };
        $commit ? DB::commit() : DB::rollBack();
        $resultado = $this->resultado($w);
        $this->assertSame($commit ? ($cambio === 'dual' ? 403 : 401) : 200, $resultado['status']);
        if ($commit) {
            $this->assertArrayNotHasKey('data', $resultado['body']);
        }
        $escribe = ! $commit && $ruta !== 'asistencia';
        $this->assertSame($escribe ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($escribe ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        $this->assertSame(! $escribe || $ruta === 'llegada', DB::table('recordatorios')->value('invalidado_en') === null);
    }

    public static function consultasConcurrentes(): array
    {
        $casos = [];
        foreach (['asistencia', 'llegada', 'inasistencia', 'atencion'] as $ruta) {
            foreach ([null, '2026-11-01T12:00:00Z'] as $inicio) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$ruta, $inicio, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('consultasConcurrentes')]
    public function test_clinical_protocol_commit_or_rollback_is_seen_after_wait(string $ruta, ?string $inicio, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $datos = $this->datos($cookies[0], $id, $ruta);
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        // Protocolo CU-23 simulado: no endpoint clínico ni garantía ante SQL ajeno al protocolo.
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->lockForUpdate()->first();
        DB::table('citas')->where('id_cita', $id)->lockForUpdate()->first();
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-PROTOCOLO-FICTICIO', 'iniciada_en' => $inicio]);
        $w = $this->worker($cookies[0], $id, $ruta, $datos);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $commit ? DB::commit() : DB::rollBack();
        $res = $this->resultado($w);
        $rechazo = $commit && in_array($ruta, ['llegada', 'inasistencia'], true);
        $this->assertSame($rechazo ? 409 : 200, $res['status']);
        if ($rechazo) {
            $this->assertSame('CITA_CON_ATENCION_DOCUMENTADA', $res['body']['error']['code']);
        }
        if ($ruta === 'asistencia' && $commit) {
            $this->assertContains('CITA_CON_ATENCION_DOCUMENTADA', $res['body']['data']['impedimentos']['confirmar_llegada']);
            $this->assertContains('CITA_CON_ATENCION_DOCUMENTADA', $res['body']['data']['impedimentos']['registrar_inasistencia']);
        }
        $escribe = ! $rechazo && $ruta !== 'asistencia';
        $this->assertSame($commit ? 1 : 0, DB::table('consultas_medicas')->count());
        if ($commit) {
            $consulta = DB::table('consultas_medicas')->sole();
            $this->assertSame($id, (string) $consulta->id_cita);
            $this->assertSame($inicio === null, $consulta->iniciada_en === null);
        }
        $this->assertSame(0, DB::table('versiones_consulta')->count());
        $this->assertSame($escribe ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($escribe ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        $this->assertSame(! $escribe || $ruta === 'llegada', DB::table('recordatorios')->value('invalidado_en') === null);
    }

    public static function cortes(): array
    {
        return [
            ['llegada', '2026-11-01T12:14:59.999999Z', '2026-11-01T12:15:00Z', 409, 'CITA_LLEGADA_FUERA_DE_PLAZO'],
            ['llegada', '2026-11-01T03:59:59.999999Z', '2026-11-01T04:00:00Z', 200, null],
            ['inasistencia', '2026-11-01T12:14:59.999999Z', '2026-11-01T12:15:00Z', 200, null],
            ['inasistencia', '2026-11-01T12:14:59Z', '2026-11-01T12:14:59.999999Z', 409, 'CITA_INASISTENCIA_ANTICIPADA'],
            ['atencion', '2026-11-01T11:59:59.999999Z', '2026-11-01T12:00:00Z', 200, null],
            ['atencion', '2026-11-01T11:59:59Z', '2026-11-01T11:59:59.999999Z', 409, 'CITA_ATENCION_ANTICIPADA'],
        ];
    }

    #[DataProvider('cortes')]
    public function test_fresh_clock_after_real_wait_uses_exact_cut_without_tolerance(string $accion, string $antes, string $despues, int $status, ?string $codigo): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $datos = $this->datos($cookies[0], $id, $accion);
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        $w = $this->worker($cookies[0], $id, $accion, $datos,
            extra: ['ahora' => $antes, 'ahora_tras_planificacion' => $despues]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        DB::commit();
        $res = $this->resultado($w);
        $this->assertSame($status, $res['status']);
        if ($codigo !== null) {
            $this->assertSame($codigo, $res['body']['error']['code']);
        }
        $this->assertSame($status === 200 ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($status === 200 ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
    }

    public static function cruceInicio(): array
    {
        $casos = [];
        foreach (['cancelacion', 'reprogramacion'] as $gestion) {
            foreach ([true, false] as $gestionPrimero) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$gestion, $gestionPrimero, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('cruceInicio')]
    public function test_management_and_attention_cross_start_with_monotonic_clocks(string $gestion, bool $gestionPrimero, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $a = $gestionPrimero ? $gestion : 'atencion';
        $b = $gestionPrimero ? 'atencion' : $gestion;
        $pa = $this->datos($cookies[0], $id, $a);
        $pb = $this->datos($cookies[0], $id, $b);
        $w1 = $this->worker($cookies[0], $id, $a, $pa, true,
            ['ahora' => $gestionPrimero ? '2026-11-01T11:59:59.999999Z' : '2026-11-01T12:00:00Z']);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($cookies[$gestionPrimero ? 2 : 1], $id, $b, $pb,
            extra: ['ahora' => '2026-11-01T12:00:00Z']);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = $this->resultado($w1);
        $rb = $this->resultado($w2);
        $this->assertSame($commit ? 200 : 500, $ra['status']);
        $esperado = $commit || ! $gestionPrimero ? 409 : 200;
        $this->assertSame($esperado, $rb['status']);
        if ($esperado === 409) {
            $this->assertSame($commit ? 'CITA_EDICION_OBSOLETA' : 'CITA_PLAZO_GESTION_FINALIZADO', $rb['body']['error']['code']);
        }
        $this->assertSame($commit || $gestionPrimero ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($commit || $gestionPrimero ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
    }

    public static function alcanceMedico(): array
    {
        $casos = [];
        foreach (['asistencia', 'atencion'] as $ruta) {
            foreach (['ajena', 'inexistente', 'reasignada'] as $tipo) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$ruta, $tipo, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('alcanceMedico')]
    public function test_medical_scope_is_applied_after_a_real_planning_wait(string $ruta, string $tipo, bool $commit): void
    {
        [$cookies, $r, $id] = $this->preparar();
        $payload = $this->datos($cookies[2], $id, $ruta);
        if ($tipo === 'ajena') {
            DB::table('citas')->where('id_cita', $id)->update(['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]);
        }
        $objetivo = $tipo === 'inexistente' ? '9223372036854775807' : $id;
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        if ($tipo === 'reasignada') {
            // Cambio concurrente simulado bajo protocolo; no nueva API de reasignación clínica.
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->lockForUpdate()->first();
            DB::table('citas')->where('id_cita', $id)->update(['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]);
        }
        $w = $this->worker($cookies[2], $objetivo, $ruta, $payload);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $commit ? DB::commit() : DB::rollBack();
        $res = $this->resultado($w);
        $rechazo = $tipo !== 'reasignada' || $commit;
        $this->assertSame($rechazo ? 404 : 200, $res['status']);
        if ($rechazo) {
            $this->assertSame('RECURSO_NO_ENCONTRADO', $res['body']['error']['code']);
            $this->assertArrayNotHasKey('data', $res['body']);
        }
        $this->assertSame(! $rechazo && $ruta === 'atencion' ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame(! $rechazo && $ruta === 'atencion' ? 1 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
    }
}
