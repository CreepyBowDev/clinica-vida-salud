<?php

namespace Tests\Feature;

use Clinica\data\repositories\SesionRepository;
use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\utils\CredencialTemporal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class AutenticacionConcurrenteTest extends TestCase
{
    use AutenticacionFixtures;

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
        foreach (['000001_create_roles', '000002_create_controles_intentos', '000003_create_consultorios', '000006_create_usuarios', '000007_create_sessions', '000008_create_tokens_acceso', '000009_create_bitacora', '000010_create_pacientes', '000016_create_medicos'] as $name) {
            $migration = require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php');
            $migration->up();
        }
        (require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php'))->up();
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

    private function line(int $worker): string
    {
        $deadline = microtime(true) + 15;
        $line = '';
        do {
            $chunk = fgets($this->workers[$worker]['pipes'][1]);
            if ($chunk !== false) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    if (str_contains($line, '"status":500')) {
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
        $nueva = 'Nueva frase ficticia concurrente 456';
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
        $nueva = 'Nueva frase ficticia concurrente 456';
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
        $nueva = 'Nueva frase ficticia concurrente 456';
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
        $nueva = 'Nueva frase ficticia concurrente 456';
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
        $nueva = 'Nueva clave de recuperacion 456';
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
        $cambio = $this->worker('', self::PASSWORD, mode: 'password', extra: ['cookies' => $cookies, 'nueva' => 'Clave ordinaria nueva 123']);
        $cookies = $this->csrfAuth();
        $reset = $this->worker('', 'Clave recuperada nueva 456', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
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
        $this->assertTrue(Hash::check($statusCambio === 204 ? 'Clave ordinaria nueva 123' : 'Clave recuperada nueva 456', $usuario->fresh()->password_hash));
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
        $reset = $this->worker('', 'Clave recuperada nueva 456', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
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
            'password' => 'Clave recuperada nueva 456', 'password_confirmation' => 'Clave recuperada nueva 456'])->assertNoContent();
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
            $workers[] = $this->worker('', 'Clave definitiva nueva 123', mode: 'temporal-completar', extra: compact('cookies'));
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
        $this->assertTrue(Hash::check('Clave definitiva nueva 123', $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNotNull('consumido_en')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CREDENCIAL_TEMPORAL_COMPLETADA')->where('resultado', 'EXITO')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_completion_and_reissue_serialize_without_restoring_a_revoked_restricted_session(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        [$usuario, $cookies] = $this->temporalPendiente();
        $completar = $this->worker('', 'Clave definitiva nueva 123', mode: 'temporal-completar', extra: compact('cookies'));
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
        $this->assertTrue(Hash::check($status === 204 ? 'Clave definitiva nueva 123' : self::PASSWORD, $usuario->fresh()->password_hash));
        $this->assertSame(1, DB::table('tokens_acceso')->whereNull('consumido_en')->whereNull('invalidado_en')->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public function test_ordinary_password_change_already_validated_cannot_bypass_a_later_temporary_reset(): void
    {
        [, $adminCookies] = $this->adminTemporal();
        $usuario = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario)->assertOk();
        $worker = $this->worker('', self::PASSWORD, mode: 'password-before', extra: ['cookies' => $cookies, 'nueva' => 'Clave ordinaria nueva 123']);
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
        $reset = $this->worker('', 'Clave recuperada nueva 456', mode: 'recuperacion-reset', extra: compact('cookies', 'token'));
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
        $this->assertTrue(Hash::check($status === 204 ? 'Clave recuperada nueva 456' : self::PASSWORD, $usuario->fresh()->password_hash));
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
        $worker = $this->worker('', 'Clave definitiva nueva 123', mode: 'temporal-completar-before', extra: compact('cookies'));
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
        $this->navegador($cookies, 'PUT', '/api/auth/password/temporal', ['password' => 'Clave definitiva nueva 123',
            'password_confirmation' => 'Clave definitiva nueva 123'])->assertNoContent();
    }
}
