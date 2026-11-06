<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\SimulacionPagoService;
use Clinica\data\models\ObligacionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class PagosSimulacionTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;
    private string $path;
    private string $o;
    private string $cita;
    private $usuario;
    private array $cookies;
    private array $resources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_simulado_'.strtolower(Str::random(16));
        $this->path = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        DB::table('configuracion_clinica')->insert(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'pagos.simulacion_habilitada' => true,
            'pagos.incorporacion_habilitada' => true, 'pagos.stripe.habilitado' => false, 'pagos.paypal.habilitado' => false]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
        Http::fake(fn () => throw new \RuntimeException('La simulación no puede usar HTTP externo.'));
        $this->resources = $r = $this->citaResources();
        $this->usuario = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $this->usuario->getKey()]);
        $this->cita = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['incorporada_finanzas' => true]), 'id_cita');
        $this->o = (string) Str::uuid();
        DB::table('obligaciones_pago')->insert(['id_obligacion' => $this->o, 'id_cita' => $this->cita,
            'centavos_bob' => 704, 'centavos_usd' => 101, 'emitida_por' => $r['actor'],
            'emitida_en' => '2026-11-01T10:00:00Z', 'vencimiento_at' => '2026-11-01T12:00:00Z']);
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $this->usuario)->assertOk();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            CarbonImmutable::setTestNow();
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->path]);
            if (isset($this->schema)) { DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE'); }
        } finally { parent::tearDown(); }
    }

    private function detalle(): \Illuminate\Testing\TestResponse
    {
        return $this->navegador($this->cookies, 'GET', '/api/pagos/obligaciones/'.$this->o);
    }

    private function iniciar(string $modalidad = 'stripe', string $escenario = 'completado', ?string $clave = null, ?string $revision = null)
    {
        return $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.$this->o.'/simulacion',
            ['modalidad' => $modalidad, 'escenario' => $escenario, 'revision' => $revision ?? $this->detalle()->assertOk()->json('data.simulacion.revision')],
            ['Idempotency-Key' => $clave ?? (string) Str::uuid()]);
    }

    private function accion(string $i, string $accion, ?string $clave = null)
    {
        return $this->postVacio($this->cookies, '/api/pagos/simulacion/intentos/'.$i.'/'.$accion, $clave);
    }

    private function postVacio(array &$cookies, string $url, ?string $clave = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetScopedInstances();

        return $this->call('POST', $url, [], $cookies, [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Origin' => 'http://localhost:5173',
            'X-XSRF-TOKEN' => $cookies['XSRF-TOKEN'], 'Idempotency-Key' => $clave ?? (string) Str::uuid()]), '{}');
    }

    public function test_payment_is_durable_shared_with_admin_and_does_not_change_assistance(): void
    {
        $key = (string) Str::uuid();
        $revision = $this->detalle()->assertOk()->json('data.simulacion.revision');
        $i = $this->iniciar(clave: $key, revision: $revision)->assertCreated()->json('data.id');
        $this->iniciar(clave: $key, revision: $revision)->assertOk()->assertJsonPath('data.id', $i);
        $this->iniciar('paypal', clave: $key, revision: $revision)->assertConflict();
        $this->detalle()->assertOk()->assertJsonPath('data.liquidada', false);
        $confirmacion = (string) Str::uuid();
        $this->accion($i, 'confirmar', $confirmacion)->assertOk()->assertJsonPath('data.liquidada', true);
        $this->accion($i, 'confirmar', $confirmacion)->assertOk()->assertJsonPath('data.repetida', true);
        $this->accion($i, 'cerrar', $confirmacion)->assertConflict();
        // Nueva sesión de servidor: el estado no procede de un objeto conservado en el cliente.
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $this->usuario)->assertOk();
        $this->navegador($cookies, 'GET', '/api/pagos/obligaciones/'.$this->o)->assertOk()
            ->assertJsonPath('data.liquidada', true)->assertJsonPath('data.origen_liquidacion', 'Simulado')
            ->assertJsonPath('data.intentos.data.0.origen', 'Simulado')->assertJsonPath('data.intentos.data.0.modalidad', 'stripe');
        $admin = $this->usuarioAuth(); $cookies = $this->csrfAuth(); $this->loginAuth($cookies, $admin)->assertOk();
        $this->navegador($cookies, 'GET', '/api/pagos/obligaciones/'.$this->o.'?id_paciente='.$this->resources['patient'])
            ->assertOk()->assertJsonPath('data.liquidada', true)->assertJsonPath('data.origen_liquidacion', 'Simulado')
            ->assertJsonPath('data.simulacion.puede_iniciar', false);
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame('Programada', DB::table('citas')->value('estado'));
        $this->assertSame(0, DB::table('eventos_pago')->count());
        $this->assertSame(0, DB::table('operaciones_pago')->whereNotNull('clave_externa')->count());
        $this->assertStringStartsWith('SIM-', DB::table('cobros_pago')->value('referencia_cobro'));
    }

    public function test_paypal_requires_approval_then_explicit_capture_and_replay_is_safe(): void
    {
        $i = $this->iniciar('paypal')->assertCreated()->json('data.id');
        $this->accion($i, 'capturar')->assertConflict()->assertJsonPath('error.code', 'PAGO_APROBACION_REQUERIDA');
        $this->accion($i, 'confirmar')->assertConflict();
        $this->accion($i, 'aprobar')->assertOk()->assertJsonPath('data.liquidada', false);
        $this->detalle()->assertOk()->assertJsonPath('data.intento_actual.aprobada', true)->assertJsonPath('data.liquidada', false);
        $key = (string) Str::uuid();
        $this->accion($i, 'capturar', $key)->assertOk()->assertJsonPath('data.liquidada', true);
        $this->accion($i, 'capturar', $key)->assertOk();
        $this->assertSame(1, DB::table('cobros_pago')->count());
    }

    public function test_pending_can_be_recovered_and_closed_without_creating_a_payment(): void
    {
        $i = $this->iniciar(escenario: 'pendiente')->assertCreated()->json('data.id');
        $this->accion($i, 'confirmar')->assertOk()->assertJsonPath('data.liquidada', false);
        CarbonImmutable::setTestNow('2026-11-01T12:00:01Z');
        $this->detalle()->assertOk()->assertJsonPath('data.intento_actual.escenario', 'pendiente')->assertJsonPath('data.simulacion.puede_iniciar', false);
        $this->accion($i, 'cerrar')->assertOk()->assertJsonPath('data.estado', 'Cerrado');
        $this->detalle()->assertOk()->assertJsonPath('data.simulacion.puede_iniciar', true);
        $this->iniciar()->assertCreated();
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public static function roles(): array { return [['Administrador'], ['Recepcionista'], ['Médico'], ['Paciente']]; }

    #[DataProvider('roles')]
    public function test_roles_and_foreign_patient_cannot_operate(string $rol): void
    {
        $i = $this->iniciar()->assertCreated()->json('data.id');
        $other = $this->usuarioAuth($rol); $cookies = $this->csrfAuth(); $this->loginAuth($cookies, $other)->assertOk();
        $status = $rol === 'Paciente' ? 404 : 403;
        $this->postVacio($cookies, '/api/pagos/simulacion/intentos/'.$i.'/confirmar')->assertStatus($status);
        $this->navegador($cookies, 'POST', '/api/pagos/obligaciones/'.$this->o.'/simulacion',
            ['modalidad' => 'stripe', 'escenario' => 'completado', 'revision' => 'ajena'], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus($status);
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public function test_input_csrf_origin_revision_and_environment_barriers(): void
    {
        $rev = $this->detalle()->assertOk()->json('data.simulacion.revision');
        $url = '/api/pagos/obligaciones/'.$this->o.'/simulacion';
        $body = ['modalidad' => 'stripe', 'escenario' => 'completado', 'revision' => $rev];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->navegador($this->cookies, 'POST', $url, $body, $headers, csrf: false)->assertStatus(419);
        $this->navegador($this->cookies, 'POST', $url, $body, [...$headers, 'Origin' => 'https://ajeno.example.test'])->assertForbidden();
        foreach (['estado' => 'Exitoso', 'centavos_usd' => 1, 'tarjeta' => '0000', 'id_paciente' => '9'] as $k => $v) {
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
            $this->navegador($this->cookies, 'POST', $url, [...$body, $k => $v], $headers)->assertUnprocessable();
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
        $this->iniciar(revision: 'falsa')->assertConflict();
        config(['pagos.simulacion_habilitada' => false]);
        $this->iniciar(revision: $rev)->assertStatus(503);
        config(['pagos.simulacion_habilitada' => true]); $this->app['env'] = 'production';
        $this->iniciar(revision: $rev)->assertStatus(503);
        $this->assertSame(0, DB::table('intentos_pago')->count());
    }

    public function test_uncertain_external_attempt_cannot_be_bypassed_and_external_endpoint_rejects_simulation(): void
    {
        $rev = $this->detalle()->assertOk()->json('data.simulacion.revision');
        DB::table('intentos_pago')->insert(['id_intento' => (string) Str::uuid(), 'id_obligacion' => $this->o,
            'iniciado_por' => $this->usuario->getKey(), 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => 'ficticia',
            'estado' => 'Incierto', 'creado_en' => now(), 'actualizado_en' => now()]);
        $this->iniciar(revision: $rev)->assertConflict();
        DB::table('intentos_pago')->update(['estado' => 'Cerrado']);
        $this->iniciar(revision: $rev)->assertConflict(); // etiqueta terminal no acredita cierre externo
    }

    public function test_external_dispatcher_never_routes_simulated_attempt_to_stripe(): void
    {
        $i = $this->iniciar()->assertCreated()->json('data.id');
        $this->postVacio($this->cookies, '/api/pagos/intentos/'.$i.'/resultado')
            ->assertConflict()->assertJsonPath('error.code', 'PAGO_PROVEEDOR_INCOMPATIBLE');
        $this->assertSame(0, DB::table('cobros_pago')->count());
    }

    public function test_cancellation_refunds_only_simulated_payment_and_never_calls_provider(): void
    {
        $i = $this->iniciar()->assertCreated()->json('data.id'); $this->accion($i, 'confirmar')->assertOk();
        $admin = $this->usuarioAuth(); $cookies = $this->csrfAuth(); $this->loginAuth($cookies, $admin)->assertOk();
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$this->cita.'/gestion')->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$this->cita.'/cancelacion',
            ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en'],
                'motivo' => 'Cancelación ficticia'])->assertOk();
        $this->assertSame('Cancelada', DB::table('citas')->value('estado'));
        $this->assertSame('Exitosa', DB::table('devoluciones_pago')->value('estado'));
        $this->assertSame(1, DB::table('devoluciones_pago')->count());
        $this->detalle()->assertOk()->assertJsonPath('data.exigible', false);
        $this->accion($i, 'confirmar')->assertConflict();
    }

    public function test_database_context_is_immutable_and_local_references_are_enforced(): void
    {
        $i = $this->iniciar()->assertCreated()->json('data.id');
        try {
            DB::transaction(fn () => DB::table('intentos_pago')->where('id_intento', $i)->update(['origen' => 'Externo']));
            $this->fail('El origen no puede reclasificarse.');
        } catch (\Illuminate\Database\QueryException $e) { $this->assertSame('23514', $e->errorInfo[0]); }
        $this->accion($i, 'confirmar')->assertOk();
        try {
            DB::transaction(fn () => DB::table('cobros_pago')->update(['referencia_cobro' => 'pi_FALSO']));
            $this->fail('Una referencia simulada no puede parecer evidencia externa.');
        } catch (\Illuminate\Database\QueryException $e) { $this->assertSame('23514', $e->errorInfo[0]); }
    }

    public function test_usual_reservation_with_personnel_price_can_be_paid_without_fixture(): void
    {
        $admin = $this->usuarioAuth(); $cookies = $this->csrfAuth(); $this->loginAuth($cookies, $admin)->assertOk();
        DB::table('horarios_atencion')->insert(['id_medico' => $this->resources['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $s = ['id_medico' => (string) $this->resources['doctors'][0], 'id_especialidad' => (string) $this->resources['specialty'],
            'fecha' => '2026-11-01', 'id_paciente' => (string) $this->resources['patient']];
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($s))->assertOk()->json('data.turnos.0');
        $token = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$s, 'hora_inicio' => $turno['hora_inicio'],
            'revision_turno' => $turno['revision_turno'], 'monto_bob' => '140.00'])->assertOk()->json('data.propuesta');
        $cita = $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $token])->assertCreated();
        $this->o = $cita->json('data.obligacion_id');
        $i = $this->iniciar()->assertCreated()->json('data.id');
        $this->accion($i, 'confirmar')->assertOk()->assertJsonPath('data.liquidada', true);
        $this->assertSame(2000, DB::table('cobros_pago')->where('id_obligacion', $this->o)->value('centavos_usd'));
        $this->assertNotSame('DEMO-CITA-001', $cita->json('data.codigo_cita'));
    }

    public function test_without_obligation_legacy_and_unpriced_cannot_invent_debt(): void
    {
        foreach ([false, true] as $incorporada) {
            $c = DB::table('citas')->insertGetId($this->citaRow($this->resources, ['incorporada_finanzas' => $incorporada,
                'inicio_at' => $incorporada ? '2026-11-03T12:00:00Z' : '2026-11-02T12:00:00Z',
                'fin_at' => $incorporada ? '2026-11-03T12:15:00Z' : '2026-11-02T12:15:00Z']), 'id_cita');
            $this->navegador($this->cookies, 'POST', '/api/pagos/obligaciones/'.Str::uuid().'/simulacion',
                ['modalidad' => 'stripe', 'escenario' => 'completado', 'revision' => 'inexistente'], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
            $this->assertFalse(DB::table('obligaciones_pago')->where('id_cita', $c)->exists());
        }
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
    }

    public static function regularizables(): array { return [['Atendida'], ['Inasistencia'], ['Confirmada'], ['Programada']]; }

    #[DataProvider('regularizables')]
    public function test_overdue_payment_never_changes_assistance(string $estado): void
    {
        DB::table('citas')->where('id_cita', $this->cita)->update(['estado' => $estado]);
        CarbonImmutable::setTestNow('2026-11-02T11:00:00Z');
        $this->cookies = $this->csrfAuth();
        $this->loginAuth($this->cookies, $this->usuario)->assertOk();
        $this->detalle()->assertOk()->assertJsonPath('data.vencida', true);
        $i = $this->iniciar()->assertCreated()->json('data.id');
        $this->accion($i, 'confirmar')->assertOk()->assertJsonPath('data.liquidada', true);
        $this->assertSame($estado, DB::table('citas')->value('estado'));
        $this->assertSame(0, DB::table('devoluciones_pago')->count());
    }

    public function test_two_process_starts_share_exclusion_and_idempotency(): void
    {
        $revision = app(SimulacionPagoService::class)->elegibilidad(ObligacionPago::findOrFail($this->o), (string) $this->usuario->getKey())['revision'];
        $processes = [];
        try {
            foreach ([1, 2] as $_) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/simulacion-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($p); $processes[] = [$p, $pipes];
                fwrite($pipes[0], json_encode(['schema' => $this->schema, 'actor' => (string) $this->usuario->getKey(),
                    'objetivo' => $this->o, 'revision' => $revision, 'clave' => (string) Str::uuid()])."\n");
                fflush($pipes[0]);
            }
            $results = [];
            foreach ($processes as [$p, $pipes]) {
                $line = fgets($pipes[1]);
                $results[] = json_decode($line ?: '{}', true, 32, JSON_THROW_ON_ERROR)['resultado'] ?? 'error';
            }
            sort($results); $this->assertSame(['PAGO_OPERACION_ACTIVA', 'creado'], $results);
            $this->assertSame(1, DB::table('intentos_pago')->count());
        } finally {
            foreach ($processes as [$p, $pipes]) { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($p); }
        }
        $processes = [];
        $clave = (string) Str::uuid();
        $intento = DB::table('intentos_pago')->value('id_intento');
        try {
            foreach ([1, 2] as $_) {
                $p = proc_open([PHP_BINARY, base_path('tests/Fixtures/simulacion-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($p); $processes[] = [$p, $pipes];
                fwrite($pipes[0], json_encode(['schema' => $this->schema, 'actor' => (string) $this->usuario->getKey(),
                    'objetivo' => $intento, 'accion' => 'confirmar', 'clave' => $clave])."\n"); fflush($pipes[0]);
            }
            foreach ($processes as [$p, $pipes]) {
                $r = json_decode(fgets($pipes[1]) ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame('Exitoso', $r['resultado'] ?? 'error');
            }
            $this->assertSame(1, DB::table('cobros_pago')->count());
            $this->assertSame(1, DB::table('operaciones_pago')->where('tipo', 'Confirmar')->count());
        } finally {
            foreach ($processes as [$p, $pipes]) { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($p); }
        }
    }
}
