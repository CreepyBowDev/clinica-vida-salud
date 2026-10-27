<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\DisponibilidadService;
use Clinica\business\services\citas\GestionCitaService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class CancelacionCitaTest extends TestCase
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
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 15]);
    }

    private function contexto(string $rol = 'Administrador', array $campos = []): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:01', 'hora_fin' => '09:00:01']);
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'inicio_at' => '2026-11-01T12:00:01Z', 'fin_at' => '2026-11-01T12:15:01Z',
            'version_programacion' => 4, 'actualizada_en' => '2026-10-26T12:00:00.000000Z',
            'id_propuesta_reserva' => (string) Str::uuid(), 'motivo_breve' => 'MOTIVO-ORIGINAL-PRIVADO', ...$campos]), 'id_cita');

        return [$cookies, $r, $id, $actor];
    }

    private function gestion(array &$cookies, string $id): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion');
    }

    private function originales(array &$cookies, string $id): array
    {
        $vista = $this->gestion($cookies, $id)->assertOk()->json('data');

        return ['version_programacion_original' => $vista['version_programacion'],
            'actualizada_en_original' => $vista['actualizada_en']];
    }

    private function cancelar(array &$cookies, string $id, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $datos);
    }

    private function snapshot(): array
    {
        $datos = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'bitacora'] as $tabla) {
            $datos[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $datos;
    }

    public static function operadores(): array
    {
        return [['Administrador', 'Programada'], ['Administrador', 'Confirmada'],
            ['Recepcionista', 'Programada'], ['Recepcionista', 'Confirmada']];
    }

    #[DataProvider('operadores')]
    public function test_cancellation_preserves_history_and_versions_without_deleting(string $rol, string $estado): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol, ['estado' => $estado]);
        $datos = $this->originales($cookies, $id);
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        DB::table('recordatorios')->insert([
            ['id_cita' => $id, 'version_programacion' => 3, 'generado_en' => now(), 'leido_en' => null, 'invalidado_en' => '2026-10-25T12:00:00Z'],
            ['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now(), 'leido_en' => now(), 'invalidado_en' => null],
        ]);
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-11-02T12:00:01Z',
            'fin_at' => '2026-11-02T12:15:01Z']), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $otro, 'version_programacion' => 1, 'generado_en' => now()]);
        $respuesta = $this->cancelar($cookies, $id, [...$datos, 'motivo' => '  MOTIVO-CANCELACION-PRIVADO  '])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.estado', 'Cancelada')->assertJsonPath('data.version_programacion', 4)
            ->assertJsonPath('data.actualizada_en', '2026-10-26T12:00:00.000001Z')
            ->assertJsonPath('data.ultimo_cambio.id_actor', (string) $actor->getKey());
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['estado', 'actualizada_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $cambio = DB::table('cambios_cita')->where('id_cita', $id)->sole();
        $this->assertSame('CANCELACION', $cambio->operacion);
        $this->assertSame('MOTIVO-CANCELACION-PRIVADO', $cambio->motivo);
        $this->assertSame((string) $actor->getKey(), (string) $cambio->id_actor);
        $this->assertSame($estado, $cambio->estado_anterior);
        $this->assertSame('Cancelada', $cambio->estado_nuevo);
        $this->assertSame(4, json_decode($cambio->datos_anteriores)->version_programacion);
        $this->assertSame(4, json_decode($cambio->datos_nuevos)->version_programacion);
        $aviso = DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 4)->sole();
        $this->assertNotNull($aviso->invalidado_en);
        $this->assertNotNull($aviso->leido_en);
        $this->assertTrue(CarbonImmutable::parse(DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 3)->value('invalidado_en'))
            ->equalTo(CarbonImmutable::parse('2026-10-25T12:00:00Z')));
        $this->assertNull(DB::table('recordatorios')->where('id_cita', $otro)->value('invalidado_en'));
        $this->assertSame(3, DB::table('recordatorios')->count());
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_CANCELADA')->sole();
        $this->assertSame((string) $actor->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        foreach ([$respuesta->getContent(), json_encode($evento), $cambio->datos_anteriores, $cambio->datos_nuevos] as $salida) {
            $this->assertStringNotContainsString('MOTIVO-CANCELACION-PRIVADO', $salida);
            $this->assertStringNotContainsString('MOTIVO-ORIGINAL-PRIVADO', $salida);
        }
        $this->gestion($cookies, $id)->assertOk()->assertJsonPath('data.acciones.cancelar', false)
            ->assertJsonPath('data.acciones.reprogramar', false)->assertJsonPath('data.ultimo_cambio.operacion', 'CANCELACION');
        if ($rol === 'Administrador') {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento->id_evento)->assertOk()
                ->assertJsonPath('data.cambios_seguros.antes.estado', $estado)
                ->assertJsonPath('data.cambios_seguros.despues.estado', 'Cancelada')
                ->assertJsonPath('data.cambios_seguros.despues.version_programacion', 4)
                ->assertJsonPath('data.referencias.id_cita', $id)->assertDontSee('MOTIVO-CANCELACION-PRIVADO');
        }
        $beforeReplay = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $actuales = $this->originales($cookies, $id);
        $this->cancelar($cookies, $id, $actuales)->assertConflict()->assertJsonPath('error.code', 'CITA_GESTION_NO_PERMITIDA');
        $this->assertSame($beforeReplay, $this->snapshot());
    }

    public static function motivos(): array
    {
        return [[[], null], [['motivo' => null], null], [['motivo' => ''], null], [['motivo' => '   '], null],
            [['motivo' => str_repeat('á', 500)], str_repeat('á', 500)]];
    }

    #[DataProvider('motivos')]
    public function test_optional_motive_is_normalized_and_private(array $extra, ?string $esperado): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        $this->cancelar($cookies, $id, [...$datos, ...$extra])->assertOk()->assertJsonMissingPath('data.motivo');
        $this->assertSame($esperado, DB::table('cambios_cita')->where('id_cita', $id)->value('motivo'));
    }

    public function test_inactive_resources_no_schedule_and_current_location_do_not_block_cancellation(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => null]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        $this->app->instance(DisponibilidadService::class, Mockery::mock(DisponibilidadService::class));
        $this->gestion($cookies, $id)->assertOk()->assertJsonPath('data.acciones.cancelar', true);
        $this->cancelar($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_consultorio', (string) $r['rooms'][0]);
    }

    public function test_cancelled_cita_frees_the_interval_for_a_new_real_reservation(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $uri = '/api/reservas/disponibilidad?'.http_build_query(['id_medico' => $r['doctors'][0],
            'id_especialidad' => $r['specialty'], 'id_paciente' => $r['patient'], 'fecha' => '2026-11-01']);
        $this->navegador($cookies, 'GET', $uri)->assertOk()->assertJsonPath('meta.total', 3);
        $this->cancelar($cookies, $id, $this->originales($cookies, $id))->assertOk();
        $vista = $this->navegador($cookies, 'GET', $uri)->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $turno = $vista['turnos'][0];
        $propuesta = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [
            'id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'],
            'id_paciente' => (string) $r['patient'], 'fecha' => '2026-11-01',
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']])->assertOk()->json('data.propuesta');
        $this->navegador($cookies, 'POST', '/api/citas', ['propuesta' => $propuesta])->assertCreated()
            ->assertJsonPath('data.inicio_at', '2026-11-01T12:00:01.000000Z');
        $this->assertSame(2, DB::table('citas')->count());
        $this->assertSame('Cancelada', DB::table('citas')->where('id_cita', $id)->value('estado'));
    }

    public static function finales(): array
    {
        return [['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('finales')]
    public function test_final_states_cannot_be_cancelled(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => $estado]);
        $datos = $this->originales($cookies, $id);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_GESTION_NO_PERMITIDA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function consultas(): array
    {
        return [[null], ['2026-10-26T12:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_any_consultation_blocks_cancellation(?string $iniciada): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA', 'iniciada_en' => $iniciada]);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function instantes(): array
    {
        return [['2026-10-26T12:09:59.999999Z', 200], ['2026-10-26T12:10:00.000000Z', 409], ['2026-10-26T12:10:00.000001Z', 409]];
    }

    #[DataProvider('instantes')]
    public function test_strict_server_deadline_with_microseconds(string $ahora, int $status): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->originales($cookies, $id);
        CarbonImmutable::setTestNow($ahora);
        $this->cancelar($cookies, $id, $datos)->assertStatus($status);
    }

    public static function versiones(): array
    {
        return [['version'], ['timestamp']];
    }

    #[DataProvider('versiones')]
    public function test_stale_originals_reject_without_effects(string $campo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        DB::table('citas')->where('id_cita', $id)->update($campo === 'version'
            ? ['version_programacion' => 5] : ['actualizada_en' => '2026-10-26T12:00:00.000001Z']);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidos(): array
    {
        return [[['id_medico' => '1']], [['id_paciente' => '1']], [['id_objetivo' => '1']], [['estado' => 'Cancelada']],
            [['fecha' => '2026-11-01']], [['revision_turno' => str_repeat('0', 64)]], [['motivo' => str_repeat('a', 501)]],
            [['motivo' => ['texto' => 'No permitido']]], [['motivo' => "Texto\nno permitido"]],
            [['version_programacion_original' => 0]], [['version_programacion_original' => 2147483648]],
            [['actualizada_en_original' => '2026-10-26T12:00:00Z']], [['actualizada_en_original' => '2026-02-30T12:00:00.000000Z']]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_inputs_and_canonical_versions(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, [...$datos, ...$extra])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function otrosRoles(): array
    {
        return [['Paciente'], ['Médico']];
    }

    #[DataProvider('otrosRoles')]
    public function test_other_roles_are_denied_even_on_direct_service(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        $this->cancelar($cookies, $id, [])->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(GestionCitaService::class)->cancelar($id, [], []);
    }

    public function test_id_query_csrf_anonymous_and_revocation(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $invalido) {
            $this->cancelar($cookies, $invalido, $datos)->assertUnprocessable();
        }
        $this->cancelar($cookies, '9223372036854775807', $datos)->assertNotFound();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion?rol=Administrador', $datos)->assertUnprocessable();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $datos, csrf: false)->assertStatus(419);
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        // Restaurar cookies/CSRF anónimas antes del PATCH: una sesión eliminada también pierde su token.
        $this->gestion($cookies, $id)->assertUnauthorized();
        $this->cancelar($cookies, $id, $datos)->assertUnauthorized();
        $anonimo = $this->csrfAuth();
        $this->cancelar($anonimo, $id, $datos)->assertUnauthorized();
    }

    public static function fallos(): array
    {
        return [['cambio'], ['avisos'], ['auditoria'], ['despues']];
    }

    #[DataProvider('fallos')]
    public function test_atomic_rollback_even_after_audit_insert(string $fallo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        [$clase, $metodo] = match ($fallo) {
            'cambio' => [CitaRepository::class, 'agregarCambio'],
            'avisos' => [RecordatorioRepository::class, 'invalidarPorCita'],
            'auditoria' => [BitacoraRepository::class, 'citaCancelada'],
            'despues' => [CitaRepository::class, 'ultimoCambio'],
        };
        $mock = Mockery::mock($clase)->makePartial();
        $mock->shouldReceive($metodo)->once()->andThrow(new RuntimeException('FALLO-CANCELACION-PRIVADO'));
        $this->app->instance($clase, $mock);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-CANCELACION-PRIVADO');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_future_timestamp_is_monotonic_and_max_programming_version_does_not_block(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['version_programacion' => 2147483647, 'actualizada_en' => '2026-10-27T12:00:00.123456Z']);
        $datos = $this->originales($cookies, $id);
        $this->cancelar($cookies, $id, $datos)->assertOk()->assertJsonPath('data.version_programacion', 2147483647)
            ->assertJsonPath('data.actualizada_en', '2026-10-27T12:00:00.123457Z');
    }

    public static function cambiosTrasEspera(): array
    {
        return [['cuenta', 401], ['consulta', 409], ['plazo', 409]];
    }

    #[DataProvider('cambiosTrasEspera')]
    public function test_waiting_for_planning_rechecks_eligibility(string $cambio, int $status): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->originales($cookies, $id);
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($cambio, $actor, $id): void {
            match ($cambio) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-ESPERA']),
                'plazo' => CarbonImmutable::setTestNow('2026-10-26T12:10:00Z'),
            };
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_time_is_rechecked_after_consultation_query_waits(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->originales($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('tieneConsulta')->once()->andReturnUsing(function (): bool {
            CarbonImmutable::setTestNow('2026-10-26T12:10:00Z');

            return false;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_PLAZO_GESTION_FINALIZADO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function cambiosBajoLock(): array
    {
        return [['version', 'CITA_EDICION_OBSOLETA'], ['timestamp', 'CITA_EDICION_OBSOLETA'],
            ['estado', 'CITA_GESTION_NO_PERMITIDA'], ['consulta', 'CITA_CON_ATENCION_DOCUMENTADA']];
    }

    #[DataProvider('cambiosBajoLock')]
    public function test_changes_while_acquiring_cita_lock_are_read_again(string $cambio, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->originales($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porId')->with($id, true)->once()->andReturnUsing(function () use ($id, $cambio) {
            match ($cambio) {
                'version' => DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 5]),
                'timestamp' => DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-10-26T12:00:00.123456Z']),
                'estado' => DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Atendida']),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-LOCK']),
            };

            return (new CitaRepository)->porId($id, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->cancelar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }
}
