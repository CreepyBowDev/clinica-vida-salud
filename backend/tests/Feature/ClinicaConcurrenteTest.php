<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ClinicaConcurrenteTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $previousPath;

    private array $workers = [];

    private const EVENTOS = ['CONSULTA_REGISTRADA', 'CONSULTA_ACTUALIZADA', 'ANTECEDENTES_ACTUALIZADOS'];

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
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
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
            // Solo el esquema aleatorio creado y propiedad de este caso.
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
                throw new RuntimeException('Falló el worker clínico aislado.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Venció la barrera del worker clínico.');
    }

    private function worker(array $cookies, string $metodo, string $ruta, array $payload = [], bool $pausa = false, array $extra = []): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso clínico.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(['schema' => $this->schema, 'mode' => 'gestion-http',
            'metodo' => $metodo, 'ruta' => $ruta, 'payload' => $payload, 'cookies' => $cookies,
            'pausar_planificacion' => $pausa, 'ip' => '192.0.2.74', 'ahora' => '2026-11-01T12:05:00Z', ...$extra]));
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
            $row = DB::selectOne('SELECT wait_event_type, cardinality(pg_blocking_pids(pid)) AS blockers FROM pg_stat_activity WHERE pid = ?', [$this->workers[$worker]['pid']]);
            if ($row?->wait_event_type === 'Lock' && $row->blockers > 0) {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('No se observó espera de lock PostgreSQL con bloqueador identificable.');
    }

    private function resultado(int $worker): array
    {
        $r = json_decode($this->line($worker), true, 512, JSON_THROW_ON_ERROR);
        foreach (['password_hash', 'SQLSTATE', 'stack_trace', 'ruta_privada'] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($r));
        }
        if ($r['status'] >= 400) {
            $this->assertArrayNotHasKey('data', $r['body']);
            $this->assertStringNotContainsString('CLINICA-', json_encode($r['body']));
        }

        return $r;
    }

    private function preparar(): array
    {
        $r = $this->citaResources();
        $cookies = $actores = [];
        foreach (['Administrador', 'Médico', 'Paciente', 'Recepcionista'] as $i => $rol) {
            $actores[$i] = $this->usuarioAuth($rol, vincular: false);
            if ($rol === 'Médico') {
                DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actores[$i]->getKey()]);
            } elseif ($rol === 'Paciente') {
                DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actores[$i]->getKey()]);
            }
            $cookies[$i] = $this->csrfAuth();
            $this->loginAuth($cookies[$i], $actores[$i])->assertOk();
        }
        foreach ($r['doctors'] as $medico) {
            DB::table('horarios_atencion')->insert(['id_medico' => $medico, 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00']);
        }
        $ids = [];
        foreach ([0, 1] as $i) {
            $ids[$i] = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][$i],
                'id_consultorio' => $r['rooms'][$i], 'actualizada_en' => '2026-11-01T10:00:00Z',
                'inicio_at' => $i === 0 ? '2026-11-01T12:00:00Z' : '2026-11-01T12:15:00Z',
                'fin_at' => $i === 0 ? '2026-11-01T12:15:00Z' : '2026-11-01T12:30:00Z']), 'id_cita');
            DB::table('recordatorios')->insert(['id_cita' => $ids[$i], 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => now()]);
        }

        return compact('r', 'cookies', 'actores', 'ids');
    }

    private function ruta(array $c, string $vista, int $cita = 0): string
    {
        return match ($vista) {
            'historia' => '/api/pacientes/'.$c['r']['patient'].'/historia-clinica',
            'antecedentes' => '/api/pacientes/'.$c['r']['patient'].'/historia-clinica/antecedentes/versiones',
            'versiones' => '/api/citas/'.$c['ids'][$cita].'/consulta/versiones',
            default => '/api/citas/'.$c['ids'][$cita].'/consulta',
        };
    }

    private function datos(array &$c, string $metodo, string $marca, int $cita = 0, bool $soloAntecedentes = false): array
    {
        $originales = $this->navegador($c['cookies'][0], 'GET', $this->ruta($c, 'consulta', $cita))->assertOk()->json('data.edicion.originales');
        $datos = ['originales' => $originales, 'atencion_real_verificada' => true,
            'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'CLINICA-'.$marca, 'diagnostico' => 'CLINICA-'.$marca],
            'antecedentes' => ['alergias' => 'CLINICA-'.$marca]];
        if ($metodo === 'PATCH') {
            $datos['motivo_cambio'] = 'CLINICA-MOTIVO-'.$marca;
        }
        if ($soloAntecedentes) {
            unset($datos['consulta']);
        }

        return $datos;
    }

    private function sembrar(array &$c, int $cita = 0): void
    {
        // Preparación mediante alta real de Fase 3; reloj tardío solo para permitir ambas citas de fixture.
        CarbonImmutable::setTestNow($cita === 1 ? '2026-11-01T12:30:00Z' : '2026-11-01T12:00:00Z');
        $datos = $this->datos($c, 'POST', 'INICIAL', $cita);
        if ($cita === 1) {
            unset($datos['antecedentes']);
        }
        $this->navegador($c['cookies'][0], 'POST', $this->ruta($c, 'consulta', $cita), $datos)->assertCreated();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    }

    private function snapshot(array $tablas = []): array
    {
        $s = [];
        foreach ($tablas ?: ['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios', 'bitacora'] as $tabla) {
            $s[$tabla] = DB::table($tabla)->orderByRaw('1')->get()->toJson();
        }

        return $s;
    }

    private function auditoriaSegura(): void
    {
        $eventos = DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->get();
        $this->assertStringNotContainsString('CLINICA-', $eventos->toJson());
        foreach ($eventos as $evento) {
            $this->assertNotNull($evento->id_usuario_responsable);
            $this->assertSame('EXITO', $evento->resultado);
        }
    }

    public static function escritores(): array
    {
        $casos = [];
        foreach (['alta', 'correccion', 'noop', 'antecedentes_otra', 'alta_otra'] as $tipo) {
            foreach ([true, false] as $commit) {
                foreach ([false, true] as $invertir) {
                    $casos[] = [$tipo, $commit, $invertir];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('escritores')]
    public function test_clinical_writers_serialize_without_duplicate_consultations_or_lost_versions(string $tipo, bool $commit, bool $invertir): void
    {
        $c = $this->preparar();
        $otra = in_array($tipo, ['antecedentes_otra', 'alta_otra'], true);
        $metodo = str_starts_with($tipo, 'alta') ? 'POST' : 'PATCH';
        if ($metodo === 'PATCH') {
            $this->sembrar($c);
            if ($otra) {
                $this->sembrar($c, 1);
            }
        }
        $a = $otra && $invertir ? 1 : 0;
        $b = $otra ? 1 - $a : 0;
        $i = ! $otra && $invertir ? 1 : 0;
        $j = ! $otra && ! $invertir ? 1 : 0;
        $pa = $this->datos($c, $metodo, 'A', $a, $tipo === 'antecedentes_otra');
        $pb = $this->datos($c, $metodo, 'B', $b, $tipo === 'antecedentes_otra');
        if ($tipo === 'noop') {
            unset($pb['consulta'], $pb['antecedentes'], $pb['motivo_cambio']);
        }
        $antes = $this->snapshot();
        $versionesAnteriores = $this->snapshot(['versiones_consulta', 'versiones_antecedente']);
        $w1 = $this->worker($c['cookies'][$i], $metodo, $this->ruta($c, 'consulta', $a), $pa, true, ['ahora' => '2026-11-01T12:30:00Z']);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($c['cookies'][$j], $metodo, $this->ruta($c, 'consulta', $b), $pb, extra: ['ahora' => '2026-11-01T12:30:00Z']);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame($antes, $this->snapshot());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = $this->resultado($w1);
        $rb = $this->resultado($w2);
        $exito = $metodo === 'POST' ? 201 : 200;
        $this->assertSame($commit ? $exito : 500, $ra['status']);
        $this->assertSame($commit ? 409 : $exito, $rb['status']);
        if ($commit) {
            $this->assertSame('CLINICA_EDICION_OBSOLETA', $rb['body']['error']['code']);
        }
        $noOp = ! $commit && $tipo === 'noop';
        if ($noOp) {
            $this->assertFalse($rb['body']['data']['modificado']);
            $this->assertSame($antes, $this->snapshot());
        } else {
            $numero = $metodo === 'POST' ? 1 : 2;
            $this->assertSame($numero, DB::table('versiones_antecedente')->max('numero_version'));
            $this->assertSame('CLINICA-'.($commit ? 'A' : 'B'), DB::table('versiones_antecedente')->orderByDesc('numero_version')->value('alergias'));
            $this->assertSame($metodo === 'POST' ? 1 : ($otra ? 2 : 1), DB::table('consultas_medicas')->count());
            $this->assertSame($tipo === 'antecedentes_otra' ? 1 : $numero, DB::table('versiones_consulta')->max('numero_version'));
            $this->assertSame($metodo === 'POST' ? 2 : 4, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
            $ultima = DB::table('versiones_antecedente')->orderByDesc('numero_version')->first();
            $this->assertSame((string) $c['actores'][$commit ? $i : $j]->getKey(), (string) $ultima->id_autor);
            $this->assertSame($c['ids'][$commit ? $a : $b], (string) DB::table('consultas_medicas')->where('id_consulta', $ultima->id_consulta_origen)->value('id_cita'));
        }
        foreach (['citas', 'cambios_cita', 'recordatorios'] as $tabla) {
            $this->assertSame($antes[$tabla], $this->snapshot([$tabla])[$tabla]);
        }
        if ($metodo === 'PATCH') {
            foreach ($versionesAnteriores as $tabla => $json) {
                $this->assertSame($json, DB::table($tabla)->where('numero_version', 1)->orderByRaw('1')->get()->toJson());
            }
        }
        $this->auditoriaSegura();
    }

    public static function lecturas(): array
    {
        $casos = [];
        foreach (['historia', 'consulta', 'versiones', 'antecedentes'] as $vista) {
            foreach (in_array($vista, ['historia', 'consulta'], true) ? [0, 1, 2] : [0, 1] as $actor) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$vista, $actor, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('lecturas')]
    public function test_clinical_read_waits_for_commit_or_rollback_and_keeps_role_projection(string $vista, int $actor, bool $commit): void
    {
        $c = $this->preparar();
        $this->sembrar($c);
        $payload = $this->datos($c, 'PATCH', 'A');
        $antes = $this->snapshot();
        $w1 = $this->worker($c['cookies'][0], 'PATCH', $this->ruta($c, 'consulta'), $payload, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($c['cookies'][$actor], 'GET', $this->ruta($c, $vista));
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame($antes, $this->snapshot());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $this->assertSame($commit ? 200 : 500, $this->resultado($w1)['status']);
        $r = $this->resultado($w2);
        $this->assertSame(200, $r['status']);
        $data = $r['body']['data'];
        $numero = $commit ? 2 : 1;
        $this->assertSame($numero, match ($vista) {
            'historia' => $data['antecedentes']['numero_version'],
            'consulta' => $data['consulta']['version_vigente']['numero_version'],
            default => $data[0]['numero_version'],
        });
        if ($actor === 2) {
            foreach (['id_autor', 'motivo_cambio', 'edicion', 'CLINICA-MOTIVO'] as $privado) {
                $this->assertStringNotContainsString($privado, json_encode($data));
            }
        }
        $this->assertSame($commit ? 2 : 1, DB::table('versiones_consulta')->count());
        $this->assertSame($commit ? 4 : 2, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        $this->auditoriaSegura();
    }

    public static function accesos(): array
    {
        $casos = [];
        foreach (['historia', 'consulta', 'versiones', 'antecedentes', 'POST', 'PATCH'] as $vista) {
            $roles = in_array($vista, ['historia', 'consulta'], true) ? [0, 1, 2] : [0, 1];
            foreach ($roles as $actor) {
                $casos[] = [$vista, $actor];
            }
        }

        return $casos;
    }

    public static function revocaciones(): array
    {
        $casos = [];
        foreach (self::accesos() as [$vista, $actor]) {
            foreach (['estado', 'rol', 'sesion', 'clave', ...($actor === 1 ? ['vinculo', 'dual'] : ($actor === 2 ? ['vinculo'] : []))] as $cambio) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$vista, $actor, $cambio, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('revocaciones')]
    public function test_revocation_after_middleware_is_revalidated_after_real_account_lock(string $vista, int $actor, string $cambio, bool $commit): void
    {
        $c = $this->preparar();
        if ($vista !== 'POST') {
            $this->sembrar($c);
        }
        $escritura = in_array($vista, ['POST', 'PATCH'], true);
        $metodo = $escritura ? $vista : 'GET';
        $payload = $escritura ? $this->datos($c, $metodo, 'B') : [];
        $ruta = $this->ruta($c, $escritura ? 'consulta' : $vista);
        $antes = $this->snapshot();
        $w = $this->worker($c['cookies'][$actor], $metodo, $ruta, $payload, extra: ['pausar_antes' => true]);
        $this->signal($w, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($w));
        DB::beginTransaction();
        $usuario = $c['actores'][$actor]->getKey();
        DB::table('usuarios')->where('id_usuario', $usuario)->lockForUpdate()->first();
        $this->signal($w, 'CONTINUAR');
        $this->blocked($w);
        match ($cambio) {
            'estado' => DB::table('usuarios')->where('id_usuario', $usuario)->update(['estado_cuenta' => 'Desactivada']),
            'rol' => DB::table('usuarios')->where('id_usuario', $usuario)->update(['id_rol' => $c['actores'][3]->id_rol]),
            'sesion' => DB::table('sessions')->where('user_id', $usuario)->delete(),
            'clave' => DB::table('usuarios')->where('id_usuario', $usuario)->update(['password_hash' => Hash::make('Frase ficticia nueva segura 123!')]),
            'vinculo' => $actor === 1 ? DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['id_usuario' => null])
                : DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['id_usuario' => null]),
            'dual' => DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['id_usuario' => $usuario]),
        };
        $commit ? DB::commit() : DB::rollBack();
        $res = $this->resultado($w);
        $this->assertSame($commit ? ($cambio === 'dual' ? 403 : 401) : ($vista === 'POST' ? 201 : 200), $res['status']);
        if ($commit || ! $escritura) {
            $this->assertSame($antes, $this->snapshot());
        } else {
            $this->assertSame($vista === 'POST' ? 1 : 2, DB::table('versiones_consulta')->count());
            $this->assertSame($vista === 'POST' ? 2 : 4, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        }
        $this->auditoriaSegura();
    }

    public static function alcance(): array
    {
        $casos = [];
        foreach (['historia', 'consulta', 'versiones', 'antecedentes', 'POST', 'PATCH'] as $vista) {
            foreach ([true, false] as $commit) {
                $casos[] = [$vista, $commit];
            }
        }

        return $casos;
    }

    #[DataProvider('alcance')]
    public function test_medical_scope_is_rechecked_after_real_planning_wait(string $vista, bool $commit): void
    {
        $c = $this->preparar();
        if ($vista !== 'POST') {
            $this->sembrar($c);
        }
        $escritura = in_array($vista, ['POST', 'PATCH'], true);
        $payload = $escritura ? $this->datos($c, $vista, 'B') : [];
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        // Simulación de cambio de relación bajo protocolo, no API nueva de reasignación.
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->lockForUpdate()->first();
        DB::table('citas')->where('id_cita', $c['ids'][0])->update(['id_medico' => $c['r']['doctors'][1]]);
        $w = $this->worker($c['cookies'][1], $escritura ? $vista : 'GET', $this->ruta($c, $escritura ? 'consulta' : $vista), $payload);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $commit ? DB::commit() : DB::rollBack();
        $res = $this->resultado($w);
        $this->assertSame($commit ? 404 : ($vista === 'POST' ? 201 : 200), $res['status']);
        $this->assertSame(! $commit && $vista === 'POST' ? 1 : ($vista === 'POST' ? 0 : 1), DB::table('consultas_medicas')->count());
        $this->auditoriaSegura();
    }

    public static function cortes(): array
    {
        return [['POST', '2026-11-01T11:59:59.999999Z', '2026-11-01T12:00:00Z', 201],
            ['POST', '2026-11-01T11:59:59Z', '2026-11-01T11:59:59.999999Z', 409],
            ['PATCH', '2026-11-01T11:59:59.999999Z', '2026-11-01T12:00:00Z', 200],
            ['PATCH', '2026-11-01T11:59:59Z', '2026-11-01T11:59:59.999999Z', 409],
            ['noop', '2026-11-01T11:59:59Z', '2026-11-01T11:59:59.999999Z', 409],
            ['noop', '2026-11-01T11:59:59.999999Z', '2026-11-01T12:00:00Z', 200]];
    }

    #[DataProvider('cortes')]
    public function test_server_clock_is_fresh_after_real_planning_lock_including_noop(string $accion, string $antes, string $despues, int $status): void
    {
        $c = $this->preparar();
        $metodo = $accion === 'POST' ? 'POST' : 'PATCH';
        if ($metodo === 'PATCH') {
            $this->sembrar($c);
        }
        $payload = $this->datos($c, $metodo, 'B');
        if ($accion === 'noop') {
            unset($payload['consulta'], $payload['antecedentes'], $payload['motivo_cambio']);
        }
        $snapshot = $this->snapshot();
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        $w = $this->worker($c['cookies'][0], $metodo, $this->ruta($c, 'consulta'), $payload,
            extra: ['ahora' => $antes, 'ahora_tras_planificacion' => $despues]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        DB::commit();
        $res = $this->resultado($w);
        $this->assertSame($status, $res['status']);
        if ($status === 409) {
            $this->assertSame('CLINICA_ATENCION_ANTICIPADA', $res['body']['error']['code']);
        }
        if ($accion === 'noop' || $status === 409) {
            $this->assertSame($snapshot, $this->snapshot());
        }
    }

    private function datosGestion(array &$c, string $accion): array
    {
        $id = $c['ids'][0];
        if ($accion === 'reprogramacion') {
            CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
            $vista = $this->navegador($c['cookies'][0], 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')->assertOk()->json('data');
            CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
            $this->assertNotEmpty($vista['turnos']);

            return ['version_programacion_original' => $vista['cita_original']['version_programacion'],
                'actualizada_en_original' => $vista['cita_original']['actualizada_en'], 'fecha' => '2026-11-01',
                'hora_inicio' => $vista['turnos'][0]['hora_inicio'], 'revision_turno' => $vista['turnos'][0]['revision_turno']];
        }
        $vista = $this->navegador($c['cookies'][0], 'GET', '/api/citas/'.$id.'/asistencia')->assertOk()->json('data');
        $declaracion = match ($accion) {
            'llegada' => ['presencia_verificada' => true], 'inasistencia' => ['ausencia_verificada' => true],
            'atencion' => ['atencion_realizada' => true], default => ['motivo' => 'GESTION-FICTICIA'],
        };

        return ['version_programacion_original' => $vista['version_programacion'],
            'actualizada_en_original' => $vista['actualizada_en'], ...$declaracion];
    }

    public static function gestion(): array
    {
        $casos = [];
        foreach (['llegada', 'inasistencia', 'atencion', 'cancelacion', 'reprogramacion'] as $accion) {
            foreach ([true, false] as $gestionPrimero) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$accion, $gestionPrimero, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('gestion')]
    public function test_real_clinical_registration_and_appointment_management_share_atomic_protocol(string $accion, bool $gestionPrimero, bool $commit): void
    {
        $c = $this->preparar();
        $clinica = $this->datos($c, 'POST', 'A');
        $gestion = $this->datosGestion($c, $accion);
        $rutaGestion = '/api/citas/'.$c['ids'][0].'/'.$accion;
        $horaGestion = match ($accion) {
            'cancelacion', 'reprogramacion' => $gestionPrimero ? '2026-11-01T11:59:59.999999Z' : '2026-11-01T12:00:00Z',
            'inasistencia' => '2026-11-01T12:15:00Z', default => '2026-11-01T12:00:00Z',
        };
        // Si ausencia va primero, la alta documental posterior también usa hora >= fin; no reloj hacia atrás.
        $horaClinica = $gestionPrimero && $accion === 'inasistencia' ? '2026-11-01T12:15:00Z' : '2026-11-01T12:00:00Z';
        $antes = $this->snapshot();
        $w1 = $this->worker($c['cookies'][0], $gestionPrimero ? 'PATCH' : 'POST',
            $gestionPrimero ? $rutaGestion : $this->ruta($c, 'consulta'), $gestionPrimero ? $gestion : $clinica,
            true, ['ahora' => $gestionPrimero ? $horaGestion : $horaClinica]);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($c['cookies'][$gestionPrimero ? 1 : 0], $gestionPrimero ? 'POST' : 'PATCH',
            $gestionPrimero ? $this->ruta($c, 'consulta') : $rutaGestion, $gestionPrimero ? $clinica : $gestion,
            extra: ['ahora' => $gestionPrimero ? $horaClinica : $horaGestion]);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame($antes, $this->snapshot());
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = $this->resultado($w1);
        $rb = $this->resultado($w2);
        $this->assertSame($commit ? ($gestionPrimero ? 200 : 201) : 500, $ra['status']);
        $esperadoB = $gestionPrimero ? ($commit ? 409 : 201)
            : ($accion === 'atencion' || (! $commit && in_array($accion, ['llegada', 'inasistencia'], true)) ? 200 : 409);
        $this->assertSame($esperadoB, $rb['status']);
        $consulta = $gestionPrimero ? ! $commit : $commit;
        $cambio = $gestionPrimero ? $commit : $esperadoB === 200;
        $this->assertSame($consulta ? 1 : 0, DB::table('consultas_medicas')->count());
        $this->assertSame($consulta ? 1 : 0, DB::table('versiones_consulta')->count());
        $this->assertSame($consulta ? 1 : 0, DB::table('versiones_antecedente')->count());
        $this->assertSame($consulta ? 2 : 0, DB::table('bitacora')->whereIn('tipo_evento', self::EVENTOS)->count());
        $this->assertSame($cambio ? 1 : 0, DB::table('cambios_cita')->count());
        $fila = DB::table('citas')->where('id_cita', $c['ids'][0])->sole();
        $this->assertSame((string) $c['r']['patient'], (string) $fila->id_paciente);
        $this->assertSame((string) $c['r']['doctors'][0], (string) $fila->id_medico);
        $this->assertSame($cambio && $accion === 'reprogramacion' ? 2 : 1, $fila->version_programacion);
        $this->assertSame(! $cambio || $accion === 'llegada', DB::table('recordatorios')->where('id_cita', $c['ids'][0])->value('invalidado_en') === null);
        $this->assertNull(DB::table('recordatorios')->where('id_cita', $c['ids'][1])->value('invalidado_en'));
        if (! $cambio) {
            foreach (['citas', 'cambios_cita', 'recordatorios'] as $tabla) {
                $this->assertSame($antes[$tabla], $this->snapshot([$tabla])[$tabla]);
            }
        }
        if ($consulta) {
            $this->assertNull(DB::table('consultas_medicas')->value('iniciada_en'));
        }
        $this->auditoriaSegura();
    }

    public static function planificacion(): array
    {
        $casos = [];
        foreach (['POST', 'PATCH'] as $metodo) {
            foreach ([true, false] as $planPrimero) {
                foreach ([true, false] as $commit) {
                    $casos[] = [$metodo, $planPrimero, $commit];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('planificacion')]
    public function test_real_duration_change_and_clinical_writing_preserve_historical_appointment(string $metodo, bool $planPrimero, bool $commit): void
    {
        $c = $this->preparar();
        if ($metodo === 'PATCH') {
            $this->sembrar($c);
        }
        $clinica = $this->datos($c, $metodo, 'A');
        $config = $this->navegador($c['cookies'][0], 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        $plan = ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']];
        $original = $this->snapshot(['citas', 'cambios_cita', 'recordatorios']);
        $w1 = $this->worker($c['cookies'][0], $planPrimero ? 'PATCH' : $metodo,
            $planPrimero ? '/api/configuracion-citas' : $this->ruta($c, 'consulta'), $planPrimero ? $plan : $clinica, true);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker($c['cookies'][$planPrimero ? 1 : 0], $planPrimero ? $metodo : 'PATCH',
            $planPrimero ? $this->ruta($c, 'consulta') : '/api/configuracion-citas', $planPrimero ? $clinica : $plan);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->assertSame($original, $this->snapshot(['citas', 'cambios_cita', 'recordatorios']));
        $this->signal($w1, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = $this->resultado($w1);
        $rb = $this->resultado($w2);
        $exitoClinico = $metodo === 'POST' ? 201 : 200;
        $this->assertSame($commit ? ($planPrimero ? 200 : $exitoClinico) : 500, $ra['status']);
        $this->assertSame($planPrimero ? $exitoClinico : 200, $rb['status']);
        $this->assertSame($planPrimero && ! $commit ? 15 : 20, DB::table('configuracion_clinica')->value('duracion_turno_minutos'));
        $this->assertSame($original, $this->snapshot(['citas', 'cambios_cita', 'recordatorios']));
        $version = $metodo === 'POST' ? 0 : 1;
        $this->assertSame($version + ($planPrimero || $commit ? 1 : 0), DB::table('versiones_consulta')->count());
        $this->auditoriaSegura();
    }
}
