<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\usuarios\RegistroPacienteRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\utils\CambioCorreoMail;
use Clinica\shared\utils\CredencialTemporal;
use Clinica\shared\utils\VerificacionPacienteMail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AutenticacionConcurrenteTest extends TestCase
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
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000004_create_especialidades', '000006_create_usuarios', '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes', '000011_create_contactos_emergencia', '000012_create_preferencias_paciente', '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes', '000015_create_verificaciones_correo', '000016_create_medicos'] as $name) {
            $migration = require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php');
            $migration->up();
        }
        (require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_21_000030_add_actualizado_en_to_especialidades.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_19_000017_create_medico_especialidad_table.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_21_000031_add_actualizado_en_to_medicos.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_21_000032_add_habilitada_to_medico_especialidad.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_21_000033_add_actualizado_en_to_consultorios.php'))->up();
        // La migración productiva fija public; la prueba usa exclusivamente su esquema efímero.
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($worker['process'])) {
                    proc_close($worker['process']);
                }
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->previousPath]);
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        } finally {
            parent::tearDown();
        }
    }

    private function worker(string $correo, string $password, string $ip = '192.0.2.70', string $mode = 'login', array $extra = []): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/autenticacion-worker.php')], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso de prueba.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $index = count($this->workers);
        $this->workers[$index] = compact('process', 'pipes');
        $this->signal($index, json_encode(array_merge(['schema' => $this->schema, 'correo' => $correo, 'password' => $password, 'ip' => $ip, 'mode' => $mode], $extra)));
        $ready = json_decode($this->line($index), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ready', $ready);
        $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $ready['ready']);
        $this->workers[$index]['pid'] = $ready['ready'];

        return $index;
    }

    private function signal(int $worker, string $line): void
    {
        fwrite($this->workers[$worker]['pipes'][0], $line."\n");
        fflush($this->workers[$worker]['pipes'][0]);
    }

    private function line(int $worker, bool $permitirError = false): string
    {
        $deadline = microtime(true) + 15;
        $line = '';
        do {
            $chunk = fgets($this->workers[$worker]['pipes'][1]);
            if ($chunk !== false) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    if (! $permitirError && str_contains($line, '"status":500')) {
                        throw new RuntimeException('Fallo HTTP del proceso: '.$line);
                    }

                    return trim($line);
                }
            }
            $error = stream_get_contents($this->workers[$worker]['pipes'][2]);
            if ($error !== '') {
                throw new RuntimeException('El proceso de prueba falló: '.$error);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Venció la espera de la barrera del proceso.');
    }

    private function blocked(int $worker): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $row = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$this->workers[$worker]['pid']]);
            if ($row?->wait_event_type === 'Lock') {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('La autenticación no esperó el bloqueo transaccional de Usuario.');
    }

    public static function conflictosNombreEspecialidad(): array
    {
        return [['crear'], ['editar']];
    }

    public static function consultasBitacoraRevocadas(): array
    {
        $casos = [];
        foreach (['listado', 'detalle'] as $consulta) {
            foreach (['estado', 'rol', 'sesion'] as $cambio) {
                foreach ([true, false] as $confirmar) {
                    $casos[$consulta.'-'.$cambio.'-'.($confirmar ? 'commit' : 'rollback')] = [$consulta, $cambio, $confirmar];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('consultasBitacoraRevocadas')]
    public function test_audit_read_revalidates_access_after_middleware_and_waits_for_committed_account_changes(string $consulta, string $cambio, bool $confirmar): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $idEvento = DB::table('bitacora')->value('id_evento');
        $ruta = $consulta === 'listado' ? '/api/bitacora' : '/api/bitacora/'.$idEvento;
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->lockForUpdate()->first();
        $w = $this->worker('', '', mode: 'bitacora-antes', extra: ['cookies' => $cookies, 'ruta' => $ruta]);
        $this->signal($w, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($w));
        $this->signal($w, 'CONTINUAR');
        $this->blocked($w);
        if ($cambio === 'estado') {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        } elseif ($cambio === 'rol') {
            $idRol = DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol');
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['id_rol' => $idRol]);
        } else {
            $this->app->make(SesionRepository::class)->revocarUsuario($actor->getKey());
        }
        $confirmar ? DB::commit() : DB::rollBack();
        $this->assertSame($confirmar ? 401 : 200, json_decode($this->line($w), true)['status']);
        $this->assertSame(1, DB::table('bitacora')->count());
    }

    public static function consultasBitacora(): array
    {
        return [['listado'], ['detalle']];
    }

    #[DataProvider('consultasBitacora')]
    public function test_audit_reader_locks_actor_but_not_events_and_serializes_later_revocation(string $consulta): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $idEvento = DB::table('bitacora')->value('id_evento');
        $ruta = $consulta === 'listado' ? '/api/bitacora' : '/api/bitacora/'.$idEvento;
        $w = $this->worker('', '', mode: 'bitacora-lectura', extra: ['cookies' => $cookies, 'ruta' => $ruta]);
        $this->signal($w, 'INICIAR');
        $this->assertSame('LEIDA', $this->line($w));
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '150ms'");
        $this->assertNotNull(DB::table('bitacora')->where('id_evento', $idEvento)->lock('for update nowait')->first());
        DB::commit();
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '150ms'");
        try {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
            $this->fail('La revocación debe esperar la lectura autorizada.');
        } catch (QueryException $error) {
            $this->assertSame('55P03', $error->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $this->signal($w, 'CONTINUAR');
        $this->assertSame(200, json_decode($this->line($w), true)['status']);
        DB::transaction(function () use ($actor): void {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
            $this->app->make(SesionRepository::class)->revocarUsuario($actor->getKey());
        });
        $this->navegador($cookies, 'GET', $ruta)->assertUnauthorized();
        $this->assertSame(1, DB::table('bitacora')->count());
    }

    public function test_doctor_two_drafts_commit_only_one_edit_with_distinct_administrators(): void
    {
        $peticiones = [];
        $idEspecialidad = DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad Ficticia'], 'id_especialidad');
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            if ($i === 0) {
                $f = $this->navegador($cookies, 'POST', '/api/medicos', ['registro_profesional' => 'REG-FICTICIO',
                    'nombres' => 'Original', 'apellidos' => 'Ficticio', 'telefono' => '70000000',
                    'correo_contacto' => 'contacto@example.test', 'especialidades' => [(string) $idEspecialidad]])->assertCreated()->json('data');
            }
            $peticiones[] = ['cookies' => $cookies, 'id_objetivo' => $f['id_medico'],
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'nombres' => 'Edicion '.$i]];
        }
        DB::beginTransaction();
        DB::table('medicos')->where('id_medico', $f['id_medico'])->lockForUpdate()->first();
        $workers = [];
        foreach ($peticiones as $datos) {
            $w = $this->worker('', '', mode: 'medico-editar', extra: $datos);
            $this->signal($w, 'INICIAR');
            $this->blocked($w);
            $workers[] = $w;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ACTUALIZADO')->count());
        $this->assertContains(DB::table('medicos')->where('id_medico', $f['id_medico'])->value('nombres'), ['Edicion 0', 'Edicion 1']);
    }

    public static function ordenEstadoRol(): array
    {
        return [[true], [false]];
    }

    public static function ordenRetiroReserva(): array
    {
        return [[true, true], [true, false], [false, true], [false, false]];
    }

    #[DataProvider('ordenRetiroReserva')]
    public function test_doctor_specialty_withdrawal_and_reservation_protocol_serialize_in_both_orders_with_rollback(bool $reservaPrimero, bool $confirmar): void
    {
        (require base_path('src/data/database/migrations/2026_10_19_000019_create_citas_table.php'))->up();
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Otra Ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $otra]);
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0])->assertOk()->json('data');
        $payload = ['actualizado_en_original' => $f['actualizado_en'], 'especialidades' => [(string) $otra]];
        DB::beginTransaction();
        DB::table('medicos')->where('id_medico', $f['id_medico'])->lockForUpdate()->first();
        if ($reservaPrimero) {
            // El escritor de citas toma Médico → Especialidad → par y revalida antes de escribir.
            DB::table('especialidades')->where('id_especialidad', $r['specialty'])->lockForUpdate()->first();
            $par = DB::table('medico_especialidad')->where('id_medico', $f['id_medico'])->where('id_especialidad', $r['specialty'])->lockForUpdate()->first();
            $this->assertTrue($par->habilitada);
            $w = $this->worker('', '', mode: 'medico-especialidades', extra: ['cookies' => $cookies, 'id_objetivo' => $f['id_medico'], 'payload' => $payload]);
        } else {
            $w = $this->worker('', '', mode: 'medico-reserva-protocolo', extra: ['cookies' => $cookies, 'fila_cita' => $this->citaRow($r)]);
        }
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        if ($reservaPrimero) {
            DB::table('citas')->insert($this->citaRow($r));
        } else {
            $this->navegador($cookies, 'PUT', '/api/medicos/'.$f['id_medico'].'/especialidades', $payload)->assertOk();
        }
        $confirmar ? DB::commit() : DB::rollBack();
        $esperado = $confirmar ? 409 : ($reservaPrimero ? 200 : 201);
        $this->assertSame($esperado, json_decode($this->line($w), true)['status']);
        $habilitada = DB::table('medico_especialidad')->where('id_medico', $f['id_medico'])->where('id_especialidad', $r['specialty'])->value('habilitada');
        $this->assertSame($reservaPrimero === $confirmar, $habilitada);
        $this->assertSame($habilitada ? 1 : 0, DB::table('citas')->count());
        $this->assertSame($habilitada ? 0 : 1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
        $this->assertSame(3, DB::table('medico_especialidad')->count());
    }

    public function test_doctor_two_specialty_replacements_cannot_remove_each_others_last_enabled_pair(): void
    {
        (require base_path('src/data/database/migrations/2026_10_19_000019_create_citas_table.php'))->up();
        $r = $this->citaResources();
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Otra Ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $otra]);
        $peticiones = [];
        foreach ([$r['specialty'], $otra] as $idEspecialidad) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0])->assertOk()->json('data');
            $peticiones[] = ['cookies' => $cookies, 'id_objetivo' => $f['id_medico'],
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'especialidades' => [(string) $idEspecialidad]]];
        }
        DB::beginTransaction();
        DB::table('medicos')->where('id_medico', $f['id_medico'])->lockForUpdate()->first();
        $workers = [];
        foreach ($peticiones as $datos) {
            $w = $this->worker('', '', mode: 'medico-especialidades', extra: $datos);
            $this->signal($w, 'INICIAR');
            $this->blocked($w);
            $workers[] = $w;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('medico_especialidad')->where('id_medico', $f['id_medico'])->where('habilitada', true)->count());
        $this->assertSame(2, DB::table('medico_especialidad')->where('id_medico', $f['id_medico'])->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
    }

    #[DataProvider('ordenEstadoRol')]
    public function test_doctor_state_and_cu03_role_exit_cannot_leave_active_former_doctor(bool $rolPrimero): void
    {
        // Evita consultar tablas públicas ajenas al escenario al comprobar obligaciones CU-03.
        (require base_path('src/data/database/migrations/2026_10_19_000019_create_citas_table.php'))->up();
        (require base_path('src/data/database/migrations/2026_10_19_000022_create_consultas_medicas_table.php'))->up();
        $this->usuarioAuth('Recepcionista');
        $usuario = $this->usuarioAuth('Médico');
        $id = (string) $usuario->medico->getKey();
        $administradores = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $administradores[] = $cookies;
        }
        $f = $this->navegador($administradores[0], 'GET', '/api/medicos/'.$id)->assertOk()->json('data');
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $w = $this->worker('', '', mode: $rolPrimero ? 'medico-estado' : 'cuenta-rol', extra: [
            'cookies' => $administradores[1], 'id_objetivo' => $rolPrimero ? $id : (string) $usuario->getKey(),
            'payload' => $rolPrimero ? ['activo' => true, 'actualizado_en_original' => $f['actualizado_en']] : ['rol' => 'Recepcionista']]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        if ($rolPrimero) {
            $this->navegador($administradores[0], 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Recepcionista'])->assertOk();
        } else {
            $this->navegador($administradores[0], 'PATCH', '/api/medicos/'.$id.'/estado',
                ['activo' => true, 'actualizado_en_original' => $f['actualizado_en']])->assertOk();
        }
        DB::commit();
        $this->assertSame(409, json_decode($this->line($w), true)['status']);
        $this->assertSame(! $rolPrimero, $usuario->fresh()->medico->activo);
        $this->assertSame($rolPrimero ? 'Recepcionista' : 'Médico', $usuario->fresh()->rol->nombre);
        $this->assertSame($id, (string) $usuario->fresh()->medico->getKey());
    }

    public function test_doctor_state_and_data_drafts_share_one_version_under_concurrency(): void
    {
        $usuario = $this->usuarioAuth('Médico');
        $id = (string) $usuario->medico->getKey();
        $workers = [];
        $peticiones = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$id)->assertOk()->json('data');
            $peticiones[] = ['cookies' => $cookies, 'id_objetivo' => $id,
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], ...($i === 0 ? ['activo' => true] : ['nombres' => 'Edicion Ficticia'])]];
        }
        DB::beginTransaction();
        DB::table('medicos')->where('id_medico', $id)->lockForUpdate()->first();
        foreach ($peticiones as $i => $datos) {
            $w = $this->worker('', '', mode: $i === 0 ? 'medico-estado' : 'medico-editar', extra: $datos);
            $this->signal($w, 'INICIAR');
            $this->blocked($w);
            $workers[] = $w;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('bitacora')->whereIn('tipo_evento', ['MEDICO_ACTUALIZADO', 'MEDICO_ESTADO_CAMBIADO'])->count());
        $medico = $usuario->fresh()->medico;
        $this->assertTrue(($medico->activo && $medico->nombres === 'Medico') || (! $medico->activo && $medico->nombres === 'Edicion Ficticia'));
    }

    public function test_doctor_reactivation_waits_for_account_deactivation_without_restoring_access(): void
    {
        $usuario = $this->usuarioAuth('Médico');
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$usuario->medico->getKey())->assertOk()->json('data');
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $w = $this->worker('', '', mode: 'medico-estado', extra: ['cookies' => $cookies, 'id_objetivo' => $f['id_medico'],
            'payload' => ['activo' => true, 'actualizado_en_original' => $f['actualizado_en']]]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        DB::commit();
        $this->assertSame(200, json_decode($this->line($w), true)['status']);
        $this->assertTrue($usuario->fresh()->medico->activo);
        $this->assertSame('Desactivada', $usuario->fresh()->estado_cuenta);
        $this->assertSame('Médico', $usuario->fresh()->rol->nombre);
    }

    public function test_doctor_creation_rechecks_specialty_after_waiting_without_partial_rows(): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $id = DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad Ficticia'], 'id_especialidad');
        DB::beginTransaction();
        DB::table('especialidades')->where('id_especialidad', $id)->lockForUpdate()->first();
        $w = $this->worker('', '', mode: 'medico-crear', extra: ['cookies' => $cookies, 'payload' => [
            'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'telefono' => '70000000',
            'correo_contacto' => 'contacto@example.test', 'especialidades' => [(string) $id]]]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        // Simula pérdida concurrente de la relación por una escritura externa; la API no ofrece DELETE.
        DB::table('especialidades')->where('id_especialidad', $id)->delete();
        DB::commit();
        $this->assertSame(422, json_decode($this->line($w), true)['status']);
        $this->assertSame(0, DB::table('medicos')->count());
        $this->assertSame(0, DB::table('medico_especialidad')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CREADO')->count());
    }

    public function test_doctor_creation_revalidates_revoked_administrator_after_coordination_wait(): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $w = $this->worker('', '', mode: 'medico-crear', extra: ['cookies' => $cookies, 'payload' => [
            'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'telefono' => '70000000',
            'correo_contacto' => 'contacto@example.test', 'especialidades' => ['1']]]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        DB::commit();
        $this->assertSame(401, json_decode($this->line($w), true)['status']);
        $this->assertSame(0, DB::table('medicos')->count());
    }

    #[DataProvider('conflictosNombreEspecialidad')]
    public function test_specialty_name_race_between_distinct_administrators_commits_only_one(string $operacion): void
    {
        $peticiones = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $datos = ['nombre' => $i === 0 ? 'Objetivo Ficticio' : 'OBJETIVO FICTICIO'];
            if ($operacion === 'editar') {
                $r = $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Original '.$i])->assertCreated()->json('data');
                $datos['actualizado_en_original'] = $r['actualizado_en'];
            }
            $peticiones[] = ['cookies' => $cookies, 'payload' => $datos, 'id_objetivo' => $r['id_especialidad'] ?? null];
        }
        // Barrera en el índice de nombre, no en el actor: los dos procesos usan Administradores distintos.
        DB::beginTransaction();
        DB::table('especialidades')->insert(['nombre' => 'objetivo ficticio']);
        $workers = [];
        foreach ($peticiones as $datos) {
            $w = $this->worker('', '', mode: 'especialidad-'.$operacion, extra: $datos);
            $this->signal($w, 'INICIAR');
            $this->blocked($w);
            $workers[] = $w;
        }
        DB::rollBack();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([$operacion === 'crear' ? 201 : 200, 409], $statuses);
        $this->assertSame(1, DB::table('especialidades')->whereRaw('lower(btrim(nombre)) = ?', ['objetivo ficticio'])->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', $operacion === 'crear' ? 'ESPECIALIDAD_CREADA' : 'ESPECIALIDAD_ACTUALIZADA')->count());
    }

    private function prepararAsignaciones(): void
    {
        foreach (['000018_create_horarios_atencion', '000019_create_citas', '000020_create_cambios_cita',
            '000021_create_recordatorios', '000022_create_consultas_medicas'] as $name) {
            (require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php'))->up();
        }
    }

    private function prepararHorarios(): void
    {
        $this->prepararAsignaciones();
        (require base_path('src/data/database/migrations/2026_10_19_000005_create_configuracion_clinica_table.php'))->up();
        DB::table('configuracion_clinica')->insert(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
    }

    public static function revocacionesPlanificacion(): array
    {
        $casos = [];
        foreach (['consultorio', 'asignacion-impacto', 'asignacion', 'horarios', 'horario-crear', 'duracion'] as $operacion) {
            foreach (['estado', 'rol', 'sesion', 'clave'] as $revocacion) {
                $casos[$operacion.'-'.$revocacion] = [$operacion, $revocacion];
            }
        }

        return $casos;
    }

    #[DataProvider('revocacionesPlanificacion')]
    public function test_planning_operations_revalidate_actor_and_persisted_session_after_middleware(string $operacion, string $revocacion): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $base = '/api/medicos/'.$r['doctors'][0];
        $version = $this->navegador($cookies, 'GET', $base.'/horarios')->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $version];
        $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->json('data.huella_impacto');
        $configuracion = $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        [$metodo, $ruta, $payload] = match ($operacion) {
            'consultorio' => ['GET', '/api/consultorios/'.$r['rooms'][0], []],
            'asignacion-impacto' => ['POST', $base.'/consultorio/impacto', $propuesta],
            'asignacion' => ['PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $huella, 'confirmar_traslados' => false]],
            'horarios' => ['GET', $base.'/horarios', []],
            'horario-crear' => ['POST', $base.'/horarios', ['dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'actualizado_en_original' => $version]],
            'duracion' => ['PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $configuracion['actualizado_en']]],
        };
        $antes = $this->estadoPlanificacion();
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $worker = $this->worker('', '', mode: 'consultorio-lectura', extra: compact('cookies', 'metodo', 'ruta', 'payload'));
        $this->signal($worker, 'INICIAR');
        // El worker ya atravesó los middlewares y espera dentro del Service.
        $this->blocked($worker);
        match ($revocacion) {
            'estado' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']),
            'rol' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['id_rol' => DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol')]),
            'sesion' => DB::table('sessions')->where('user_id', $actor->getKey())->delete(),
            'clave' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['password_hash' => Hash::make('Nueva frase ficticia para la prueba 567')]),
        };
        DB::commit();
        $resultado = json_decode($this->line($worker), true);
        $this->assertSame(401, $resultado['status']);
        $this->assertSame('AUTH_NO_AUTENTICADO', $resultado['code']);
        $this->assertSame($antes, $this->estadoPlanificacion());
    }

    private function estadoPlanificacion(): array
    {
        $estado = [];
        foreach (['medicos', 'consultorios', 'horarios_atencion', 'configuracion_clinica', 'citas', 'cambios_cita', 'recordatorios'] as $tabla) {
            $estado[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $estado['eventos'] = DB::table('bitacora')->whereIn('modulo', ['CONSULTORIOS', 'HORARIOS'])->count();

        return $estado;
    }

    public static function ordenesHorarioPlanificacion(): array
    {
        $casos = [];
        foreach (['asignacion', 'duracion', 'version_medico'] as $tipo) {
            foreach ([true, false] as $horarioPrimero) {
                foreach ([true, false] as $confirmar) {
                    $casos[] = [$tipo, $horarioPrimero, $confirmar];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('ordenesHorarioPlanificacion')]
    public function test_real_schedule_against_assignment_or_duration_in_both_orders_with_commit_or_rollback(string $tipo, bool $horarioPrimero, bool $confirmar): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        $peticiones = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $peticiones[$i] = ['cookies' => $cookies, 'ahora' => '2026-10-21T12:00:00Z'];
        }
        if ($tipo !== 'duracion') {
            DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
            $c = $peticiones[0]['cookies'];
            $base = '/api/medicos/'.$r['doctors'][0].'/consultorio';
            $version = $this->navegador($c, 'GET', $base)->assertOk()->json('data.actualizado_en');
            $payload = ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $version];
            $huella = $this->navegador($c, 'POST', $base.'/impacto', $payload)->assertOk()->json('data.huella_impacto');
            $otra = [...$peticiones[0], 'mode' => 'consultorio-asignar', 'id_objetivo' => $r['doctors'][0],
                'payload' => [...$payload, 'huella_impacto' => $huella, 'confirmar_traslados' => false]];
        } else {
            $c = $peticiones[0]['cookies'];
            $version = $this->navegador($c, 'GET', '/api/configuracion-citas')->assertOk()->json('data.actualizado_en');
            $otra = [...$peticiones[0], 'mode' => 'configuracion-citas', 'payload' => ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $version]];
        }
        $c = $peticiones[1]['cookies'];
        $medicoHorario = $r['doctors'][$tipo === 'version_medico' ? 0 : 1];
        $version = $this->navegador($c, 'GET', '/api/medicos/'.$medicoHorario.'/horarios')->assertOk()->json('data.actualizado_en');
        $horario = [...$peticiones[1], 'mode' => 'horario-crear', 'id_objetivo' => $medicoHorario,
            'payload' => ['dia_semana' => $tipo === 'version_medico' ? 1 : 7, 'hora_inicio' => '08:00:00', 'hora_fin' => $tipo === 'duracion' ? '08:15:00' : '12:00:00', 'actualizado_en_original' => $version]];
        [$primera, $segunda] = $horarioPrimero ? [$horario, $otra] : [$otra, $horario];
        $w1 = $this->worker('', '', mode: $primera['mode'], extra: [...$primera, 'pausar_planificacion' => true]);
        $this->signal($w1, 'INICIAR');
        $this->assertSame('ESCRITURA_PENDIENTE', $this->line($w1));
        $w2 = $this->worker('', '', mode: $segunda['mode'], extra: $segunda);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $confirmar ? 'CONFIRMAR' : 'REVERTIR');
        $r1 = json_decode($this->line($w1, permitirError: ! $confirmar), true);
        $r2 = json_decode($this->line($w2), true);
        $this->assertSame($confirmar ? ($horarioPrimero ? 201 : 200) : 500, $r1['status']);
        $this->assertSame($confirmar ? 409 : ($horarioPrimero ? 200 : 201), $r2['status']);
        $esperado = match ($tipo) {
            'duracion' => 'DURACION_INCOMPATIBLE', 'version_medico' => 'MEDICO_EDICION_OBSOLETA',
            default => $horarioPrimero ? 'ASIGNACION_IMPACTO_OBSOLETO' : 'PLANIFICACION_CONFLICTO',
        };
        $this->assertSame($confirmar ? $esperado : null, $r2['code']);
        $ganaHorario = $confirmar === $horarioPrimero;
        $this->assertSame(($tipo === 'version_medico' ? 1 : 0) + ($ganaHorario ? 1 : 0), DB::table('horarios_atencion')->where('id_medico', $medicoHorario)->count());
        if ($tipo === 'duracion') {
            $this->assertSame($ganaHorario ? 15 : 20, DB::table('configuracion_clinica')->value('duracion_turno_minutos'));
        } else {
            $this->assertSame($r['rooms'][$ganaHorario ? 0 : 1], DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('id_consultorio'));
        }
        $this->assertSame(1, DB::table('bitacora')->whereIn('modulo', ['CONSULTORIOS', 'HORARIOS'])->count());
    }

    public static function ordenesProtocoloTraslado(): array
    {
        $casos = [];
        foreach (['reserva', 'atencion'] as $tipo) {
            foreach ([true, false] as $trasladoPrimero) {
                foreach ([true, false] as $confirmar) {
                    $casos[] = [$tipo, $trasladoPrimero, $confirmar];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('ordenesProtocoloTraslado')]
    public function test_transfer_and_future_reservation_or_care_protocol_in_both_orders_and_rollback(string $tipo, bool $trasladoPrimero, bool $confirmar): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $base = '/api/medicos/'.$r['doctors'][0].'/consultorio';
        $version = $this->navegador($cookies, 'GET', $base)->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $version];
        $huella = $this->navegador($cookies, 'POST', $base.'/impacto', $propuesta)->assertOk()->json('data.huella_impacto');
        $traslado = ['mode' => 'consultorio-asignar', 'cookies' => $cookies, 'id_objetivo' => $r['doctors'][0], 'ahora' => '2026-10-21T12:00:00Z',
            'payload' => [...$propuesta, 'huella_impacto' => $huella, 'confirmar_traslados' => true]];
        $protocolo = ['mode' => 'planificacion-protocolo', 'tipo' => $tipo, 'id_medico' => $r['doctors'][0], 'id_cita' => $id,
            'fila_cita' => $this->citaRow($r, ['inicio_at' => '2026-11-08T12:00:00Z', 'fin_at' => '2026-11-08T12:15:00Z'])];
        [$primera, $segunda] = $trasladoPrimero ? [$traslado, $protocolo] : [$protocolo, $traslado];
        $w1 = $this->worker('', '', mode: $primera['mode'], extra: [...$primera, $trasladoPrimero ? 'pausar_planificacion' : 'pausar_protocolo' => true]);
        $this->signal($w1, 'INICIAR');
        $this->assertSame($trasladoPrimero ? 'ESCRITURA_PENDIENTE' : 'PROTOCOLO_PENDIENTE', $this->line($w1));
        $w2 = $this->worker('', '', mode: $segunda['mode'], extra: $segunda);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        $this->signal($w1, $confirmar ? 'CONFIRMAR' : 'REVERTIR');
        $r1 = json_decode($this->line($w1, permitirError: ! $confirmar), true);
        $r2 = json_decode($this->line($w2), true);
        $this->assertSame($confirmar ? ($trasladoPrimero ? 200 : 201) : 500, $r1['status']);
        $conflicto = $confirmar && (! $trasladoPrimero || $tipo === 'reserva');
        $this->assertSame($conflicto ? 409 : ($trasladoPrimero ? 201 : 200), $r2['status']);
        $trasladado = $trasladoPrimero ? $confirmar : ! $confirmar;
        $this->assertSame($r['rooms'][$trasladado ? 1 : 0], DB::table('citas')->where('id_cita', $id)->value('id_consultorio'));
        $this->assertSame($trasladado ? 1 : 0, DB::table('cambios_cita')->count());
        $this->assertSame($trasladado ? 1 : 0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->count());
        $escrito = $trasladoPrimero ? ! $conflicto : $confirmar;
        $this->assertSame($tipo === 'atencion' && $escrito ? 1 : 0, DB::table('consultas_medicas')->count());
        $this->assertSame($tipo === 'reserva' && $escrito ? 2 : 1, DB::table('citas')->count());
    }

    public function test_simultaneous_shared_room_schedules_cannot_both_be_created(): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0]]);
        $requests = [];
        foreach ($r['doctors'] as $doctor) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $version = $this->navegador($cookies, 'GET', '/api/medicos/'.$doctor.'/horarios')->assertOk()->json('data.actualizado_en');
            $requests[] = ['cookies' => $cookies, 'id_objetivo' => $doctor, 'payload' => ['dia_semana' => 7,
                'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'actualizado_en_original' => $version]];
        }
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        $workers = [];
        foreach ($requests as $data) {
            $worker = $this->worker('', '', mode: 'horario-crear', extra: $data);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $results = array_map(fn ($worker) => json_decode($this->line($worker), true), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame('PLANIFICACION_CONFLICTO', array_values(array_filter($results, fn ($r) => $r['status'] === 409))[0]['code']);
        $this->assertSame(1, DB::table('horarios_atencion')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'HORARIO_CREADO')->count());
    }

    public function test_concurrent_short_schedule_and_longer_duration_never_commit_incompatible_configuration(): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        $actor1 = $this->usuarioAuth();
        $c1 = $this->csrfAuth();
        $this->loginAuth($c1, $actor1)->assertOk();
        $version = $this->navegador($c1, 'GET', '/api/medicos/'.$r['doctors'][0].'/horarios')->assertOk()->json('data.actualizado_en');
        $actor2 = $this->usuarioAuth();
        $c2 = $this->csrfAuth();
        $this->loginAuth($c2, $actor2)->assertOk();
        $config = $this->navegador($c2, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        $w1 = $this->worker('', '', mode: 'horario-crear', extra: ['cookies' => $c1, 'id_objetivo' => $r['doctors'][0],
            'payload' => ['dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '08:15:00', 'actualizado_en_original' => $version]]);
        $this->signal($w1, 'INICIAR');
        $this->blocked($w1);
        $w2 = $this->worker('', '', mode: 'configuracion-citas', extra: ['cookies' => $c2,
            'payload' => ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']]]);
        $this->signal($w2, 'INICIAR');
        $this->blocked($w2);
        DB::commit();
        $results = [json_decode($this->line($w1), true), json_decode($this->line($w2), true)];
        $this->assertSame(1, count(array_filter($results, fn ($r) => in_array($r['status'], [200, 201], true))));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 409 && $r['code'] === 'DURACION_INCOMPATIBLE')));
        $count = DB::table('horarios_atencion')->count();
        $this->assertSame($count === 1 ? 15 : 20, DB::table('configuracion_clinica')->value('duracion_turno_minutos'));
    }

    public static function confirmarReservaHorario(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('confirmarReservaHorario')]
    public function test_disabling_schedule_rechecks_coverage_after_concurrent_reservation_commit_or_rollback(bool $confirmar): void
    {
        $this->prepararHorarios();
        $r = $this->citaResources();
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $ruta = '/api/medicos/'.$r['doctors'][0].'/horarios';
        $version = $this->navegador($cookies, 'GET', $ruta)->assertOk()->json('data.actualizado_en');
        $h = $this->navegador($cookies, 'POST', $ruta, ['dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'actualizado_en_original' => $version])->assertCreated()->json('data');
        DB::beginTransaction();
        // Escritor CU-14 simulado: coordinación antes de configuración/Médico/sala/cita.
        $this->app->make(PlanificacionRepository::class)->coordinar();
        DB::table('configuracion_clinica')->where('id_configuracion', 1)->lockForUpdate()->first();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->lockForUpdate()->first();
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->lockForUpdate()->first();
        DB::table('citas')->insert($this->citaRow($r));
        $worker = $this->worker('', '', mode: 'horario-estado', extra: ['cookies' => $cookies, 'id_objetivo' => $r['doctors'][0], 'id_horario' => $h['id_horario'],
            'payload' => ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']]]);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        $confirmar ? DB::commit() : DB::rollBack();
        $result = json_decode($this->line($worker), true);
        $this->assertSame($confirmar ? 409 : 200, $result['status']);
        $this->assertSame($confirmar ? 'HORARIO_CITAS_SIN_COBERTURA' : null, $result['code']);
        $this->assertSame($confirmar, DB::table('horarios_atencion')->where('id_horario', $h['id_horario'])->value('activo'));
    }

    public function test_two_professionals_cannot_confirm_simultaneous_conflicting_room_assignments(): void
    {
        $this->prepararAsignaciones();
        $r = $this->citaResources();
        $destination = DB::table('consultorios')->insertGetId(['codigo_consultorio' => 'DESTINO-NUEVO', 'nombre' => 'Destino ficticio'], 'id_consultorio');
        $requests = [];
        for ($i = 0; $i < 2; $i++) {
            DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][$i], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $path = '/api/medicos/'.$r['doctors'][$i].'/consultorio';
            $f = $this->navegador($cookies, 'GET', $path)->assertOk()->json('data');
            $payload = ['id_consultorio' => (string) $destination, 'actualizado_en_original' => $f['actualizado_en']];
            $impacto = $this->navegador($cookies, 'POST', $path.'/impacto', $payload)->assertOk()->assertJsonPath('data.puede_confirmar', true)->json('data');
            $requests[] = ['cookies' => $cookies, 'id_objetivo' => $r['doctors'][$i],
                'payload' => [...$payload, 'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => false]];
        }
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        $workers = [];
        foreach ($requests as $data) {
            $worker = $this->worker('', '', mode: 'consultorio-asignar', extra: $data);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $responses = array_map(fn ($worker) => json_decode($this->line($worker), true), $workers);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame('ASIGNACION_IMPACTO_OBSOLETO', array_values(array_filter($responses, fn ($r) => $r['status'] === 409))[0]['code']);
        $this->assertSame(1, DB::table('medicos')->where('id_consultorio', $destination)->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->count());
    }

    public static function cambiosConcurrentesAsignacion(): array
    {
        return [['reserva', true], ['reserva', false], ['consulta', true], ['consulta', false]];
    }

    #[DataProvider('cambiosConcurrentesAsignacion')]
    public function test_room_transfer_revalidates_reservation_or_documented_care_after_concurrent_commit_or_rollback(string $tipo, bool $confirmar): void
    {
        $this->prepararAsignaciones();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $appointment = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $path = '/api/medicos/'.$r['doctors'][0].'/consultorio';
        $f = $this->navegador($cookies, 'GET', $path)->assertOk()->json('data');
        $payload = ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $f['actualizado_en']];
        $impacto = $this->navegador($cookies, 'POST', $path.'/impacto', $payload)->assertOk()->assertJsonPath('data.puede_confirmar', true)->json('data');
        DB::beginTransaction();
        // Protocolo simulado de futuros CU-14/CU-23; no acredita esos endpoints.
        $this->app->make(PlanificacionRepository::class)->coordinar();
        if ($tipo === 'reserva') {
            DB::table('medicos')->where('id_medico', $r['doctors'][1])->lockForUpdate()->first();
            DB::table('consultorios')->where('id_consultorio', $r['rooms'][1])->lockForUpdate()->first();
            DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]));
        } else {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->lockForUpdate()->first();
            DB::table('citas')->where('id_cita', $appointment)->lockForUpdate()->first();
            DB::table('consultas_medicas')->insert(['codigo_consulta' => 'FICTICIA', 'id_cita' => $appointment]);
        }
        $worker = $this->worker('', '', mode: 'consultorio-asignar', extra: ['cookies' => $cookies, 'id_objetivo' => $r['doctors'][0],
            'payload' => [...$payload, 'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true]]);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        $confirmar ? DB::commit() : DB::rollBack();
        $result = json_decode($this->line($worker), true);
        $this->assertSame($confirmar ? 409 : 200, $result['status']);
        $this->assertSame($confirmar ? 'ASIGNACION_IMPACTO_OBSOLETO' : null, $result['code']);
        $this->assertSame($r['rooms'][$confirmar ? 0 : 1], DB::table('citas')->where('id_cita', $appointment)->value('id_consultorio'));
        $this->assertSame($confirmar ? 0 : 1, DB::table('cambios_cita')->count());
        $this->assertSame($confirmar ? 0 : 1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->count());
    }

    public function test_consultorio_distinct_administrators_edit_one_version_only_once(): void
    {
        $requests = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            if ($i === 0) {
                $f = $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'CONCURRENTE', 'nombre' => 'Original ficticio'])->assertCreated()->json('data');
            }
            $requests[] = ['cookies' => $cookies, 'id_objetivo' => $f['id_consultorio'],
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'nombre' => 'Borrador '.$i]];
        }
        DB::beginTransaction();
        DB::table('consultorios')->where('id_consultorio', $f['id_consultorio'])->lockForUpdate()->first();
        $workers = [];
        foreach ($requests as $data) {
            $worker = $this->worker('', '', mode: 'consultorio-editar', extra: $data);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $responses = array_map(fn ($worker) => json_decode($this->line($worker), true), $workers);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ACTUALIZADO')->count());
        $this->assertContains(DB::table('consultorios')->value('nombre'), ['Borrador 0', 'Borrador 1']);
    }

    public function test_consultorio_same_code_concurrent_creations_commit_only_one(): void
    {
        $requests = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $requests[] = ['cookies' => $cookies, 'payload' => ['codigo_consultorio' => 'MISMO-CODIGO', 'nombre' => 'Ficticio '.$i]];
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $workers = [];
        foreach ($requests as $data) {
            $worker = $this->worker('', '', mode: 'consultorio-crear', extra: $data);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $responses = array_map(fn ($worker) => json_decode($this->line($worker), true), $workers);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame('CONSULTORIO_CODIGO_DUPLICADO', array_values(array_filter($responses, fn ($r) => $r['status'] === 409))[0]['code']);
        $this->assertSame(1, DB::table('consultorios')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_CREADO')->count());
    }

    public function test_consultorio_deactivation_rechecks_assignment_committed_while_waiting_for_planning(): void
    {
        (require base_path('src/data/database/migrations/2026_10_19_000019_create_citas_table.php'))->up();
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $f = $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'DESTINO', 'nombre' => 'Destino ficticio'])->assertCreated()->json('data');
        $r = $this->citaResources();
        DB::beginTransaction();
        $this->app->make(PlanificacionRepository::class)->coordinar();
        // Simula el protocolo del futuro escritor CU-11; no acredita su endpoint.
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->lockForUpdate()->first();
        $worker = $this->worker('', '', mode: 'consultorio-estado', extra: ['cookies' => $cookies,
            'id_objetivo' => $f['id_consultorio'], 'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'activo' => false]]);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $f['id_consultorio']]);
        DB::commit();
        $result = json_decode($this->line($worker), true);
        $this->assertSame(409, $result['status']);
        $this->assertSame('CONSULTORIO_CON_ASIGNACIONES', $result['code']);
        $this->assertTrue(DB::table('consultorios')->where('id_consultorio', $f['id_consultorio'])->value('activo'));
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ESTADO_CAMBIADO')->count());
    }

    public function test_consultorio_creation_revalidates_actor_revoked_during_administrative_coordination(): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $worker = $this->worker('', '', mode: 'consultorio-crear', extra: ['cookies' => $cookies,
            'payload' => ['codigo_consultorio' => 'NO', 'nombre' => 'No autorizado']]);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        DB::commit();
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame(0, DB::table('consultorios')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_CREADO')->count());
    }

    public function test_specialty_data_and_state_race_rejects_stale_writer(): void
    {
        $peticiones = [];
        for ($i = 0; $i < 2; $i++) {
            $actor = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            if ($i === 0) {
                $f = $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Concurrente Ficticia'])->assertCreated()->json('data');
            }
            $peticiones[] = ['cookies' => $cookies, 'id_objetivo' => $f['id_especialidad'],
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], ...($i === 0 ? ['descripcion' => 'Editada'] : ['activa' => false])]];
        }
        DB::beginTransaction();
        DB::table('especialidades')->where('id_especialidad', $f['id_especialidad'])->lockForUpdate()->first();
        $workers = [];
        foreach ($peticiones as $i => $datos) {
            $w = $this->worker('', '', mode: $i === 0 ? 'especialidad-editar' : 'especialidad-estado', extra: $datos);
            $this->signal($w, 'INICIAR');
            $this->blocked($w);
            $workers[] = $w;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('bitacora')->whereIn('tipo_evento', ['ESPECIALIDAD_ACTUALIZADA', 'ESPECIALIDAD_ESTADO_CAMBIADO'])->count());
        $row = DB::table('especialidades')->where('id_especialidad', $f['id_especialidad'])->first();
        $this->assertTrue(($row->activa && $row->descripcion === 'Editada') || (! $row->activa && $row->descripcion === null));
    }

    public function test_specialty_write_revalidates_administrator_revoked_while_waiting(): void
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->porId($actor->getKey(), bloquear: true);
        $w = $this->worker('', '', mode: 'especialidad-crear', extra: ['cookies' => $cookies, 'payload' => ['nombre' => 'No autorizada']]);
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        DB::commit();
        $this->assertSame(401, json_decode($this->line($w), true)['status']);
        $this->assertSame(0, DB::table('especialidades')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_CREADA')->count());
    }

    public function test_pacientes_parallel_administrative_creations_commit_only_one_identity(): void
    {
        $actor = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $worker = $this->worker($actor->correo_acceso, self::PASSWORD, '192.0.2.'.(80 + $i), 'paciente-crear',
                ['cookies' => $cookies, 'payload' => ['documento_identidad' => '001-FICTICIO', 'nombres' => 'Paciente',
                    'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000']]);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, DB::table('pacientes')->where('documento_identidad', '001-FICTICIO')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_CREADO')->count());
    }

    public function test_pacientes_administrative_creation_rechecks_web_reservation_after_waiting(): void
    {
        $actor = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        $worker = $this->worker($actor->correo_acceso, self::PASSWORD, '192.0.2.82', 'paciente-crear',
            ['cookies' => $cookies, 'payload' => ['documento_identidad' => '001-RESERVA', 'nombres' => 'Paciente',
                'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000']]);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('registros_paciente_pendientes')->insert(['tipo_registro' => 'Nuevo', 'documento_identidad' => '001-RESERVA',
            'correo_propuesto' => 'reservado@example.test', 'password_hash_temporal' => 'hash-ficticio', 'datos_registro' => '{}',
            'iniciado_en' => CarbonImmutable::now('UTC'), 'expira_en' => CarbonImmutable::now('UTC')->addHours(24)]);
        DB::commit();
        $this->assertSame(409, json_decode($this->line($worker), true)['status']);
        $this->assertSame(0, DB::table('pacientes')->count());
        $this->assertNull(DB::table('registros_paciente_pendientes')->value('invalidado_en'));
    }

    public static function conflictosEdicionPaciente(): array
    {
        return [['emergencia'], ['estado']];
    }

    public static function conflictosPerfil(): array
    {
        return [['propio'], ['recepcion']];
    }

    public static function cambiosDuranteEnvioCorreo(): array
    {
        return [['sustitucion'], ['password'], ['estado']];
    }

    #[DataProvider('cambiosDuranteEnvioCorreo')]
    public function test_late_email_delivery_cannot_resurrect_superseded_or_security_cancelled_request(string $cambio): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $worker = $this->worker('', '', mode: 'paciente-correo-pausa', extra: ['cookies' => $cookies,
            'payload' => ['correo_acceso' => 'primero@example.test', 'current_password' => self::PASSWORD]]);
        $this->signal($worker, 'INICIAR');
        $this->assertSame('TRANSPORTE', $this->line($worker));
        $emision = DB::table('verificaciones_correo')->sole();
        $this->assertSame($emision->enviado_en, $emision->invalidado_en);
        // Una confirmación durante transporte no consume ni gasta intentos.
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/verificar', ['codigo' => '000000'])->assertUnprocessable();
        $this->assertSame(0, DB::table('verificaciones_correo')->value('intentos_fallidos'));
        if ($cambio === 'sustitucion') {
            $this->travel(60)->seconds();
            Mail::fake();
            config(['seguridad.correo.mailer' => 'array']);
            $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'segundo@example.test', 'current_password' => self::PASSWORD])->assertStatus(202);
        } elseif ($cambio === 'password') {
            $this->navegador($cookies, 'PUT', '/api/auth/password', ['current_password' => self::PASSWORD,
                'password' => 'Otra frase segura ficticia 123!', 'password_confirmation' => 'Otra frase segura ficticia 123!'])->assertNoContent();
        } else {
            $admin = $this->usuarioAuth();
            $administrativas = $this->csrfAuth();
            $this->loginAuth($administrativas, $admin)->assertOk();
            // Reloj congelado y snapshot futuro: dos cambios deben seguir avanzando Usuario.
            $this->travelTo(CarbonImmutable::now('UTC'));
            $antes = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->value('actualizado_en');
            foreach (['Desactivada', 'Activa'] as $estado) {
                $this->navegador($administrativas, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => $estado])->assertOk();
            }
            $despues = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->value('actualizado_en');
            $this->assertTrue(CarbonImmutable::parse($despues)->greaterThan(CarbonImmutable::parse($antes)));
        }
        $this->signal($worker, 'CONTINUAR');
        $respuesta = json_decode($this->line($worker), true);
        $this->assertSame(202, $respuesta['status']);
        $this->assertSame('fallido', $respuesta['envio']);
        $this->assertNotNull(DB::table('verificaciones_correo')->where('id_verificacion', $emision->id_verificacion)->value('invalidado_en'));
        $this->assertNull(DB::table('verificaciones_correo')->where('id_verificacion', $emision->id_verificacion)->value('consumido_en'));
        $this->assertSame($usuario->correo_acceso, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->value('correo_acceso'));
        $this->assertSame($cambio === 'sustitucion' ? 1 : 0, DB::table('verificaciones_correo')->whereNull('invalidado_en')->count());
    }

    public function test_parallel_email_confirmations_consume_once_and_revoke_both_sessions(): void
    {
        config(['seguridad.correo.mailer' => 'array']);
        Mail::fake();
        $usuario = $this->usuarioAuth('Paciente');
        $sesiones = [];
        for ($i = 0; $i < 2; $i++) {
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $usuario)->assertOk();
            $sesiones[] = $cookies;
        }
        $this->navegador($sesiones[0], 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'nuevo@example.test', 'current_password' => self::PASSWORD])->assertStatus(202);
        $codigo = Mail::sent(CambioCorreoMail::class)->last()->codigo;
        $workers = [];
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($sesiones as $cookies) {
            $worker = $this->worker('', '', mode: 'paciente-correo-verificar', extra: compact('cookies', 'codigo'));
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([204, 401], $statuses);
        $this->assertSame(1, DB::table('verificaciones_correo')->whereNotNull('consumido_en')->count());
        $this->assertSame('nuevo@example.test', DB::table('usuarios')->value('correo_acceso'));
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CAMBIO_CORREO_VERIFICADO')->where('resultado', 'EXITO')->count());
    }

    public function test_two_users_cannot_confirm_same_email_even_with_two_accepted_codes(): void
    {
        config(['seguridad.correo.mailer' => 'array']);
        Mail::fake();
        $peticiones = [];
        for ($i = 0; $i < 2; $i++) {
            $usuario = $this->usuarioAuth('Paciente');
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $usuario)->assertOk();
            $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'compartido@example.test', 'current_password' => self::PASSWORD])->assertStatus(202);
            $codigo = Mail::sent(CambioCorreoMail::class)->last()->codigo;
            $peticiones[] = compact('cookies', 'codigo');
            $this->travel(60)->seconds();
        }
        $workers = [];
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($peticiones as $peticion) {
            $worker = $this->worker('', '', mode: 'paciente-correo-verificar', extra: [...$peticion, 'ahora' => CarbonImmutable::now('UTC')->toIso8601String()]);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $statuses = array_map(fn ($w) => json_decode($this->line($w), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([204, 409], $statuses);
        $this->assertSame(1, DB::table('usuarios')->where('correo_acceso', 'compartido@example.test')->count());
        $this->assertSame(1, DB::table('verificaciones_correo')->whereNotNull('consumido_en')->count());
        $this->assertSame(2, DB::table('pacientes')->count());
    }

    public function test_late_login_response_cannot_restore_a_session_revoked_by_email_confirmation(): void
    {
        config(['seguridad.correo.mailer' => 'array']);
        Mail::fake();
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo', ['correo_acceso' => 'nuevo@example.test', 'current_password' => self::PASSWORD])->assertStatus(202);
        $codigo = Mail::sent(CambioCorreoMail::class)->last()->codigo;
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $this->assertSame(2, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->navegador($cookies, 'POST', '/api/mi-perfil/correo/verificar', compact('codigo'))->assertNoContent();
        $this->signal($worker, 'CONTINUAR');
        $respuesta = json_decode($this->line($worker), true);
        $this->assertSame(200, $respuesta['status']);
        $this->assertSame(401, $respuesta['me_status']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->assertSame('nuevo@example.test', DB::table('usuarios')->where('id_usuario', $usuario->getKey())->value('correo_acceso'));
    }

    #[DataProvider('conflictosPerfil')]
    public function test_profile_preference_and_competing_edits_share_parent_snapshot(string $competidor): void
    {
        $usuario = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $f = $this->navegador($cookies, 'GET', '/api/mi-perfil')->assertOk()->json('data');
        $otras = $cookies;
        if ($competidor === 'recepcion') {
            $actor = $this->usuarioAuth('Recepcionista');
            $otras = $this->csrfAuth();
            $this->loginAuth($otras, $actor)->assertOk();
        }
        $uno = $this->worker('', '', mode: 'paciente-perfil', extra: ['cookies' => $cookies,
            'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'recordatorios_habilitados' => false]]);
        $dos = $this->worker('', '', mode: $competidor === 'recepcion' ? 'paciente-editar' : 'paciente-perfil', extra: [
            'cookies' => $otras, 'id_objetivo' => $f['id_paciente'], 'payload' => ['actualizado_en_original' => $f['actualizado_en'],
                'contacto_emergencia' => ['nombre' => 'Familiar Ficticio', 'parentesco' => 'Familiar', 'telefono' => '72222222']]]);
        DB::beginTransaction();
        DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->lockForUpdate()->first();
        foreach ([$uno, $dos] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], [$uno, $dos]);
        $ordenados = $statuses;
        sort($ordenados);
        $this->assertSame([200, 409], $ordenados);
        $this->assertSame($statuses[0] === 200 ? 1 : 0, DB::table('preferencias_paciente')->count());
        $this->assertSame($statuses[1] === 200 ? 1 : 0, DB::table('contactos_emergencia')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_ACTUALIZADO')->count());
        $this->assertFalse(DB::table('pacientes')->where('id_paciente', $f['id_paciente'])->value('activo'));
    }

    #[DataProvider('conflictosEdicionPaciente')]
    public function test_pacientes_parallel_edits_from_same_snapshot_do_not_overwrite_each_other(string $tipo): void
    {
        $datos = $this->vinculacionPreparada(false);
        $id = $datos['id'];
        $cookies = $datos['recepcion'];
        $f = $this->navegador($cookies, 'GET', '/api/pacientes/'.$id)->assertOk()->json('data');
        $uno = $this->worker('', '', mode: 'paciente-editar', extra: ['cookies' => $cookies, 'id_objetivo' => $id,
            'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'telefono' => '71111111']]);
        $dos = $this->worker('', '', mode: $tipo === 'estado' ? 'paciente-estado' : 'paciente-editar', extra: ['cookies' => $cookies, 'id_objetivo' => $id,
            'payload' => ['actualizado_en_original' => $f['actualizado_en'], ...($tipo === 'estado'
                ? ['activo' => true, 'motivo' => 'Rehabilitacion administrativa']
                : ['contacto_emergencia' => ['nombre' => 'Contacto Ficticio', 'parentesco' => 'Familiar', 'telefono' => '72222222']])]]);
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$uno, $dos] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], [$uno, $dos]);
        $ordenados = $statuses;
        sort($ordenados);
        $this->assertSame([200, 409], $ordenados);
        $paciente = DB::table('pacientes')->where('id_paciente', $id)->first();
        $this->assertSame($statuses[0] === 200 ? '71111111' : '70000000', $paciente->telefono);
        if ($tipo === 'estado') {
            $this->assertSame($statuses[1] === 200, $paciente->activo);
        } else {
            $this->assertSame($statuses[1] === 200 ? 1 : 0, DB::table('contactos_emergencia')->count());
        }
        $this->assertSame(1, DB::table('bitacora')->whereIn('tipo_evento', ['PACIENTE_ACTUALIZADO', 'PACIENTE_ESTADO_CAMBIADO'])->count());
    }

    public function test_pacientes_ci_correction_and_historical_confirmation_preserve_single_identity(): void
    {
        $datos = $this->vinculacionPreparada();
        $cookies = $datos['recepcion'];
        $f = $this->navegador($cookies, 'GET', '/api/pacientes/'.$datos['id'])->assertOk()->json('data');
        $editar = $this->worker('', '', mode: 'paciente-editar', extra: ['cookies' => $cookies, 'id_objetivo' => $datos['id'],
            'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'documento_identidad' => 'CI-CORREGIDO',
                'identidad_verificada' => true, 'motivo' => 'Correccion presencial']]);
        $confirmar = $this->worker('', '', mode: 'registro-verificar', extra: $datos);
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$editar, $confirmar] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $edicion = json_decode($this->line($editar), true)['status'];
        $confirmacion = json_decode($this->line($confirmar), true)['status'];
        $this->assertContains([$edicion, $confirmacion], [[200, 422], [409, 204]]);
        $this->assertSame(1, DB::table('pacientes')->count());
        $paciente = DB::table('pacientes')->sole();
        $this->assertSame($datos['id'], $paciente->id_paciente);
        $this->assertFalse($paciente->activo);
        $this->assertSame($edicion === 200 ? 'CI-CORREGIDO' : 'CI-HISTORICO', $paciente->documento_identidad);
        $this->assertSame($confirmacion === 204, $paciente->id_usuario !== null);
        $this->assertSame($confirmacion === 204 ? 2 : 1, DB::table('usuarios')->count());
        $this->assertNotNull($edicion === 200 ? DB::table('codigos_activacion_paciente')->sole()->invalidado_en : DB::table('codigos_activacion_paciente')->sole()->consumido_en);
    }

    public function test_pacientes_two_fiches_cannot_correct_to_same_ci(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $cookies = $datos['recepcion'];
        $otra = $this->navegador($cookies, 'POST', '/api/pacientes', ['documento_identidad' => 'CI-OTRO', 'nombres' => 'Otro',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'])->assertCreated()->json('data');
        $original = $this->navegador($cookies, 'GET', '/api/pacientes/'.$datos['id'])->assertOk()->json('data');
        $workers = [];
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$original, $otra] as $f) {
            $worker = $this->worker('', '', mode: 'paciente-editar', extra: ['cookies' => $cookies, 'id_objetivo' => $f['id_paciente'],
                'payload' => ['actualizado_en_original' => $f['actualizado_en'], 'documento_identidad' => 'CI-DESTINO',
                    'identidad_verificada' => true, 'motivo' => 'Correccion comprobada']]);
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
            $workers[] = $worker;
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('pacientes')->where('documento_identidad', 'CI-DESTINO')->count());
        $this->assertSame(2, DB::table('pacientes')->count());
    }

    public function test_parallel_password_errors_do_not_lose_increments_and_block_at_three(): void
    {
        $usuario = $this->usuarioAuth();
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        $workers = [];
        for ($i = 0; $i < 3; $i++) {
            $workers[] = $this->worker($usuario->correo_acceso, 'incorrecta', '192.0.2.'.($i + 40));
        }
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(3, $usuario->fresh()->intentos_fallidos);
        $this->assertNotNull($usuario->fresh()->bloqueado_hasta);
        $this->assertSame(3, DB::table('bitacora')->where('resultado', 'DENEGADO')->count());
    }

    public function test_login_revalidates_after_waiting_for_deactivation_and_revocation(): void
    {
        $usuario = $this->usuarioAuth();
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey());
        DB::commit();
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_framework_late_session_save_does_not_recreate_a_revoked_authenticated_session(): void
    {
        $usuario = $this->usuarioAuth();
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        // Login ya confirmó su sesión; StartSession todavía no guardó la respuesta.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        DB::transaction(function () use ($usuario): void {
            DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
            $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey());
        });
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(200, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_concurrent_requests_share_an_atomic_limit_in_postgresql(): void
    {
        $usuario = $this->usuarioAuth();
        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $workers[] = $this->worker($usuario->correo_acceso, 'incorrecta');
        }
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([401, 401, 401, 401, 401, 429], $statuses);
        $this->assertSame(3, $usuario->fresh()->intentos_fallidos);
        $this->assertSame([5, 6], DB::table('controles_intentos')->orderBy('cantidad_intentos')->pluck('cantidad_intentos')->all());
    }

    public function test_successful_and_failed_concurrent_login_have_a_serial_account_result(): void
    {
        $usuario = $this->usuarioAuth();
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        $correcto = $this->worker($usuario->correo_acceso, self::PASSWORD, '192.0.2.80');
        $incorrecto = $this->worker($usuario->correo_acceso, 'incorrecta', '192.0.2.81');
        foreach ([$correcto, $incorrecto] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $this->assertSame(200, json_decode($this->line($correcto), true)['status']);
        $this->assertSame(401, json_decode($this->line($incorrecto), true)['status']);
        // El contador refleja el orden de serialización real; no se pierden escrituras.
        $this->assertContains($usuario->fresh()->intentos_fallidos, [0, 1]);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
        $this->assertSame(1, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_concurrent_password_changes_allow_only_one_original_session_to_commit(): void
    {
        $usuario = $this->usuarioAuth();
        $primero = $this->csrfAuth();
        $segundo = $this->csrfAuth();
        $this->loginAuth($primero, $usuario)->assertOk();
        $this->loginAuth($segundo, $usuario)->assertOk();
        $nueva = 'Nueva frase ficticia concurrente 456!';
        $workers = [];
        foreach ([$primero, $segundo] as $cookies) {
            $workers[] = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'password', extra: compact('cookies', 'nueva'));
        }
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([204, 401], $statuses);
        $this->assertTrue(Hash::check($nueva, $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'AUTENTICACION_CAMBIO_CLAVE')->where('resultado', 'EXITO')->count());
    }

    public static function cambiosConcurrentes(): array
    {
        return [['revocacion'], ['estado'], ['rol'], ['obligatoria'], ['hash']];
    }

    #[DataProvider('cambiosConcurrentes')]
    public function test_account_or_session_changes_after_route_validation_are_rechecked_under_lock(string $cambio): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $nueva = 'Nueva frase ficticia concurrente 456!';
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'password-before', extra: compact('cookies', 'nueva'));
        $this->signal($worker, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($worker));
        DB::transaction(function () use ($usuario, $cambio): void {
            DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
            match ($cambio) {
                'revocacion' => $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey()),
                'estado' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'rol' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['id_rol' => DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol')]),
                'obligatoria' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['cambio_clave_obligatorio' => true]),
                'hash' => DB::table('usuarios')->where('id_usuario', $usuario->getKey())->update(['password_hash' => Hash::make('Otra clave ficticia concurrente 789')]),
            };
        });
        $hashVigente = $usuario->fresh()->password_hash;
        $this->signal($worker, 'CONTINUAR');
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame($hashVigente, $usuario->fresh()->password_hash);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_password_change_revokes_a_login_waiting_for_the_framework_late_save(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $nueva = 'Nueva frase ficticia concurrente 456!';
        $this->navegador($cookies, 'PUT', '/api/auth/password', [
            'current_password' => self::PASSWORD, 'password' => $nueva, 'password_confirmation' => $nueva,
        ])->assertNoContent();
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(200, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
        $this->assertSame(1, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_a_late_save_of_the_renewed_session_cannot_undo_a_later_revocation(): void
    {
        $usuario = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $nueva = 'Nueva frase ficticia concurrente 456!';
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'password-pause', extra: compact('cookies', 'nueva'));
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $this->assertTrue(Hash::check($nueva, $usuario->fresh()->password_hash));
        DB::transaction(function () use ($usuario): void {
            DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
            $this->app->make(SesionRepository::class)->revocarUsuario($usuario->getKey());
        });
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(204, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    private function invitacionPendiente(): array
    {
        $usuario = $this->usuarioAuth('Recepcionista', ['estado_cuenta' => 'Pendiente', 'password_hash' => null, 'correo_verificado_en' => null]);
        $token = bin2hex(random_bytes(32));
        DB::table('tokens_acceso')->insert([
            'id_usuario' => $usuario->getKey(), 'proposito' => 'InvitacionPersonal', 'token_hash' => hash_hmac('sha256', $token, config('app.key')),
            'emitido_en' => now()->subMinutes(2)->toIso8601String(), 'expira_en' => now()->addHours(24)->toIso8601String(),
        ]);

        return [$usuario, $token];
    }

    public function test_two_independent_requests_cannot_consume_the_same_invitation(): void
    {
        [$usuario, $token] = $this->invitacionPendiente();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $cookies = $this->csrfAuth();
            $workers[] = $this->worker('', self::PASSWORD, mode: 'invitacion-aceptar', extra: compact('cookies', 'token'));
        }
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([204, 422], $statuses);
        $this->assertSame('Activa', $usuario->fresh()->estado_cuenta);
        $this->assertTrue(Hash::check(self::PASSWORD, $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'INVITACION_ACEPTADA')->where('resultado', 'EXITO')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_competing_staff_creations_share_email_uniqueness_without_partial_tokens(): void
    {
        DB::table('roles')->insert(['nombre' => 'Recepcionista']);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $admin = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $admin)->assertOk();
            $payload = ['correo_acceso' => 'personal-concurrente@example.test', 'rol' => 'Recepcionista', 'nombres_personal' => 'Persona', 'apellidos_personal' => 'Ficticia'];
            $workers[] = $this->worker('', '', mode: 'personal-crear', extra: compact('cookies', 'payload'));
        }
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(3, DB::table('usuarios')->count());
        $this->assertSame(1, DB::table('tokens_acceso')->count());
    }

    public function test_competing_creations_cannot_bind_one_doctor_to_two_staff_accounts(): void
    {
        DB::table('roles')->insert(['nombre' => 'Médico']);
        $idMedico = DB::table('medicos')->insertGetId([
            'codigo_medico' => 'MED-FICTICIO', 'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Profesional',
            'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'medico@example.test',
        ], 'id_medico');
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $admin = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $admin)->assertOk();
            $payload = ['correo_acceso' => 'medico-concurrente'.$i.'@example.test', 'rol' => 'Médico', 'id_medico' => $idMedico];
            $workers[] = $this->worker('', '', mode: 'personal-crear', extra: compact('cookies', 'payload'));
        }
        DB::beginTransaction();
        DB::table('medicos')->where('id_medico', $idMedico)->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(3, DB::table('usuarios')->count());
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->assertNotNull(DB::table('medicos')->where('id_medico', $idMedico)->value('id_usuario'));
    }

    public function test_resend_and_acceptance_serialize_on_the_same_pending_account(): void
    {
        $admin = $this->usuarioAuth();
        $adminCookies = $this->csrfAuth();
        $this->loginAuth($adminCookies, $admin)->assertOk();
        [$usuario, $token] = $this->invitacionPendiente();
        $cookies = $this->csrfAuth();
        $aceptar = $this->worker('', self::PASSWORD, mode: 'invitacion-aceptar', extra: compact('cookies', 'token'));
        $reenviar = $this->worker('', '', mode: 'personal-reenviar', extra: ['cookies' => $adminCookies, 'id_objetivo' => (string) $usuario->getKey()]);
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$aceptar, $reenviar] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statusAceptar = json_decode($this->line($aceptar), true)['status'];
        $statusReenviar = json_decode($this->line($reenviar), true)['status'];
        $this->assertContains([$statusAceptar, $statusReenviar], [[204, 409], [422, 200]]);
        if ($statusAceptar === 204) {
            $this->assertSame('Activa', $usuario->fresh()->estado_cuenta);
            $this->assertSame(1, DB::table('tokens_acceso')->count());
        } else {
            $this->assertSame('Pendiente', $usuario->fresh()->estado_cuenta);
            $this->assertSame(1, DB::table('tokens_acceso')->whereNull('invalidado_en')->whereNull('consumido_en')->count());
        }
    }

    public static function retirosAdministrativos(): array
    {
        return [['estado', 'estado'], ['rol', 'rol'], ['estado', 'rol']];
    }

    #[DataProvider('retirosAdministrativos')]
    public function test_two_administrators_cannot_simultaneously_remove_the_last_normal_accesses(string $primera, string $segunda): void
    {
        DB::table('roles')->insert(['nombre' => 'Recepcionista']);
        $workers = [];
        foreach ([$primera, $segunda] as $operacion) {
            $admin = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $admin)->assertOk();
            $payload = $operacion === 'estado' ? ['estado_cuenta' => 'Desactivada'] : ['rol' => 'Recepcionista'];
            $workers[] = $this->worker('', '', mode: 'cuenta-'.$operacion, extra: ['cookies' => $cookies, 'id_objetivo' => (string) $admin->getKey(), 'payload' => $payload]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertTrue($this->app->make(UsuarioRepository::class)->existeAdministradorNormal());
        $this->assertSame(1, DB::table('usuarios')->join('roles', 'usuarios.id_rol', '=', 'roles.id_rol')
            ->where('roles.nombre', 'Administrador')->where('estado_cuenta', 'Activa')->count());
        $this->assertSame(1, DB::table('bitacora')->whereIn('tipo_evento', ['CUENTA_DESACTIVADA', 'CUENTA_ROL_CAMBIADO'])->where('resultado', 'EXITO')->count());
        $this->assertSame(1, DB::table('bitacora')->where('causa_codigo', 'ULTIMO_ADMINISTRADOR')->where('resultado', 'DENEGADO')->count());
    }

    public function test_cross_deactivation_revalidates_the_waiting_actor_after_the_global_lock(): void
    {
        $primero = $this->usuarioAuth();
        $segundo = $this->usuarioAuth();
        $workers = [];
        foreach ([[$primero, $segundo], [$segundo, $primero]] as [$actor, $objetivo]) {
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $workers[] = $this->worker('', '', mode: 'cuenta-estado', extra: ['cookies' => $cookies,
                'id_objetivo' => (string) $objetivo->getKey(), 'payload' => ['estado_cuenta' => 'Desactivada']]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 401], $statuses);
        $this->assertSame(1, DB::table('usuarios')->where('estado_cuenta', 'Activa')->count());
        $desactivado = DB::table('usuarios')->where('estado_cuenta', 'Desactivada')->value('id_usuario');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $desactivado)->count());
    }

    public function test_revoked_actor_waiting_for_administrative_coordination_cannot_modify_another_account(): void
    {
        $admin = $this->usuarioAuth();
        $this->usuarioAuth();
        $objetivo = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $worker = $this->worker('', '', mode: 'cuenta-estado', extra: ['cookies' => $cookies,
            'id_objetivo' => (string) $objetivo->getKey(), 'payload' => ['estado_cuenta' => 'Desactivada']]);
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('usuarios')->where('id_usuario', $admin->getKey())->lockForUpdate()->first();
        $this->app->make(SesionRepository::class)->revocarUsuario($admin->getKey());
        DB::commit();
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame('Activa', $objetivo->fresh()->estado_cuenta);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->getKey())->count());
    }

    public function test_late_login_response_cannot_restore_session_after_administrative_deactivation(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $objetivo = $this->usuarioAuth('Recepcionista');
        $worker = $this->worker($objetivo->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$objetivo->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(200, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $objetivo->getKey())->count());
    }

    public function test_concurrent_professional_role_assignments_cannot_share_one_medical_identity(): void
    {
        DB::table('roles')->insert(['nombre' => 'Médico']);
        $idMedico = DB::table('medicos')->insertGetId(['codigo_medico' => 'MED-FICTICIO', 'registro_profesional' => 'REG-FICTICIO',
            'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'telefono' => '70000000', 'correo_contacto' => 'profesional@example.test'], 'id_medico');
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $admin = $this->usuarioAuth();
            $objetivo = $this->usuarioAuth('Recepcionista');
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $admin)->assertOk();
            $workers[] = $this->worker('', '', mode: 'cuenta-rol', extra: ['cookies' => $cookies,
                'id_objetivo' => (string) $objetivo->getKey(), 'payload' => ['rol' => 'Médico', 'id_medico' => $idMedico, 'identidad_verificada' => true]]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('usuarios')->join('roles', 'usuarios.id_rol', '=', 'roles.id_rol')->where('roles.nombre', 'Médico')->count());
        $this->assertNotNull(DB::table('medicos')->where('id_medico', $idMedico)->value('id_usuario'));
    }

    private function recuperacionPendiente(): array
    {
        $usuario = $this->usuarioAuth('Recepcionista');
        $token = bin2hex(random_bytes(32));
        DB::table('tokens_acceso')->insert(['id_usuario' => $usuario->getKey(), 'proposito' => 'RecuperacionClave',
            'token_hash' => hash_hmac('sha256', $token, config('app.key')), 'emitido_en' => now('UTC')->subMinute()->toIso8601String(),
            'expira_en' => now('UTC')->addMinutes(59)->toIso8601String()]);

        return [$usuario, $token];
    }

    public function test_parallel_recovery_consumers_cannot_change_password_twice_with_one_token(): void
    {
        [$usuario, $token] = $this->recuperacionPendiente();
        $nueva = 'Nueva clave de recuperacion 456!';
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $cookies = $this->csrfAuth();
            $workers[] = $this->worker('', $nueva, mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
        }
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = json_decode($this->line($worker), true)['status'];
        }
        sort($statuses);
        $this->assertSame([204, 422], $statuses);
        $this->assertTrue(Hash::check($nueva, $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_COMPLETADA')->where('resultado', 'EXITO')->count());
    }

    public function test_parallel_recovery_emissions_keep_only_one_current_token(): void
    {
        $usuario = $this->usuarioAuth();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $cookies = $this->csrfAuth();
            $workers[] = $this->worker($usuario->correo_acceso, '', mode: 'recuperacion-solicitar', extra: compact('cookies'));
        }
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(202, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(2, DB::table('tokens_acceso')->count());
        $this->assertSame(1, DB::table('tokens_acceso')->whereNull('invalidado_en')->whereNull('consumido_en')->count());
        $this->assertSame(2, DB::table('bitacora')->where('tipo_evento', 'RECUPERACION_ENVIO')->where('resultado', 'EXITO')->count());
    }

    public function test_recovery_reissuance_and_consumption_are_serialized_by_account(): void
    {
        [$usuario, $token] = $this->recuperacionPendiente();
        $cookies = $this->csrfAuth();
        $reset = $this->worker('', 'Nueva clave recuperada 456', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
        $cookies = $this->csrfAuth();
        $emision = $this->worker($usuario->correo_acceso, '', mode: 'recuperacion-solicitar', extra: compact('cookies'));
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$reset, $emision] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $status = json_decode($this->line($reset), true)['status'];
        $this->assertContains($status, [204, 422]);
        $this->assertSame(202, json_decode($this->line($emision), true)['status']);
        $this->assertSame(2, DB::table('tokens_acceso')->count());
        $this->assertSame(1, DB::table('tokens_acceso')->whereNull('invalidado_en')->whereNull('consumido_en')->count());
        $this->assertTrue(Hash::check($status === 204 ? 'Nueva clave recuperada 456' : self::PASSWORD, $usuario->fresh()->password_hash));
    }

    public function test_recovery_and_normal_password_change_cannot_both_confirm_from_original_credentials(): void
    {
        [$usuario, $token] = $this->recuperacionPendiente();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $cambio = $this->worker('', self::PASSWORD, mode: 'password', extra: ['cookies' => $cookies, 'nueva' => 'Clave ordinaria nueva 123!']);
        $cookies = $this->csrfAuth();
        $reset = $this->worker('', 'Clave recuperada nueva 456!', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$cambio, $reset] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statusCambio = json_decode($this->line($cambio), true)['status'];
        $statusReset = json_decode($this->line($reset), true)['status'];
        $this->assertContains([$statusCambio, $statusReset], [[204, 422], [401, 204]]);
        $this->assertTrue(Hash::check($statusCambio === 204 ? 'Clave ordinaria nueva 123!' : 'Clave recuperada nueva 456!', $usuario->fresh()->password_hash));
        $this->assertSame($statusCambio === 204 ? 1 : 0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_recovery_and_administrative_deactivation_never_reactivate_or_preserve_target_sessions(): void
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        [$usuario, $token] = $this->recuperacionPendiente();
        $desactivar = $this->worker('', '', mode: 'cuenta-estado', extra: ['cookies' => $cookies,
            'id_objetivo' => (string) $usuario->getKey(), 'payload' => ['estado_cuenta' => 'Desactivada']]);
        $cookies = $this->csrfAuth();
        $reset = $this->worker('', 'Clave recuperada nueva 456!', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$desactivar, $reset] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $this->assertSame(200, json_decode($this->line($desactivar), true)['status']);
        $status = json_decode($this->line($reset), true)['status'];
        $this->assertContains($status, [204, 422]);
        $this->assertSame('Desactivada', $usuario->fresh()->estado_cuenta);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_late_login_response_cannot_restore_a_session_revoked_by_recovery(): void
    {
        [$usuario, $token] = $this->recuperacionPendiente();
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/password/reset', ['token' => $token,
            'password' => 'Clave recuperada nueva 456!', 'password_confirmation' => 'Clave recuperada nueva 456!'])->assertNoContent();
        $this->signal($worker, 'CONTINUAR');
        $resultado = json_decode($this->line($worker), true);
        $this->assertSame(200, $resultado['status']);
        $this->assertSame(401, $resultado['me_status']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    private function adminTemporal(): array
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();

        return [$admin, $cookies];
    }

    private function emisionTemporal(array &$cookies, int|string $id): string
    {
        return $this->navegador($cookies, 'POST', '/api/usuarios/'.$id.'/credencial-temporal', ['identidad_verificada' => true])
            ->assertCreated()->json('data.credencial_temporal');
    }

    private function temporalPendiente(): array
    {
        $usuario = $this->usuarioAuth('Recepcionista', ['cambio_clave_obligatorio' => true]);
        $secreto = CredencialTemporal::generar();
        DB::table('tokens_acceso')->insert(['id_usuario' => $usuario->getKey(), 'proposito' => 'CredencialTemporal',
            'token_hash' => CredencialTemporal::resumen($secreto), 'emitido_en' => now('UTC')->subMinutes(2), 'expira_en' => now('UTC')->addMinutes(28)]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();

        return [$usuario, $cookies, $secreto];
    }

    public function test_parallel_temporary_emissions_serialize_limits_and_preserve_a_single_current_secret(): void
    {
        [, $cookies] = $this->adminTemporal();
        $usuario = $this->usuarioAuth('Recepcionista');
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', '', mode: 'temporal-emitir', extra: ['cookies' => $cookies, 'id_objetivo' => $usuario->getKey()]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([201, 429], $statuses);
        $this->assertSame(1, DB::table('tokens_acceso')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CREDENCIAL_TEMPORAL_EMITIDA')->where('resultado', 'EXITO')->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public static function retirosConTemporal(): array
    {
        return [['temporal'], ['estado'], ['rol']];
    }

    #[DataProvider('retirosConTemporal')]
    public function test_temporary_reset_competing_with_another_administrative_removal_preserves_last_admin(string $operacion): void
    {
        DB::table('roles')->insert(['nombre' => 'Recepcionista']);
        $workers = [];
        foreach (['temporal', $operacion] as $accion) {
            [$admin, $cookies] = $this->adminTemporal();
            $workers[] = $this->worker('', '', mode: $accion === 'temporal' ? 'temporal-emitir' : 'cuenta-'.$accion,
                extra: ['cookies' => $cookies, 'id_objetivo' => $admin->getKey(),
                    'payload' => $accion === 'estado' ? ['estado_cuenta' => 'Desactivada'] : ['rol' => 'Recepcionista']]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertContains($statuses, [[201, 409], [200, 409]]);
        $this->assertTrue($this->app->make(UsuarioRepository::class)->existeAdministradorNormal());
        $this->assertSame(1, DB::table('usuarios')->join('roles', 'usuarios.id_rol', '=', 'roles.id_rol')
            ->where('roles.nombre', 'Administrador')->where('estado_cuenta', 'Activa')->where('cambio_clave_obligatorio', false)->count());
    }

    public function test_cross_temporary_resets_revalidate_the_waiting_actor_and_do_not_deadlock(): void
    {
        [$primero, $cookiesPrimero] = $this->adminTemporal();
        [$segundo, $cookiesSegundo] = $this->adminTemporal();
        $workers = [];
        foreach ([[$cookiesPrimero, $segundo], [$cookiesSegundo, $primero]] as [$cookies, $objetivo]) {
            $workers[] = $this->worker('', '', mode: 'temporal-emitir', extra: ['cookies' => $cookies, 'id_objetivo' => $objetivo->getKey()]);
        }
        DB::beginTransaction();
        $this->app->make(UsuarioRepository::class)->coordinarAdministracion();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([201, 401], $statuses);
        $this->assertTrue($this->app->make(UsuarioRepository::class)->existeAdministradorNormal());
        $this->assertSame(1, DB::table('tokens_acceso')->count());
    }

    public function test_two_requests_cannot_complete_the_same_restricted_session_twice(): void
    {
        [$usuario, $cookies] = $this->temporalPendiente();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', 'Clave definitiva nueva 123!', mode: 'temporal-completar', extra: compact('cookies'));
        }
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([204, 401], $statuses);
        $this->assertFalse($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertTrue(Hash::check('Clave definitiva nueva 123!', $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CREDENCIAL_TEMPORAL_COMPLETADA')->where('resultado', 'EXITO')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_completion_and_reissue_serialize_without_restoring_a_revoked_restricted_session(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        [$usuario, $cookies] = $this->temporalPendiente();
        $completar = $this->worker('', 'Clave definitiva nueva 123!', mode: 'temporal-completar', extra: compact('cookies'));
        $emitir = $this->worker('', '', mode: 'temporal-emitir', extra: ['cookies' => $adminCookies, 'id_objetivo' => $usuario->getKey()]);
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$completar, $emitir] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $status = json_decode($this->line($completar), true)['status'];
        $this->assertContains($status, [204, 401]);
        $this->assertSame(201, json_decode($this->line($emitir), true)['status']);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertTrue(Hash::check($status === 204 ? 'Clave definitiva nueva 123!' : self::PASSWORD, $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNull('consumido_en')->whereNull('invalidado_en')->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_ordinary_password_change_already_validated_cannot_bypass_a_later_temporary_reset(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        $usuario = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $worker = $this->worker('', self::PASSWORD, mode: 'password-before', extra: ['cookies' => $cookies, 'nueva' => 'Clave ordinaria nueva 123!']);
        $this->signal($worker, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($worker));
        $this->emisionTemporal($adminCookies, $usuario->getKey());
        $this->signal($worker, 'CONTINUAR');
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
    }

    public function test_email_recovery_cannot_clear_a_concurrent_temporary_obligation(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        [$usuario, $token] = $this->recuperacionPendiente();
        $cookies = $this->csrfAuth();
        $reset = $this->worker('', 'Clave recuperada nueva 456!', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
        $emitir = $this->worker('', '', mode: 'temporal-emitir', extra: ['cookies' => $adminCookies, 'id_objetivo' => $usuario->getKey()]);
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $usuario->getKey())->lockForUpdate()->first();
        foreach ([$reset, $emitir] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $status = json_decode($this->line($reset), true)['status'];
        $this->assertContains($status, [204, 422]);
        $this->assertSame(201, json_decode($this->line($emitir), true)['status']);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertTrue(Hash::check($status === 204 ? 'Clave recuperada nueva 456!' : self::PASSWORD, $usuario->fresh()->password_hash));
        $recuperacion = DB::table('tokens_acceso')->where('proposito', 'RecuperacionClave')->sole();
        $this->assertTrue($recuperacion->consumido_en !== null || $recuperacion->invalidado_en !== null);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public static function revocacionesTemporales(): array
    {
        return [['estado'], ['rol'], ['reemision'], ['logout'], ['expiracion']];
    }

    #[DataProvider('revocacionesTemporales')]
    public function test_restricted_completion_rechecks_after_route_validation_under_the_account_lock(string $cambio): void
    {
        [, $adminCookies] = $this->adminTemporal();
        [$usuario, $cookies] = $this->temporalPendiente();
        $worker = $this->worker('', 'Clave definitiva nueva 123!', mode: 'temporal-completar-before', extra: compact('cookies'));
        $this->signal($worker, 'INICIAR');
        $this->assertSame('VALIDADA', $this->line($worker));
        match ($cambio) {
            'estado' => $this->navegador($adminCookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk(),
            'rol' => $this->navegador($adminCookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Administrador'])->assertOk(),
            'reemision' => $this->emisionTemporal($adminCookies, $usuario->getKey()),
            'logout' => $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent(),
            // Simula el paso del reloj entre middleware y Service sin esperar 30 minutos.
            'expiracion' => DB::table('tokens_acceso')->where('id_usuario', $usuario->getKey())->update(['expira_en' => now('UTC')->subSecond()]),
        };
        $this->signal($worker, 'CONTINUAR');
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertTrue($usuario->fresh()->cambio_clave_obligatorio);
        $this->assertSame(0, DB::table('tokens_acceso')->whereNotNull('consumido_en')->count());
        // Si venció dentro de la transacción fallida, su fila antigua puede permanecer hasta la siguiente lectura;
        // el token vencido impide usarla y la comprobación fuera del rollback la elimina.
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_temporary_reset_revokes_a_normal_login_waiting_for_its_late_save(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        $usuario = $this->usuarioAuth('Recepcionista');
        $worker = $this->worker($usuario->correo_acceso, self::PASSWORD, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $this->emisionTemporal($adminCookies, $usuario->getKey());
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(200, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_a_late_restricted_login_response_cannot_restore_a_second_device(): void
    {
        [$usuario, $cookies, $secreto] = $this->temporalPendiente();
        $worker = $this->worker($usuario->correo_acceso, $secreto, mode: 'login-pause');
        $this->signal($worker, 'INICIAR');
        $this->assertSame('GUARDADA', $this->line($worker));
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $secreto)->assertOk();
        $this->signal($worker, 'CONTINUAR');
        $result = json_decode($this->line($worker), true);
        $this->assertSame(200, $result['status']);
        $this->assertSame(401, $result['me_status']);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->navegador($cookies, 'PUT', '/api/auth/password/temporal', ['password' => 'Clave definitiva nueva 123!',
            'password_confirmation' => 'Clave definitiva nueva 123!'])->assertNoContent();
    }

    private function datosRegistro(): array
    {
        return ['documento_identidad' => 'CI-FICTICIO-001', 'correo_acceso' => 'paciente@example.test',
            'nombres' => 'Paciente', 'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    private function registroPendiente(): array
    {
        DB::table('roles')->insert(['nombre' => 'Paciente']);
        Mail::fake();
        config(['seguridad.correo.mailer' => 'array']);
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'POST', '/api/auth/registro', $this->datosRegistro())->assertStatus(202);
        $codigo = Mail::sent(VerificacionPacienteMail::class)->last()->codigo;

        return [$cookies, $codigo];
    }

    public function test_parallel_new_patient_registrations_cannot_reserve_the_same_document_or_email_twice(): void
    {
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $cookies = $this->csrfAuth();
            $workers[] = $this->worker('', '', mode: 'registro-iniciar', extra: ['cookies' => $cookies, 'payload' => $this->datosRegistro()]);
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(202, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('pacientes')->count());
    }

    public function test_parallel_patient_verifications_create_only_one_linked_account_and_one_patient(): void
    {
        [$cookies, $codigo] = $this->registroPendiente();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', '', mode: 'registro-verificar', extra: compact('cookies', 'codigo'));
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([204, 422], $statuses);
        $usuario = DB::table('usuarios')->sole();
        $this->assertSame($usuario->id_usuario, DB::table('pacientes')->sole()->id_usuario);
        $this->assertSame(1, DB::table('verificaciones_correo')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'REGISTRO_PACIENTE_ACTIVADO')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_parallel_wrong_email_codes_preserve_all_five_errors_without_exceeding_the_limit(): void
    {
        [$cookies, $correcto] = $this->registroPendiente();
        $codigo = $correcto === '000000' ? '111111' : '000000';
        $workers = [];
        for ($i = 0; $i < 5; $i++) {
            $workers[] = $this->worker('', '', ip: '192.0.2.'.(100 + $i), mode: 'registro-verificar', extra: compact('cookies', 'codigo'));
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
        }
        $this->blocked($workers[0]);
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(422, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(5, DB::table('verificaciones_correo')->sole()->intentos_fallidos);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->invalidado_en);
        $this->assertSame(0, DB::table('usuarios')->count());
    }

    public function test_patient_resend_and_confirmation_serialize_code_replacement_and_activation(): void
    {
        [$cookies, $codigo] = $this->registroPendiente();
        DB::table('verificaciones_correo')->update(['enviado_en' => now('UTC')->subMinutes(2)]);
        $verificar = $this->worker('', '', mode: 'registro-verificar', extra: compact('cookies', 'codigo'));
        $reenviar = $this->worker('', '', mode: 'registro-reenviar', extra: compact('cookies'));
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$verificar, $reenviar] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $status = json_decode($this->line($verificar), true)['status'];
        $this->assertContains($status, [204, 422]);
        $this->assertSame(202, json_decode($this->line($reenviar), true)['status']);
        $this->assertSame($status === 204 ? 1 : 0, DB::table('pacientes')->count());
        $this->assertSame($status === 204 ? 1 : 0, DB::table('usuarios')->count());
        $this->assertSame($status === 204 ? 1 : 2, DB::table('verificaciones_correo')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_unique_document_constraint_rolls_back_account_when_reception_commits_a_competing_patient(): void
    {
        [$cookies, $codigo] = $this->registroPendiente();
        $worker = $this->worker('', '', mode: 'registro-verificar', extra: compact('cookies', 'codigo'));
        DB::beginTransaction();
        $id = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'PAC-RECEPCION-FICTICIO', 'documento_identidad' => 'CI-FICTICIO-001',
            'nombres' => 'Paciente', 'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::commit();
        $this->assertSame(422, json_decode($this->line($worker), true)['status']);
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame($id, DB::table('pacientes')->sole()->id_paciente);
        $this->assertNull(DB::table('pacientes')->sole()->id_usuario);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->completado_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
    }

    private function vinculacionPreparada(bool $iniciar = true): array
    {
        DB::table('roles')->insert(['nombre' => 'Paciente']);
        Mail::fake();
        config(['seguridad.correo.mailer' => 'array']);
        $recepcion = $this->csrfAuth();
        $actor = $this->usuarioAuth('Recepcionista');
        $this->loginAuth($recepcion, $actor)->assertOk();
        $id = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'PAC-HISTORICO', 'documento_identidad' => 'CI-HISTORICO',
            'nombres' => 'Paciente', 'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'activo' => false], 'id_paciente');
        $principal = $this->navegador($recepcion, 'POST', '/api/pacientes/'.$id.'/codigo-activacion', ['identidad_verificada' => true])
            ->assertCreated()->json('data.codigo_activacion');
        $cookies = $this->csrfAuth();
        $payload = ['documento_identidad' => 'CI-HISTORICO', 'codigo_activacion' => $principal, 'correo_acceso' => 'historico@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $codigo = null;
        if ($iniciar) {
            $this->navegador($cookies, 'POST', '/api/auth/registro/vincular', $payload)->assertStatus(202);
            $codigo = Mail::sent(VerificacionPacienteMail::class)->last()->codigo;
        }

        return compact('id', 'actor', 'recepcion', 'principal', 'cookies', 'payload', 'codigo');
    }

    public function test_parallel_historical_reservations_cannot_take_the_same_principal_with_different_contexts(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', '', mode: 'registro-vincular', extra: ['cookies' => $this->csrfAuth(),
                'payload' => [...$datos['payload'], 'correo_acceso' => 'historico'.$i.'@example.test']]);
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(202, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        $this->assertNull(DB::table('pacientes')->sole()->id_usuario);
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
    }

    public function test_parallel_historical_confirmation_consumes_both_codes_once_without_new_patient(): void
    {
        $datos = $this->vinculacionPreparada();
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', '', mode: 'registro-verificar', extra: $datos);
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $statuses = array_map(fn ($worker) => json_decode($this->line($worker), true)['status'], $workers);
        sort($statuses);
        $this->assertSame([204, 422], $statuses);
        $this->assertSame(1, DB::table('pacientes')->count());
        $this->assertSame(2, DB::table('usuarios')->count());
        $this->assertFalse(DB::table('pacientes')->sole()->activo);
        $this->assertNotNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNotNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PACIENTE_CUENTA_VINCULADA')->count());
    }

    public function test_reception_reissue_and_historical_confirmation_are_atomic_in_either_order(): void
    {
        $datos = $this->vinculacionPreparada();
        $confirmar = $this->worker('', '', mode: 'registro-verificar', extra: $datos);
        $emitir = $this->worker('', '', mode: 'registro-emitir', extra: ['cookies' => $datos['recepcion'], 'id_objetivo' => $datos['id']]);
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$confirmar, $emitir] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        $status = json_decode($this->line($confirmar), true)['status'];
        $otro = json_decode($this->line($emitir), true)['status'];
        $this->assertContains([$status, $otro], [[204, 409], [422, 201]]);
        $this->assertSame($status === 204 ? 2 : 1, DB::table('usuarios')->count());
        $this->assertSame($status === 204 ? 1 : 0, DB::table('codigos_activacion_paciente')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('pacientes')->count());
    }

    public function test_parallel_reception_code_errors_do_not_lose_increments_or_exceed_five(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $payload = [...$datos['payload'], 'codigo_activacion' => $datos['principal'] === str_repeat('0', 12) ? str_repeat('1', 12) : str_repeat('0', 12)];
        $workers = [];
        for ($i = 0; $i < 5; $i++) {
            $workers[] = $this->worker('', '', ip: '192.0.2.'.(120 + $i), mode: 'registro-vincular', extra: ['cookies' => $this->csrfAuth(), 'payload' => $payload]);
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
        }
        $this->blocked($workers[0]);
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(202, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(5, DB::table('codigos_activacion_paciente')->sole()->intentos_fallidos);
        $this->assertNotNull(DB::table('codigos_activacion_paciente')->sole()->invalidado_en);
        $this->assertSame(0, DB::table('registros_paciente_pendientes')->count());
    }

    public function test_new_and_historical_requests_serialize_reservation_of_the_same_email(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $nuevo = $this->worker('', '', mode: 'registro-iniciar', extra: ['cookies' => $this->csrfAuth(),
            'payload' => [...$this->datosRegistro(), 'correo_acceso' => 'historico@example.test']]);
        $historico = $this->worker('', '', mode: 'registro-vincular', extra: $datos);
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ([$nuevo, $historico] as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ([$nuevo, $historico] as $worker) {
            $this->assertSame(202, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(1, DB::table('registros_paciente_pendientes')->count());
        $this->assertSame(1, DB::table('verificaciones_correo')->count());
        $this->assertSame(1, DB::table('pacientes')->count());
    }

    public function test_code_issuance_revalidates_reception_after_waiting_for_revocation(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $worker = $this->worker('', '', mode: 'registro-emitir', extra: ['cookies' => $datos['recepcion'], 'id_objetivo' => $datos['id']]);
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::table('usuarios')->where('id_usuario', $datos['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->app->make(SesionRepository::class)->revocarUsuario($datos['actor']->getKey());
        DB::commit();
        $this->assertSame(401, json_decode($this->line($worker), true)['status']);
        $this->assertSame(1, DB::table('codigos_activacion_paciente')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->invalidado_en);
    }

    public function test_historical_link_rolls_back_both_codes_when_external_email_insert_wins_unique_constraint(): void
    {
        $datos = $this->vinculacionPreparada();
        $worker = $this->worker('', '', mode: 'registro-verificar', extra: $datos);
        DB::beginTransaction();
        DB::table('usuarios')->insert(['id_rol' => $datos['actor']->id_rol, 'correo_acceso' => 'historico@example.test',
            'nombres_personal' => 'Personal', 'apellidos_personal' => 'Ficticio']);
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::commit();
        $this->assertSame(422, json_decode($this->line($worker), true)['status']);
        $this->assertNull(DB::table('pacientes')->sole()->id_usuario);
        $this->assertNull(DB::table('codigos_activacion_paciente')->sole()->consumido_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->completado_en);
        $this->assertSame(2, DB::table('usuarios')->count());
    }

    public function test_parallel_reception_emissions_leave_only_latest_principal_usable(): void
    {
        $datos = $this->vinculacionPreparada(false);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = $this->worker('', '', mode: 'registro-emitir', extra: ['cookies' => $datos['recepcion'], 'id_objetivo' => $datos['id']]);
        }
        DB::beginTransaction();
        $this->app->make(RegistroPacienteRepository::class)->coordinar();
        foreach ($workers as $worker) {
            $this->signal($worker, 'INICIAR');
            $this->blocked($worker);
        }
        DB::commit();
        foreach ($workers as $worker) {
            $this->assertSame(201, json_decode($this->line($worker), true)['status']);
        }
        $this->assertSame(3, DB::table('codigos_activacion_paciente')->count());
        $this->assertSame(1, DB::table('codigos_activacion_paciente')->whereNull('consumido_en')->whereNull('invalidado_en')->count());
        $this->assertNull(DB::table('codigos_activacion_paciente')->orderByDesc('id_codigo_activacion')->first()->invalidado_en);
    }

    public function test_unique_email_constraint_preserves_competing_staff_account_and_rolls_back_patient(): void
    {
        [$cookies, $codigo] = $this->registroPendiente();
        $rol = DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol');
        $worker = $this->worker('', '', mode: 'registro-verificar', extra: compact('cookies', 'codigo'));
        DB::beginTransaction();
        $id = DB::table('usuarios')->insertGetId(['id_rol' => $rol, 'correo_acceso' => 'paciente@example.test',
            'estado_cuenta' => 'Pendiente', 'nombres_personal' => 'Personal', 'apellidos_personal' => 'Ficticio'], 'id_usuario');
        $this->signal($worker, 'INICIAR');
        $this->blocked($worker);
        DB::commit();
        $this->assertSame(422, json_decode($this->line($worker), true)['status']);
        $this->assertSame($id, DB::table('usuarios')->sole()->id_usuario);
        $this->assertSame('Pendiente', DB::table('usuarios')->sole()->estado_cuenta);
        $this->assertSame(0, DB::table('pacientes')->count());
        $this->assertNull(DB::table('registros_paciente_pendientes')->sole()->completado_en);
        $this->assertNull(DB::table('verificaciones_correo')->sole()->consumido_en);
    }
}
