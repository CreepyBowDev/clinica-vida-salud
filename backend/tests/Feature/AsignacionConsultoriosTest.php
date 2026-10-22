<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AsignacionConsultoriosTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-10-21T12:00:00Z');
    }

    private function contexto(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7,
            'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0].'/consultorio')->assertOk()->json('data');

        return [$cookies, $r, $f];
    }

    private function ruta(array $r): string
    {
        return '/api/medicos/'.$r['doctors'][0].'/consultorio';
    }

    private function propuesta(array $r, array $f): array
    {
        return ['id_consultorio' => (string) $r['rooms'][1], 'actualizado_en_original' => $f['actualizado_en']];
    }

    private function impacto(array &$cookies, array $r, array $f, array $extra = []): array
    {
        return $this->navegador($cookies, 'POST', $this->ruta($r).'/impacto', [...$this->propuesta($r, $f), ...$extra])
            ->assertOk()->json('data');
    }

    private function confirmar(array &$cookies, array $r, array $f, array $impacto, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'PUT', $this->ruta($r), [...$this->propuesta($r, $f),
            'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true, ...$extra]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['medicos', 'horarios_atencion', 'citas', 'consultas_medicas', 'cambios_cita', 'recordatorios'] as $tabla) {
            $snapshot[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $snapshot;
    }

    public function test_first_assignment_null_read_and_safe_audit_use_existing_professional_identity(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => null]);
        $f = $this->navegador($cookies, 'GET', $this->ruta($r))->assertOk()->assertJsonPath('data.consultorio', null)->json('data');
        $before = $this->snapshot();
        $eventos = DB::table('bitacora')->count();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertTrue($impacto['puede_confirmar']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($eventos, DB::table('bitacora')->count());
        $result = $this->confirmar($cookies, $r, $f, $impacto, ['confirmar_traslados' => false])->assertOk()
            ->assertJsonPath('data.id_medico', (string) $r['doctors'][0])->assertJsonPath('data.consultorio.id_consultorio', (string) $r['rooms'][1])
            ->assertJsonPath('data.citas_trasladadas', 0)->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertNotSame($f['actualizado_en'], $result['actualizado_en']);
        $this->assertSame(2, DB::table('medicos')->count());
        $event = DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->sole();
        $this->assertSame('CONSULTORIOS', $event->modulo);
        $this->assertSame(['id_medico' => (string) $r['doctors'][0], 'id_consultorio' => (string) $r['rooms'][1]], json_decode($event->referencias, true));
        $this->assertNull(json_decode($event->cambios_seguros, true)['antes']['id_consultorio']);
        $this->assertStringNotContainsString('Consultorio ficticio', json_encode($event));
    }

    public function test_confirmed_future_appointment_moves_only_location_preserving_notices_and_programming(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => 'Confirmada', 'motivo_breve' => 'Contenido clínico ficticio privado']), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1,
            'generado_en' => '2026-10-31T12:00:00Z', 'leido_en' => '2026-10-31T12:01:00Z']);
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        $avisos = DB::table('recordatorios')->get()->toJson();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertTrue($impacto['puede_confirmar']);
        $this->assertSame(1, $impacto['totales']['citas_trasladadas']);
        $this->assertStringNotContainsString('Contenido clínico', json_encode($impacto));
        $this->confirmar($cookies, $r, $f, $impacto, ['confirmar_traslados' => false])->assertConflict()
            ->assertJsonPath('error.code', 'ASIGNACION_CONFIRMACION_REQUERIDA');
        $this->assertSame($antes, (array) DB::table('citas')->where('id_cita', $id)->first());
        $this->confirmar($cookies, $r, $f, $impacto)->assertOk()->assertJsonPath('data.citas_trasladadas', 1);
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        $this->assertSame($r['rooms'][1], $despues['id_consultorio']);
        $destino = DB::table('consultorios')->where('id_consultorio', $r['rooms'][1])->first();
        $this->assertSame($destino->codigo_consultorio.' — '.$destino->nombre, $despues['consultorio_atencion']);
        foreach (['id_consultorio', 'consultorio_atencion', 'actualizada_en'] as $campo) {
            unset($antes[$campo], $despues[$campo]);
        }
        $this->assertSame($antes, $despues);
        $this->assertSame($avisos, DB::table('recordatorios')->get()->toJson());
        $cambio = DB::table('cambios_cita')->sole();
        $this->assertSame('CAMBIO_UBICACION', $cambio->operacion);
        $this->assertSame('Confirmada', $cambio->estado_anterior);
        $this->assertSame('Confirmada', $cambio->estado_nuevo);
        $this->assertSame((string) $r['rooms'][0], json_decode($cambio->datos_anteriores, true)['id_consultorio']);
        // El lector del aviso utiliza Cita, sin snapshots de ubicación en Recordatorio.
        $this->assertSame($r['rooms'][1], DB::table('recordatorios')->join('citas', 'citas.id_cita', '=', 'recordatorios.id_cita')->value('citas.id_consultorio'));
    }

    public function test_pagination_changes_neither_fingerprint_nor_atomic_full_set_of_moves(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        for ($i = 0; $i < 3; $i++) {
            $start = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addWeeks($i);
            DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $start, 'fin_at' => $start->addMinutes(15)]));
        }
        $p1 = $this->impacto($cookies, $r, $f, ['per_page' => 1]);
        $response = $this->navegador($cookies, 'POST', $this->ruta($r).'/impacto', [...$this->propuesta($r, $f), 'per_page' => 1, 'page' => 2])
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)->assertJsonCount(1, 'data.elementos');
        $this->assertSame($p1['huella_impacto'], $response->json('data.huella_impacto'));
        $this->assertSame(3, $p1['totales']['citas_trasladadas']);
        $this->confirmar($cookies, $r, $f, $p1)->assertOk()->assertJsonPath('data.citas_trasladadas', 3);
        $this->assertSame(3, DB::table('citas')->where('id_consultorio', $r['rooms'][1])->count());
        $this->assertSame(3, DB::table('cambios_cita')->count());
    }

    public static function cambioImpacto(): array
    {
        return [['cita'], ['consulta'], ['destino'], ['horario'], ['actor']];
    }

    #[DataProvider('cambioImpacto')]
    public function test_impact_rejects_changes_not_represented_by_professional_version(string $cambio): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $impacto = $this->impacto($cookies, $r, $f);
        if ($cambio === 'cita') {
            DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Confirmada']);
        } elseif ($cambio === 'consulta') {
            DB::table('consultas_medicas')->insert(['codigo_consulta' => 'FICTICIA', 'id_cita' => $id]);
        } elseif ($cambio === 'destino') {
            DB::table('consultorios')->where('id_consultorio', $r['rooms'][1])->update(['nombre' => 'Nombre nuevo']);
        } elseif ($cambio === 'horario') {
            DB::table('horarios_atencion')->where('id_medico', $r['doctors'][0])->update(['hora_fin' => '13:00:00']);
        } else {
            $otro = $this->usuarioAuth();
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $otro)->assertOk();
        }
        $antes = $this->snapshot();
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'ASIGNACION_IMPACTO_OBSOLETO');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_new_appointment_outside_visible_page_invalidates_old_impact(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $impacto = $this->impacto($cookies, $r, $f, ['per_page' => 1, 'page' => 100]);
        DB::table('citas')->insert($this->citaRow($r));
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'ASIGNACION_IMPACTO_OBSOLETO');
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public function test_crossing_start_time_invalidates_eligibility_and_blocks_fresh_confirmation(): void
    {
        CarbonImmutable::setTestNow('2026-11-01T11:59:00Z');
        [$cookies, $r, $f] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r));
        $impacto = $this->impacto($cookies, $r, $f);
        CarbonImmutable::setTestNow('2026-11-01T12:01:00Z');
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'ASIGNACION_IMPACTO_OBSOLETO');
        $nuevo = $this->impacto($cookies, $r, $f);
        $this->assertFalse($nuevo['puede_confirmar']);
        $this->confirmar($cookies, $r, $f, $nuevo)->assertConflict()->assertJsonPath('error.code', 'ATENCION_NO_TRASLADABLE');
    }

    public function test_noop_preserves_versions_events_and_appointment_snapshot_even_after_catalog_rename(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r));
        $r['rooms'][1] = $r['rooms'][0];
        $before = $this->snapshot();
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'Nuevo nombre del catálogo']);
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertSame(0, $impacto['totales']['citas_trasladadas']);
        $this->confirmar($cookies, $r, $f, $impacto)->assertOk()->assertJsonPath('data.actualizado_en', $f['actualizado_en']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->count());
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['actualizado_en' => CarbonImmutable::now('UTC')->addDay()]);
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'MEDICO_EDICION_OBSOLETA');
    }

    public static function actividadCompartida(): array
    {
        return [[true, true, false], [false, true, false], [true, false, true], [false, false, true]];
    }

    #[DataProvider('actividadCompartida')]
    public function test_only_active_schedule_reserves_weekly_room_even_when_its_doctor_is_inactive(bool $medicoActivo, bool $horarioActivo, bool $permitido): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['activo' => $medicoActivo]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7,
            'hora_inicio' => '11:59:59', 'hora_fin' => '13:00:00', 'activo' => $horarioActivo]);
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertSame($permitido, $impacto['puede_confirmar']);
        if ($permitido) {
            $this->confirmar($cookies, $r, $f, $impacto)->assertOk();
        } else {
            $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'PLANIFICACION_CONFLICTO');
        }
    }

    public function test_contiguous_weekly_schedules_are_compatible_and_source_inactivation_changes_no_schedule(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7,
            'hora_inicio' => '12:00:00', 'hora_fin' => '13:00:00']);
        $horarios = DB::table('horarios_atencion')->get()->toJson();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertTrue($impacto['puede_confirmar']);
        $this->confirmar($cookies, $r, $f, $impacto)->assertOk();
        $this->assertSame($horarios, DB::table('horarios_atencion')->get()->toJson());
        $this->assertFalse(DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('activo'));
    }

    public function test_actual_appointment_blocks_room_even_if_its_doctor_is_assigned_elsewhere_and_has_no_schedule(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]));
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0], 'activo' => false]);
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertFalse($impacto['puede_confirmar']);
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict();
        $this->assertSame($r['rooms'][0], DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('id_consultorio'));
    }

    public static function ocupacionDestino(): array
    {
        return [
            ['2026-11-01T11:45:00Z', 15, true],
            ['2026-11-01T16:00:00Z', 15, true],
            ['2026-11-01T15:59:00Z', 15, false],
            ['2026-11-01T03:59:00Z', 482, false],
            ['2026-10-31T12:00:00Z', 12960, false],
        ];
    }

    #[DataProvider('ocupacionDestino')]
    public function test_real_occupancy_uses_half_open_local_intervals_even_across_dates_and_weeks(string $desde, int $minutos, bool $permitido): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $start = CarbonImmutable::parse($desde);
        DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1],
            'inicio_at' => $start, 'fin_at' => $start->addMinutes($minutos), 'duracion_minutos' => $minutos]));
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertSame($permitido, $impacto['puede_confirmar']);
        if ($permitido) {
            $this->confirmar($cookies, $r, $f, $impacto)->assertOk();
        } else {
            $this->confirmar($cookies, $r, $f, $impacto)->assertConflict();
        }
    }

    public function test_appointment_must_fit_full_active_schedule_and_does_not_move_partially(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => '2026-11-01T15:50:00Z', 'fin_at' => '2026-11-01T16:05:00Z']));
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertFalse($impacto['puede_confirmar']);
        $antes = $this->snapshot();
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_documented_consultation_with_unknown_start_blocks_relocation_without_exposing_clinical_data(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        DB::table('consultas_medicas')->insert(['codigo_consulta' => 'FICTICIA', 'id_cita' => $id, 'iniciada_en' => null]);
        $antes = $this->snapshot();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertFalse($impacto['puede_confirmar']);
        $this->assertSame('ATENCION_NO_TRASLADABLE', $impacto['elementos'][0]['codigo']);
        $this->assertArrayNotHasKey('id_consulta', $impacto['elementos'][0]);
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'ATENCION_NO_TRASLADABLE');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_historical_and_final_locations_are_preserved_and_cancelled_future_interval_is_free(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        foreach (['Programada', 'Confirmada', 'Atendida', 'Inasistencia', 'Cancelada'] as $i => $estado) {
            $start = CarbonImmutable::parse('2026-10-01T12:00:00Z')->addDays($i);
            DB::table('citas')->insert($this->citaRow($r, ['estado' => $estado, 'inicio_at' => $start, 'fin_at' => $start->addMinutes(15)]));
        }
        DB::table('citas')->insert($this->citaRow($r, ['estado' => 'Cancelada', 'id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]));
        foreach (['Atendida', 'Inasistencia'] as $i => $estado) {
            $start = CarbonImmutable::parse('2026-11-08T12:00:00Z')->addWeeks($i);
            DB::table('citas')->insert($this->citaRow($r, ['estado' => $estado, 'inicio_at' => $start, 'fin_at' => $start->addMinutes(15)]));
        }
        $antes = DB::table('citas')->get()->toJson();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertTrue($impacto['puede_confirmar']);
        $this->confirmar($cookies, $r, $f, $impacto)->assertOk()->assertJsonPath('data.citas_trasladadas', 0);
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
    }

    public function test_inactive_destination_and_missing_resources_have_safe_results(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][1])->update(['activo' => false]);
        $impacto = $this->impacto($cookies, $r, $f);
        $this->assertFalse($impacto['puede_confirmar']);
        $this->confirmar($cookies, $r, $f, $impacto)->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_NO_DISPONIBLE');
        $this->navegador($cookies, 'POST', $this->ruta($r).'/impacto', [...$this->propuesta($r, $f), 'id_consultorio' => '9223372036854775807'])->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/medicos/9223372036854775807/consultorio')->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/medicos/9223372036854775808/consultorio')->assertUnprocessable();
        $this->navegador($cookies, 'DELETE', $this->ruta($r))->assertStatus(405);
    }

    public function test_audit_failure_after_all_relocations_rolls_back_professional_locations_history_and_event(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        for ($i = 0; $i < 2; $i++) {
            $start = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addWeeks($i);
            DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $start, 'fin_at' => $start->addMinutes(15)]));
        }
        $impacto = $this->impacto($cookies, $r, $f);
        $before = $this->snapshot();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('asignacionConsultorio')->once()->andReturnUsing(function (...$args): void {
            (new BitacoraRepository)->asignacionConsultorio(...$args);
            throw new RuntimeException('Error técnico privado');
        });
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->confirmar($cookies, $r, $f, $impacto)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Error técnico');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CONSULTORIO_CAMBIADO')->count());
    }

    public function test_cu30_projects_assignment_references_and_only_safe_transition_metadata(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->confirmar($cookies, $r, $f, $impacto)->assertOk();
        $list = $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=Consultorio&registro_referido='.$r['rooms'][1])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.referencias.id_medico', (string) $r['doctors'][0]);
        $id = $list->json('data.0.id_evento');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()
            ->assertJsonPath('data.cambios_seguros.antes.id_consultorio', (string) $r['rooms'][0])
            ->assertJsonPath('data.cambios_seguros.despues.id_consultorio', (string) $r['rooms'][1])
            ->assertJsonPath('data.cambios_seguros.citas_trasladadas', 0)->assertDontSee('Consultorio ficticio');
        $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=Medico&registro_referido='.$r['doctors'][0])->assertOk()->assertJsonCount(1, 'data');
    }

    public static function invalidos(): array
    {
        return [['id_consultorio', null], ['id_consultorio', '9223372036854775808'], ['id_consultorio', ['1']],
            ['actualizado_en_original', '2026-02-30T12:00:00.000000Z'], ['huella_impacto', 'incorrecta'],
            ['confirmar_traslados', 'false'], ['id_objetivo', '2'], ['estado', 'Atendida'], ['citas', ['1']]];
    }

    #[DataProvider('invalidos')]
    public function test_confirmation_rejects_invalid_or_unexpected_input(string $field, mixed $value): void
    {
        [$cookies, $r, $f] = $this->contexto();
        $impacto = $this->impacto($cookies, $r, $f);
        $this->confirmar($cookies, $r, $f, $impacto, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');
        $this->assertSame($r['rooms'][0], DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('id_consultorio'));
    }

    public function test_invalid_preview_filters_and_forged_valid_fingerprint_do_not_write(): void
    {
        [$cookies, $r, $f] = $this->contexto();
        foreach ([['per_page' => 101], ['page' => 0], ['orden' => 'nombre']] as $extra) {
            $this->navegador($cookies, 'POST', $this->ruta($r).'/impacto', [...$this->propuesta($r, $f), ...$extra])->assertUnprocessable();
        }
        $this->confirmar($cookies, $r, $f, ['huella_impacto' => str_repeat('0', 64)])->assertConflict()->assertJsonPath('error.code', 'ASIGNACION_IMPACTO_OBSOLETO');
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_non_administrators_cannot_read_preview_or_confirm(string $role): void
    {
        $actor = $this->usuarioAuth($role);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach ([['GET', ''], ['POST', '/impacto'], ['PUT', '']] as [$method, $suffix]) {
            $this->navegador($cookies, $method, '/api/medicos/1/consultorio'.$suffix)->assertForbidden();
        }
    }

    public function test_session_csrf_origin_revocation_and_temporary_password_controls_are_reused(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/medicos/1/consultorio')->assertUnauthorized();
        [$cookies, $r, $f] = $this->contexto();
        $this->navegador($cookies, 'POST', $this->ruta($r).'/impacto', $this->propuesta($r, $f), csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', $this->ruta($r), headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $target = $this->usuarioAuth();
        $secret = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])
            ->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $secret)->assertOk();
        $this->navegador($restricted, 'GET', $this->ruta($r))->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        DB::table('usuarios')->whereNotNull('password_hash')->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'GET', $this->ruta($r))->assertUnauthorized();
    }
}
