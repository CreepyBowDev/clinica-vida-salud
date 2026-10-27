<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
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

class ReprogramacionCitaTest extends TestCase
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
        DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 20]);
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
            'inicio_at' => '2026-11-01T12:10:01Z', 'fin_at' => '2026-11-01T12:25:01Z',
            'id_propuesta_reserva' => (string) Str::uuid(), 'motivo_breve' => 'SECRETO-ORIGINAL', ...$campos]), 'id_cita');

        return [$cookies, $r, $id, $actor];
    }

    private function gestion(array &$cookies, string $id): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion');
    }

    private function propuesta(array &$cookies, string $id): array
    {
        $vista = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')
            ->assertOk()->json('data');

        return ['fecha' => '2026-11-01', 'hora_inicio' => $vista['turnos'][0]['hora_inicio'],
            'revision_turno' => $vista['turnos'][0]['revision_turno'],
            'version_programacion_original' => $vista['cita_original']['version_programacion'],
            'actualizada_en_original' => $vista['cita_original']['actualizada_en']];
    }

    private function guardar(array &$cookies, string $id, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos);
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
    public function test_reprogramming_preserves_identity_and_records_real_actor_atomically(string $rol, string $estado): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol, ['estado' => $estado]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => now()]);
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        $datos = $this->propuesta($cookies, $id);
        $respuesta = $this->guardar($cookies, $id, $datos)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id_cita', $id)->assertJsonPath('data.estado', 'Programada')
            ->assertJsonPath('data.version_programacion', 2)->assertJsonPath('data.duracion_minutos', 20)
            ->assertJsonPath('data.inicio_at', '2026-11-01T12:00:01.000000Z')
            ->assertJsonPath('data.fin_at', '2026-11-01T12:20:01.000000Z')
            ->assertJsonPath('data.ultimo_cambio.id_actor', (string) $actor->getKey());
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach (['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'creada_por', 'creada_en',
            'id_propuesta_reserva', 'motivo_breve', 'modalidad'] as $campo) {
            $this->assertSame($antes[$campo], $despues[$campo]);
        }
        $this->assertTrue(CarbonImmutable::parse($despues['actualizada_en'])->greaterThan(CarbonImmutable::parse($antes['actualizada_en'])));
        $cambio = DB::table('cambios_cita')->where('id_cita', $id)->sole();
        $this->assertSame('REPROGRAMACION', $cambio->operacion);
        $this->assertSame((string) $actor->getKey(), (string) $cambio->id_actor);
        $this->assertSame($estado, $cambio->estado_anterior);
        $this->assertSame(1, json_decode($cambio->datos_anteriores)->version_programacion);
        $this->assertSame(2, json_decode($cambio->datos_nuevos)->version_programacion);
        $aviso = DB::table('recordatorios')->where('id_cita', $id)->sole();
        $this->assertNotNull($aviso->invalidado_en);
        $this->assertNotNull($aviso->leido_en);
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_REPROGRAMADA')->sole();
        $this->assertSame((string) $actor->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        foreach ([$respuesta->getContent(), $cambio->datos_nuevos, $cambio->datos_anteriores, json_encode($evento)] as $salida) {
            $this->assertStringNotContainsString('SECRETO-ORIGINAL', $salida);
            $this->assertStringNotContainsString('revision_turno', $salida);
        }
        $this->gestion($cookies, $id)->assertOk()->assertJsonPath('data.version_programacion', 2)
            ->assertJsonPath('data.ultimo_cambio.operacion', 'REPROGRAMACION');
        $beforeReplay = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->assertSame($beforeReplay, $this->snapshot());
        if ($rol === 'Administrador') {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento->id_evento)->assertOk()
                ->assertJsonPath('data.cambios_seguros.antes.estado', $estado)
                ->assertJsonPath('data.cambios_seguros.despues.version_programacion', 2)
                ->assertJsonPath('data.referencias.id_medico', (string) $r['doctors'][0]);
        }
    }

    public static function finales(): array
    {
        return [['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('finales')]
    public function test_management_reads_final_states_but_writes_reject(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        DB::table('citas')->where('id_cita', $id)->update(['estado' => $estado]);
        $before = $this->snapshot();
        $this->gestion($cookies, $id)->assertOk()->assertJsonPath('data.acciones.reprogramar', false);
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_GESTION_NO_PERMITIDA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function instantes(): array
    {
        return [['2026-10-26T12:09:59.999999Z', 200], ['2026-10-26T12:10:00.000000Z', 409], ['2026-10-26T12:10:00.000001Z', 409]];
    }

    #[DataProvider('instantes')]
    public function test_original_start_deadline_is_strict_in_writer(string $ahora, int $status): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->propuesta($cookies, $id);
        CarbonImmutable::setTestNow($ahora);
        $this->guardar($cookies, $id, $datos)->assertStatus($status);
    }

    public static function consultas(): array
    {
        return [[null], ['2026-10-26T12:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_any_consultation_blocks_writer(?string $inicio): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA', 'iniciada_en' => $inicio]);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        $this->gestion($cookies, $id)->assertOk()->assertJsonPath('data.acciones.reprogramar', false);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_same_start_is_rejected_without_reconfiguring_confirmed_cita(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => 'Confirmada', 'inicio_at' => '2026-11-01T12:00:01Z', 'fin_at' => '2026-11-01T12:15:01Z']);
        $datos = $this->propuesta($cookies, $id);
        $datos['hora_inicio'] = '08:00:01';
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_REPROGRAMACION_SIN_CAMBIO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function obsoletos(): array
    {
        return [['version'], ['timestamp'], ['estado'], ['duracion'], ['ubicacion'], ['horario'], ['revision'], ['turno']];
    }

    #[DataProvider('obsoletos')]
    public function test_stale_originals_or_review_are_not_silently_replaced(string $cambio): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        match ($cambio) {
            'version' => DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2]),
            'timestamp' => DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-10-26T12:00:00.123456Z']),
            'estado' => DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Confirmada']),
            'duracion' => DB::table('configuracion_clinica')->update(['duracion_turno_minutos' => 30]),
            'ubicacion' => DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'Otra etiqueta']),
            'horario' => DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->update(['hora_inicio' => '08:05:01']),
            'revision' => $datos['revision_turno'] = str_repeat('0', 64),
            'turno' => $datos['hora_inicio'] = '08:01:01',
        };
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', in_array($cambio, ['version', 'timestamp'], true)
            ? 'CITA_EDICION_OBSOLETA' : 'CITA_REPROGRAMACION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function recursos(): array
    {
        return [['medicos', 'activo'], ['especialidades', 'activa'], ['medico_especialidad', 'habilitada'], ['consultorios', 'activo']];
    }

    #[DataProvider('recursos')]
    public function test_destination_resources_must_remain_enabled(string $tabla, string $campo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        DB::table($tabla)->update([$campo => false]);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_TURNO_NO_DISPONIBLE');
        $this->assertSame($before, $this->snapshot());
    }

    public static function fallos(): array
    {
        return [['cambio'], ['avisos'], ['auditoria']];
    }

    #[DataProvider('fallos')]
    public function test_failure_rolls_back_cita_history_reminders_and_audit(string $fallo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        [$clase, $metodo] = match ($fallo) {
            'cambio' => [CitaRepository::class, 'agregarCambio'],
            'avisos' => [RecordatorioRepository::class, 'invalidarPorCita'],
            'auditoria' => [BitacoraRepository::class, 'citaReprogramada'],
        };
        $mock = Mockery::mock($clase)->makePartial();
        $mock->shouldReceive($metodo)->once()->andThrow(new RuntimeException('FALLO-INTERNO-NO-EXPONER'));
        $this->app->instance($clase, $mock);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-INTERNO-NO-EXPONER');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidos(): array
    {
        return [[['id_medico' => '1']], [['id_paciente' => '1']], [['id_objetivo' => '1']], [['estado' => 'Confirmada']],
            [['motivo' => 'No permitido']], [['version_programacion_original' => 0]], [['version_programacion_original' => 2147483648]],
            [['actualizada_en_original' => '2026-10-26T12:00:00Z']], [['actualizada_en_original' => '2026-02-30T12:00:00.000000Z']],
            [['revision_turno' => 'alterada']], [['fecha' => '2026-02-30']], [['hora_inicio' => '08:00']]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_inputs_and_canonical_versions(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, [...$datos, ...$extra])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function otrosRoles(): array
    {
        return [['Paciente'], ['Médico']];
    }

    #[DataProvider('otrosRoles')]
    public function test_other_roles_cannot_read_or_write_management(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        $this->gestion($cookies, $id)->assertForbidden();
        $this->guardar($cookies, $id, [])->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(GestionCitaService::class)->consultar($id);
    }

    public function test_canonical_id_query_body_csrf_anonymous_and_revoked_sessions(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $invalido) {
            $this->gestion($cookies, $invalido)->assertUnprocessable();
            $this->guardar($cookies, $invalido, $datos)->assertUnprocessable();
        }
        $this->gestion($cookies, '9223372036854775807')->assertNotFound();
        $this->guardar($cookies, '9223372036854775807', $datos)->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion?fecha=2026-11-01')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion', ['fecha' => '2026-11-01'])->assertUnprocessable();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion?rol=Administrador', $datos)->assertUnprocessable();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos, csrf: false)->assertStatus(419);
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->gestion($cookies, $id)->assertUnauthorized();
        $this->guardar($cookies, $id, $datos)->assertUnauthorized();
        $anonimo = $this->csrfAuth();
        $this->gestion($anonimo, $id)->assertUnauthorized();
        $this->guardar($anonimo, $id, $datos)->assertUnauthorized();
    }

    public function test_occupied_destination_does_not_free_the_original_interval(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-11-02T12:00:01Z', 'fin_at' => '2026-11-02T12:15:01Z']);
        $datos = $this->propuesta($cookies, $id);
        DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => '2026-11-01T12:00:01Z', 'fin_at' => '2026-11-01T12:20:01Z', 'duracion_minutos' => 20]));
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_REPROGRAMACION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_revision_cannot_be_transferred_between_citas_or_actors(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-11-02T12:00:01Z', 'fin_at' => '2026-11-02T12:15:01Z']), 'id_cita');
        $otra = $this->propuesta($cookies, $otro);
        $this->guardar($cookies, $otro, [...$otra, 'revision_turno' => $datos['revision_turno']])->assertConflict();
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->loginAuth($cookies, $this->usuarioAuth('Recepcionista'))->assertOk();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_REPROGRAMACION_OBSOLETA');
    }

    public function test_current_room_is_applied_only_after_review_and_old_reminders_are_preserved(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $r['rooms'][1]]);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1,
            'generado_en' => now(), 'invalidado_en' => '2026-10-25T12:00:00Z']);
        $datos = $this->propuesta($cookies, $id);
        $this->guardar($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_consultorio', (string) $r['rooms'][1]);
        $this->assertSame(1, DB::table('recordatorios')->where('id_cita', $id)->count());
        $this->assertTrue(CarbonImmutable::parse(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'))
            ->equalTo(CarbonImmutable::parse('2026-10-25T12:00:00Z')));
    }

    public function test_future_stored_timestamp_is_advanced_by_one_microsecond(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['actualizada_en' => '2026-10-27T12:00:00.123456Z']);
        $datos = $this->propuesta($cookies, $id);
        $this->guardar($cookies, $id, $datos)->assertOk()->assertJsonPath('data.actualizada_en', '2026-10-27T12:00:00.123457Z');
    }

    public static function cambiosTrasEspera(): array
    {
        return [['cuenta', 401], ['consulta', 409], ['plazo', 409]];
    }

    #[DataProvider('cambiosTrasEspera')]
    public function test_waiting_for_planning_rechecks_security_and_eligibility(string $cambio, int $status): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->propuesta($cookies, $id);
        $plan = Mockery::mock(PlanificacionRepository::class)->makePartial();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($cambio, $actor, $id): void {
            match ($cambio) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-ESPERA']),
                'plazo' => CarbonImmutable::setTestNow('2026-10-26T12:10:00Z'),
            };
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
    }

    public static function cambiosFinales(): array
    {
        return [['consulta', 'CITA_CON_ATENCION_DOCUMENTADA'], ['plazo', 'CITA_PLAZO_GESTION_FINALIZADO'],
            ['version', 'CITA_EDICION_OBSOLETA']];
    }

    #[DataProvider('cambiosFinales')]
    public function test_writer_revalidates_again_after_availability_returns(string $cambio, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->propuesta($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $llamadas = 0;
        $repo->shouldReceive('porId')->with($id, true)->twice()->andReturnUsing(function () use (&$llamadas, $cambio, $id) {
            if (++$llamadas === 2) {
                match ($cambio) {
                    'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FINAL']),
                    'plazo' => CarbonImmutable::setTestNow('2026-10-26T12:10:00Z'),
                    'version' => DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2]),
                };
            }

            return (new CitaRepository)->porId($id, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }

    public static function exclusiones(): array
    {
        return [['medico', 'CITA_TURNO_NO_DISPONIBLE'], ['sala', 'CITA_TURNO_NO_DISPONIBLE'], ['paciente', 'CITA_PACIENTE_SOLAPADO']];
    }

    #[DataProvider('exclusiones')]
    public function test_sql_exclusions_translate_conflicts_and_roll_back(string $recurso, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-11-02T12:00:01Z', 'fin_at' => '2026-11-02T12:15:01Z']);
        $datos = $this->propuesta($cookies, $id);
        $otraFicha = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'OTRA-FICTICIA', 'nombres' => 'Otro',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('guardar')->once()->andReturnUsing(function ($cita) use ($r, $otraFicha, $recurso): void {
            // Escritor SQL simulado después del cálculo; no acredita concurrencia multiproceso.
            DB::table('citas')->insert($this->citaRow($r, [
                'id_medico' => $recurso === 'medico' ? $r['doctors'][0] : $r['doctors'][1],
                'id_consultorio' => $recurso === 'sala' ? $r['rooms'][0] : $r['rooms'][1],
                'id_paciente' => $recurso === 'paciente' ? $r['patient'] : $otraFicha,
                'inicio_at' => '2026-11-01T12:00:01Z', 'fin_at' => '2026-11-01T12:20:01Z', 'duracion_minutos' => 20]));
            (new CitaRepository)->guardar($cita);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failure_after_audit_insert_also_rolls_back_every_business_effect(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->propuesta($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('ultimoCambio')->once()->andThrow(new RuntimeException('FALLO-POST-AUDITORIA'));
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-POST-AUDITORIA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_original_deadline_is_rechecked_after_the_last_consultation_query(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['inicio_at' => '2026-10-26T12:10:00Z', 'fin_at' => '2026-10-26T12:25:00Z']);
        $datos = $this->propuesta($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $lecturas = 0;
        $repo->shouldReceive('tieneConsulta')->with($id)->times(4)->andReturnUsing(function () use (&$lecturas): bool {
            if (++$lecturas === 4) {
                CarbonImmutable::setTestNow('2026-10-26T12:10:00Z');
            }

            return false;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->guardar($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_PLAZO_GESTION_FINALIZADO');
        $this->assertSame($before, $this->snapshot());
    }
}
