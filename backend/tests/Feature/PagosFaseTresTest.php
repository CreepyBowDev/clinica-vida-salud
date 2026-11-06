<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\ObligacionPagoRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class PagosFaseTresTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'pagos.incorporacion_habilitada' => true]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00.123456Z');
    }

    private function contexto(string $rol = 'Administrador'): array
    {
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $actor = $this->usuarioAuth($rol, vincular: false);
        if ($rol === 'Paciente') {
            DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actor->getKey()]);
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $seleccion = ['id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'],
            'fecha' => '2026-11-01', ...($rol === 'Paciente' ? [] : ['id_paciente' => (string) $r['patient']])];
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($seleccion))->assertOk()->json('data.turnos.0');
        $seleccion += ['hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']];

        return compact('r', 'actor', 'cookies', 'seleccion');
    }

    private function propuesta(array &$c, ?string $monto = '70.00'): string
    {
        return $this->navegador($c['cookies'], 'POST', '/api/reservas/propuesta',
            [...$c['seleccion'], ...($monto === null ? [] : ['monto_bob' => $monto])])->assertOk()->json('data.propuesta');
    }

    private function cita(array $c, array $cambios = []): string
    {
        return (string) DB::table('citas')->insertGetId($this->citaRow($c['r'], array_replace([
            'incorporada_finanzas' => true, 'creada_por' => $c['actor']->getKey()], $cambios)), 'id_cita');
    }

    private function monto(array &$cookies, string $id, string $monto = '7.04'): string
    {
        return $this->navegador($cookies, 'POST', '/api/citas/'.$id.'/monto/propuesta', ['monto_bob' => $monto])
            ->assertOk()->json('data.propuesta');
    }

    public static function personal(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('personal')]
    public function test_administrative_reservation_is_reviewed_atomic_and_replayable(string $rol): void
    {
        $c = $this->contexto($rol);
        $token = $this->propuesta($c, '7.04');
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token, 'monto_bob' => '7.00'])->assertUnprocessable();
        $this->navegador($c['cookies'], 'POST', '/api/reservas/resultado', ['propuesta' => $token])->assertOk()->assertJsonPath('data.registrada', false);
        $respuesta = $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertCreated()
            ->assertJsonPath('data.estado', 'Programada')->assertJsonPath('data.incorporada_finanzas', true)
            ->assertJsonPath('data.situacion_precio', 'Asignado')->assertJsonPath('data.precio.centavos_usd', 101);
        $id = $respuesta->json('data.id_cita');
        $obligacion = DB::table('obligaciones_pago')->sole();
        $this->assertSame($respuesta->json('data.obligacion_id'), $obligacion->id_obligacion);
        $this->assertSame((string) $c['actor']->getKey(), (string) $obligacion->emitida_por);
        $this->assertSame(704, $obligacion->centavos_bob);
        $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertOk()->assertJsonPath('meta.repetida', true);
        $this->navegador($c['cookies'], 'POST', '/api/reservas/resultado', ['propuesta' => $token])->assertOk()
            ->assertJsonPath('data.cita.obligacion_id', $obligacion->id_obligacion);
        $this->navegador($c['cookies'], 'GET', '/api/pacientes/'.$c['r']['patient'].'/citas?situacion_precio=asignado')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_cita', $id);
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PAGO_OBLIGACION_EMITIDA')->count());
        foreach (['intentos_pago', 'cobros_pago', 'devoluciones_pago', 'operaciones_pago', 'eventos_pago'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count());
        }
    }

    public function test_own_reservation_rejects_third_party_and_money_and_starts_without_debt(): void
    {
        $c = $this->contexto('Paciente');
        foreach (['monto_bob' => '70.00', 'centavos_usd' => 1000, 'id_paciente' => (string) $c['r']['patient']] as $campo => $valor) {
            $this->navegador($c['cookies'], 'POST', '/api/reservas/propuesta', [...$c['seleccion'], $campo => $valor])->assertUnprocessable();
        }
        $token = $this->propuesta($c, null);
        $id = $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertCreated()
            ->assertJsonPath('data.situacion_precio', 'PendienteAsignacion')->assertJsonPath('data.precio', null)
            ->assertJsonPath('data.puede_asignar_monto', false)->json('data.id_cita');
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->navegador($c['cookies'], 'GET', '/api/mis-citas?situacion_precio=pendiente_asignacion')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto/propuesta', ['monto_bob' => '70'])->assertForbidden();
    }

    public static function invalidos(): array
    {
        return [[7], [7.04], [' 7.00'], ['7.00 '], ['07'], ['7,00'], ['7e1'], ['+7'], ['6.99'], ['7000.01'], ['7.001'], [null], [['monto' => '7']]];
    }

    #[DataProvider('invalidos')]
    public function test_http_rejects_money_before_normalization(mixed $monto): void
    {
        $c = $this->contexto();
        $this->navegador($c['cookies'], 'POST', '/api/reservas/propuesta', [...$c['seleccion'], 'monto_bob' => $monto])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['monto_bob']]]);
        $id = $this->cita($c);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto/propuesta', ['monto_bob' => $monto])->assertUnprocessable();
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
    }

    public function test_activation_versions_pending_proposals_but_never_reclassifies_confirmed_legacy(): void
    {
        config(['pagos.incorporacion_habilitada' => false]);
        $c = $this->contexto();
        $token = $this->propuesta($c, null);
        config(['pagos.incorporacion_habilitada' => true]);
        $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertConflict()
            ->assertJsonPath('error.code', 'CITA_PROPUESTA_OBSOLETA');
        config(['pagos.incorporacion_habilitada' => false]);
        $id = $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertCreated()
            ->assertJsonPath('data.situacion_precio', 'NoIncorporada')->json('data.id_cita');
        config(['pagos.incorporacion_habilitada' => true]);
        $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertOk()
            ->assertJsonPath('meta.repetida', true)->assertJsonPath('data.situacion_precio', 'NoIncorporada');
        $this->navegador($c['cookies'], 'POST', '/api/reservas/resultado', ['propuesta' => $token])->assertOk()
            ->assertJsonPath('data.cita.incorporada_finanzas', false);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto/propuesta', ['monto_bob' => '70'])->assertConflict()
            ->assertJsonPath('error.code', 'PAGO_CITA_NO_INCORPORADA');
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
    }

    public function test_deactivation_prevents_new_incorporation_and_price_emission(): void
    {
        $c = $this->contexto();
        $token = $this->propuesta($c);
        $id = $this->cita($c);
        $precio = $this->monto($c['cookies'], $id);
        config(['pagos.incorporacion_habilitada' => false]);
        $this->navegador($c['cookies'], 'POST', '/api/citas', ['propuesta' => $token])->assertConflict();
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $precio])->assertStatus(503)
            ->assertJsonPath('error.code', 'PAGO_NO_CONFIGURADO');
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $defaults = require base_path('config/pagos.php');
        $this->assertFalse($defaults['incorporacion_habilitada']);
    }

    public static function tardias(): array
    {
        return [['Programada', '7'], ['Atendida', '7000.00'], ['Inasistencia', '7.04']];
    }

    #[DataProvider('tardias')]
    public function test_late_assignment_is_unique_historical_and_regularizable(string $estado, string $monto): void
    {
        $c = $this->contexto('Recepcionista');
        $id = $this->cita($c, ['inicio_at' => '2026-10-31T12:00:00Z', 'fin_at' => '2026-10-31T12:15:00Z', 'estado' => $estado]);
        $token = $this->monto($c['cookies'], $id, $monto);
        $alternativa = $this->monto($c['cookies'], $id, '70');
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token, 'monto_bob' => '14'])->assertUnprocessable();
        $primera = $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertCreated()
            ->assertJsonPath('data.vencida', true)->assertJsonPath('data.exigible', true)->assertJsonPath('data.puede_iniciar_pago', false)
            ->assertJsonPath('data.revision_pago', null)->assertJsonPath('data.cita.estado', $estado)->json('data');
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertOk()->assertJsonPath('meta.repetida', true)
            ->assertJsonPath('data.id', $primera['id']);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $alternativa])->assertConflict()
            ->assertJsonPath('error.code', 'PAGO_PRECIO_YA_ASIGNADO');
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'PAGO_OBLIGACION_EMITIDA')->count());
        $this->assertSame($estado, DB::table('citas')->where('id_cita', $id)->value('estado'));
    }

    public function test_cancellation_prevents_assignment_and_replay_preserves_annulment(): void
    {
        $c = $this->contexto();
        $id = $this->cita($c);
        $token = $this->monto($c['cookies'], $id);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertCreated();
        $gestion = $this->navegador($c['cookies'], 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$id.'/cancelacion', [
            'version_programacion_original' => $gestion['version_programacion'], 'actualizada_en_original' => $gestion['actualizada_en']])->assertOk();
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertOk()
            ->assertJsonPath('data.exigible', false)->assertJsonPath('data.vencida', false);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto/propuesta', ['monto_bob' => '70'])->assertConflict()
            ->assertJsonPath('error.code', 'PAGO_NO_EXIGIBLE');
    }

    public static function fallos(): array
    {
        return [['reserva', 'obligacion'], ['reserva', 'auditoria'], ['asignacion', 'obligacion'], ['asignacion', 'auditoria']];
    }

    #[DataProvider('fallos')]
    public function test_emission_or_audit_failure_rolls_back_every_effect(string $operacion, string $fallo): void
    {
        $c = $this->contexto();
        $id = $operacion === 'asignacion' ? $this->cita($c) : null;
        $token = $id === null ? $this->propuesta($c) : $this->monto($c['cookies'], $id);
        $this->partialMock($fallo === 'obligacion' ? ObligacionPagoRepository::class : BitacoraRepository::class,
            function ($mock) use ($fallo): void {
                $mock->shouldReceive($fallo === 'obligacion' ? 'crear' : 'obligacionEmitida')->once()
                    ->andThrow(new \RuntimeException('Fallo seguro simulado'));
            });
        $this->navegador($c['cookies'], 'POST', $id === null ? '/api/citas' : '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertStatus(500);
        $this->assertSame($id === null ? 0 : 1, DB::table('citas')->count());
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->assertSame(0, DB::table('bitacora')->whereIn('tipo_evento', ['PAGO_OBLIGACION_EMITIDA', 'CITA_PROGRAMADA'])->count());
    }

    public function test_assignment_revalidates_actor_revision_and_cancellation(): void
    {
        $c = $this->contexto();
        $id = $this->cita($c);
        $token = $this->monto($c['cookies'], $id);
        $otro = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $otro)->assertOk();
        $this->navegador($cookies, 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertForbidden();
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token.'x'])->assertUnprocessable();
        DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2]);
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertConflict()
            ->assertJsonPath('error.code', 'PAGO_REVISION_OBSOLETA');
        $token = $this->monto($c['cookies'], $id);
        $gestion = $this->navegador($c['cookies'], 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$id.'/cancelacion', [
            'version_programacion_original' => $gestion['version_programacion'], 'actualizada_en_original' => $gestion['actualizada_en']])->assertOk();
        $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertConflict()
            ->assertJsonPath('error.code', 'PAGO_NO_EXIGIBLE');
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
    }

    public function test_historical_queries_project_only_verified_local_facts_without_creating_operations(): void
    {
        $c = $this->contexto();
        $id = $this->cita($c);
        $token = $this->monto($c['cookies'], $id);
        $uuid = $this->navegador($c['cookies'], 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertCreated()->json('data.id');
        // Fixtures de hechos locales preexistentes; no existe procesamiento de pagos en esta fase.
        foreach (['Exitoso', 'Incierto'] as $estado) {
            $intento = (string) Str::uuid();
            DB::table('intentos_pago')->insert(['id_intento' => $intento, 'id_obligacion' => $uuid,
                'iniciado_por' => $c['actor']->getKey(), 'proveedor' => 'stripe', 'entorno' => 'test',
                'cuenta_contexto' => 'NO-EXPONER-CONTEXTO', 'estado' => $estado, 'creado_en' => now(), 'actualizado_en' => now()]);
            if ($estado === 'Exitoso') {
                DB::table('cobros_pago')->insert(['id_cobro' => (string) Str::uuid(), 'id_intento' => $intento,
                    'id_obligacion' => $uuid, 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => 'NO-EXPONER-CONTEXTO',
                    'referencia_cobro' => 'referencia_ficticia_sin_pago_real', 'centavos_usd' => 101,
                    'aplicado' => true, 'confirmado_en' => now(), 'registrado_en' => now()]);
            }
        }
        $params = '?id_paciente='.$c['r']['patient'];
        $this->navegador($c['cookies'], 'GET', '/api/pagos/historial'.$params.'&resultado=Exitoso&proveedor=stripe&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cobros.0.aplicado', true)
            ->assertDontSee('NO-EXPONER-CONTEXTO')->assertDontSee('captura_permitida');
        $this->navegador($c['cookies'], 'GET', '/api/pagos/obligaciones'.$params.'&liquidada=1&operacion_en_verificacion=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.liquidada', true)
            ->assertJsonPath('data.0.intento_actual.estado', 'Incierto');
        $this->navegador($c['cookies'], 'GET', '/api/pagos/obligaciones'.$params.'&liquidada=0')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($c['cookies'], 'GET', '/api/pagos/obligaciones'.$params.'&operacion_en_verificacion=0')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($c['cookies'], 'GET', '/api/pagos/obligaciones/'.$uuid.$params.'&intentos_page=2')
            ->assertOk()->assertJsonPath('data.intentos.meta.total', 2)->assertJsonCount(0, 'data.intentos.data');
        $this->assertSame(2, DB::table('intentos_pago')->count());
        $this->assertSame(1, DB::table('cobros_pago')->count());
        $this->assertSame(0, DB::table('operaciones_pago')->count());
    }

    public function test_queries_enforce_roles_ownership_privacy_filters_dates_and_pagination(): void
    {
        $c = $this->contexto('Paciente');
        $id = $this->cita($c);
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $token = $this->monto($cookies, $id);
        $uuid = $this->navegador($cookies, 'POST', '/api/citas/'.$id.'/monto', ['propuesta' => $token])->assertCreated()->json('data.id');
        $url = '/api/pagos/obligaciones';
        $this->navegador($cookies, 'GET', $url)->assertUnprocessable();
        $this->navegador($cookies, 'GET', $url.'?id_paciente='.$c['r']['patient'].'&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.puede_iniciar_pago', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->navegador($c['cookies'], 'GET', $url.'?id_paciente='.$c['r']['patient'])->assertUnprocessable();
        $this->navegador($c['cookies'], 'GET', $url.'?fecha_desde=2026-11-01&fecha_hasta=2026-11-01')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($c['cookies'], 'GET', $url.'?page=2&per_page=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1);
        foreach (['sort=precio', 'exigible=true', 'fecha_desde=2026-11-02&fecha_hasta=2026-11-01', 'per_page=101'] as $query) {
            $this->navegador($c['cookies'], 'GET', $url.'?'.$query)->assertUnprocessable();
        }
        $this->navegador($c['cookies'], 'GET', $url, ['estado' => 'Asignado'])->assertUnprocessable();
        $this->navegador($c['cookies'], 'GET', $url.'/'.$uuid)->assertOk()->assertJsonPath('data.intentos.meta.total', 0)
            ->assertJsonPath('data.devoluciones.meta.total', 0)->assertJsonPath('data.revision_pago', null)
            ->assertDontSee('cuenta_contexto')->assertDontSee('password_hash')->assertDontSee('motivo_breve');
        $this->navegador($c['cookies'], 'GET', '/api/pagos/historial')->assertOk()->assertJsonCount(0, 'data');
        CarbonImmutable::setTestNow('2026-11-01T11:59:59.999999Z');
        $this->navegador($c['cookies'], 'GET', $url.'?vencida=1')->assertOk()->assertJsonCount(0, 'data');
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
        $this->navegador($c['cookies'], 'GET', $url.'?vencida=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.vencida', true);
        $this->navegador($c['cookies'], 'GET', $url.'?vencida=0')->assertOk()->assertJsonCount(0, 'data');
        $otro = $this->usuarioAuth('Paciente');
        $ajenas = $this->csrfAuth();
        $this->loginAuth($ajenas, $otro)->assertOk();
        $this->navegador($ajenas, 'GET', $url.'/'.$uuid)->assertNotFound();
        $this->navegador($cookies, 'GET', $url.'/'.$uuid.'?id_paciente='.$otro->paciente->getKey())->assertNotFound();
        foreach (['Recepcionista', 'Médico'] as $rol) {
            $actor = $this->usuarioAuth($rol, vincular: false);
            if ($rol === 'Médico') {
                DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['id_usuario' => $actor->getKey()]);
            }
            $privadas = $this->csrfAuth();
            $this->loginAuth($privadas, $actor)->assertOk();
            $this->navegador($privadas, 'GET', $url)->assertForbidden();
            $this->navegador($privadas, 'GET', '/api/pagos/historial')->assertForbidden();
            $detalle = $this->navegador($privadas, 'GET', '/api/citas/'.$id)->assertOk();
            if ($rol === 'Médico') {
                $detalle->assertDontSee('incorporada_finanzas')->assertDontSee('centavos_bob')->assertDontSee('obligacion_id');
            } else {
                $detalle->assertJsonPath('data.situacion_precio', 'Asignado')->assertDontSee('intentos');
            }
        }
    }
}
