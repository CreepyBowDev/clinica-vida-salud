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

class HorariosTest extends TestCase
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
        $ruta = '/api/medicos/'.$r['doctors'][0].'/horarios';
        $version = $this->navegador($cookies, 'GET', $ruta)->assertOk()->json('data.actualizado_en');

        return [$cookies, $r, $ruta, $version];
    }

    private function horario(array &$cookies, string $ruta, string $version, array $extra = []): TestResponse
    {
        return $this->navegador($cookies, 'POST', $ruta, ['dia_semana' => 7, 'hora_inicio' => '08:00:00',
            'hora_fin' => '12:00:00', 'actualizado_en_original' => $version, ...$extra]);
    }

    private function configuracion(array &$cookies): array
    {
        return $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
    }

    private function snapshot(): array
    {
        $salida = [];
        foreach (['medicos', 'horarios_atencion', 'configuracion_clinica', 'citas', 'recordatorios', 'cambios_cita'] as $tabla) {
            $salida[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $salida;
    }

    public function test_create_edit_state_versions_pagination_noop_and_safe_audit(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.turnos_completos', 16)->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertNotSame($version, $h['actualizado_en']);
        $this->horario($cookies, $ruta, $version)->assertConflict()->assertJsonPath('error.code', 'MEDICO_EDICION_OBSOLETA');
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_fin' => '12:50:01', 'actualizado_en_original' => $h['actualizado_en']])
            ->assertOk()->assertJsonPath('data.turnos_completos', 19)->json('data');
        $antes = $this->snapshot();
        $eventos = DB::table('bitacora')->count();
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_fin' => '12:50:01', 'actualizado_en_original' => $h['actualizado_en']])
            ->assertOk()->assertJsonPath('data.actualizado_en', $h['actualizado_en']);
        $this->assertSame($antes, $this->snapshot());
        $this->assertSame($eventos, DB::table('bitacora')->count());
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])
            ->assertOk()->assertJsonPath('data.activo', false)->json('data');
        $this->navegador($cookies, 'GET', $ruta.'?activo=1')->assertOk()->assertJsonCount(0, 'data.elementos');
        $this->navegador($cookies, 'GET', $ruta.'?activo=0&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.actualizado_en', $h['actualizado_en']);
        $list = $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=HorarioAtencion&registro_referido='.$h['id_horario'])
            ->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$list[0]['id_evento'])->assertOk()
            ->assertJsonPath('data.referencias.id_medico', (string) $r['doctors'][0])->assertJsonPath('data.cambios_seguros.campos', ['activo']);
    }

    public function test_create_edit_previews_and_list_order_use_server_data_without_writing(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $antes = $this->snapshot();
        $datos = ['accion' => 'CREAR', 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '08:50:00', 'actualizado_en_original' => $version];
        $this->navegador($cookies, 'POST', $ruta.'/impacto', $datos)->assertOk()->assertJsonPath('data.puede_confirmar', true)->assertJsonPath('data.totales.conflictos', 0);
        $this->assertSame($antes, $this->snapshot());
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $this->navegador($cookies, 'POST', $ruta.'/impacto', ['accion' => 'EDITAR', 'id_horario' => $h['id_horario'], 'hora_fin' => '13:00:00', 'actualizado_en_original' => $h['actualizado_en']])
            ->assertOk()->assertJsonPath('data.puede_confirmar', true);
        $this->assertSame('12:00:00', DB::table('horarios_atencion')->value('hora_fin'));
        $h = $this->horario($cookies, $ruta, $h['actualizado_en'], ['dia_semana' => 1])->assertCreated()->json('data');
        $this->navegador($cookies, 'GET', $ruta.'?per_page=1')->assertOk()->assertJsonPath('data.elementos.0.dia_semana', 1)->assertJsonPath('meta.total', 2);
        $this->navegador($cookies, 'GET', $ruta.'?per_page=1&page=2')->assertOk()->assertJsonPath('data.elementos.0.dia_semana', 7);
    }

    public function test_preview_is_readonly_complete_and_paginated_and_uses_same_rules_as_write(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        foreach (['2026-11-01', '2026-11-08', '2026-11-15'] as $fecha) {
            DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $fecha.'T12:00:00Z', 'fin_at' => $fecha.'T12:15:00Z']));
        }
        $antes = $this->snapshot();
        $datos = ['accion' => 'ESTADO', 'id_horario' => $h['id_horario'], 'activo' => false, 'actualizado_en_original' => $h['actualizado_en']];
        $this->navegador($cookies, 'POST', $ruta.'/impacto', [...$datos, 'per_page' => 1, 'page' => 2])->assertOk()
            ->assertJsonPath('data.puede_confirmar', false)->assertJsonPath('data.totales.conflictos', 3)->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data.elementos');
        unset($datos['accion'], $datos['id_horario']);
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', $datos)->assertConflict()->assertJsonPath('error.code', 'HORARIO_CITAS_SIN_COBERTURA');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function limites(): array
    {
        return [['07:45:00', '08:00:00', true], ['12:00:00', '12:15:00', true], ['11:59:59', '12:15:00', false],
            ['08:00:00', '08:00:00', false], ['23:45:00', '00:15:00', false], ['08:00:01', '08:15:00', false]];
    }

    #[DataProvider('limites')]
    public function test_own_intervals_are_half_open_and_duration_needs_whole_minutes(string $inicio, string $fin, bool $permitido): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $response = $this->horario($cookies, $ruta, $h['actualizado_en'], ['hora_inicio' => $inicio, 'hora_fin' => $fin]);
        $permitido ? $response->assertCreated() : $response->assertConflict();
    }

    public static function compartidos(): array
    {
        return [[true, true, false], [false, true, false], [true, false, true], [false, false, true]];
    }

    #[DataProvider('compartidos')]
    public function test_shared_room_reservation_depends_on_schedule_activity_not_doctor_activity(bool $medico, bool $horario, bool $permitido): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0], 'activo' => $medico]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'activo' => $horario]);
        $response = $this->horario($cookies, $ruta, $version);
        $permitido ? $response->assertCreated() : $response->assertConflict()->assertJsonPath('error.code', 'PLANIFICACION_CONFLICTO');
    }

    public function test_inactive_doctor_can_manage_schedule_and_reactivation_checks_reservation(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_consultorio' => $r['rooms'][0]]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][1], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => true, 'actualizado_en_original' => $h['actualizado_en']])->assertConflict();
        $this->assertFalse(DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('activo'));
    }

    public static function salasInvalidas(): array
    {
        return [['sin_sala'], ['inactiva']];
    }

    #[DataProvider('salasInvalidas')]
    public function test_active_schedule_requires_assigned_active_room(string $tipo): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        if ($tipo === 'sin_sala') {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => null]);
        } else {
            DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        }
        $this->horario($cookies, $ruta, $version)->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_NO_DISPONIBLE');
    }

    public static function cobertura(): array
    {
        return [['08:00:00', '08:15:00', true], ['08:00:01', '12:00:00', false], ['07:00:00', '08:14:59', false], ['09:00:00', '12:00:00', false]];
    }

    #[DataProvider('cobertura')]
    public function test_changes_preserve_complete_appointment_interval_and_never_rewrite_appointments(string $inicio, string $fin, bool $permitido): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        DB::table('citas')->insert($this->citaRow($r));
        $antes = DB::table('citas')->get()->toJson();
        $response = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_inicio' => $inicio, 'hora_fin' => $fin, 'actualizado_en_original' => $h['actualizado_en']]);
        $permitido ? $response->assertOk() : $response->assertConflict()->assertJsonPath('error.code', 'HORARIO_CITAS_SIN_COBERTURA');
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
    }

    public function test_in_progress_appointment_is_checked_in_full_and_ended_or_cancelled_do_not_block(): void
    {
        CarbonImmutable::setTestNow('2026-11-01T12:05:00Z');
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_inicio' => '08:05:00', 'actualizado_en_original' => $h['actualizado_en']])
            ->assertConflict()->assertJsonPath('error.code', 'HORARIO_CITAS_SIN_COBERTURA');
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Cancelada']);
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        CarbonImmutable::setTestNow('2026-11-01T12:15:00Z');
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Programada']);
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_inicio' => '09:00:00', 'actualizado_en_original' => $h['actualizado_en']])->assertOk();
    }

    public function test_real_occupancy_blocks_shared_room_even_without_fixed_assignment_and_schedule(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        DB::table('citas')->insert($this->citaRow($r, ['id_medico' => $r['doctors'][1]]));
        $this->horario($cookies, $ruta, $version)->assertConflict()->assertJsonPath('error.code', 'PLANIFICACION_CONFLICTO');
    }

    public function test_confirmed_appointment_keeps_coverage_requirement_and_final_appointment_location_is_preserved(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => 'Confirmada']), 'id_cita');
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertConflict();
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Atendida']);
        $antes = DB::table('citas')->get()->toJson();
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        $eventos = DB::table('bitacora')->count();
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->assertJsonPath('data.actualizado_en', $h['actualizado_en']);
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
        $this->assertSame($eventos, DB::table('bitacora')->count());
    }

    public function test_disabling_redundant_schedule_preserves_appointments_and_frees_weekly_slot(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00']);
        DB::table('citas')->insert($this->citaRow($r));
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk();
        $this->assertSame(1, DB::table('citas')->count());
    }

    public function test_duration_configuration_default_noop_surplus_and_existing_data_preserved(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $config = $this->configuracion($cookies);
        $this->assertSame(15, $config['duracion_turno_minutos']);
        $h = $this->horario($cookies, $ruta, $version, ['hora_fin' => '08:50:00'])->assertCreated()->assertJsonPath('data.turnos_completos', 3)->json('data');
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => '2026-10-21T12:00:00Z']);
        $antes = $this->snapshot();
        $datos = ['duracion_turno_minutos' => 15, 'actualizado_en_original' => $config['actualizado_en']];
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', $datos)->assertOk()->assertJsonPath('data.actualizado_en', $config['actualizado_en']);
        $this->assertSame($antes, $this->snapshot());
        $datos['duracion_turno_minutos'] = 20;
        $this->navegador($cookies, 'POST', '/api/configuracion-citas/impacto', $datos)->assertOk()->assertJsonPath('data.puede_confirmar', true);
        $nuevo = $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', $datos)->assertOk()->assertJsonPath('data.duracion_turno_minutos', 20)->json('data');
        $this->assertNotSame($config['actualizado_en'], $nuevo['actualizado_en']);
        $this->navegador($cookies, 'GET', $ruta)->assertOk()->assertJsonPath('data.elementos.0.turnos_completos', 2)->assertJsonPath('data.actualizado_en', $h['actualizado_en']);
        $despues = $this->snapshot();
        unset($antes['configuracion_clinica'], $despues['configuracion_clinica']);
        $this->assertSame($antes, $despues);
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', $datos)->assertConflict()->assertJsonPath('error.code', 'CONFIGURACION_EDICION_OBSOLETA');
        $event = $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=ConfiguracionClinica&registro_referido=1')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$event)->assertOk()->assertJsonPath('data.cambios_seguros.antes.duracion_turno_minutos', 15)->assertJsonPath('data.cambios_seguros.despues.duracion_turno_minutos', 20);
    }

    public function test_duration_checks_all_active_intervals_including_inactive_professionals_and_ignores_disabled_intervals(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        DB::table('medicos')->update(['activo' => false]);
        for ($i = 0; $i < 3; $i++) {
            DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => $i + 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '08:15:00']);
        }
        $config = $this->configuracion($cookies);
        $datos = ['duracion_turno_minutos' => 16, 'actualizado_en_original' => $config['actualizado_en']];
        $antes = $this->snapshot();
        $this->navegador($cookies, 'POST', '/api/configuracion-citas/impacto', [...$datos, 'page' => 2, 'per_page' => 1])->assertOk()
            ->assertJsonPath('data.puede_confirmar', false)->assertJsonPath('data.totales.conflictos', 3)->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data.elementos');
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', $datos)->assertConflict()->assertJsonPath('error.code', 'DURACION_INCOMPATIBLE');
        $this->assertSame($antes, $this->snapshot());
        DB::table('horarios_atencion')->update(['activo' => false]);
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', $datos)->assertOk();
    }

    public function test_full_integer_duration_is_valid_without_active_intervals_and_reactivation_checks_current_duration(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $h = $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => false, 'actualizado_en_original' => $h['actualizado_en']])->assertOk()->json('data');
        $config = $this->configuracion($cookies);
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 2147483647, 'actualizado_en_original' => $config['actualizado_en']])->assertOk();
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'].'/estado', ['activo' => true, 'actualizado_en_original' => $h['actualizado_en']])->assertConflict()->assertJsonPath('error.code', 'DURACION_INCOMPATIBLE');
    }

    public function test_foreign_and_missing_schedule_and_bigint_route_are_not_accepted(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $otro = DB::table('horarios_atencion')->insertGetId(['id_medico' => $r['doctors'][1], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00'], 'id_horario');
        foreach ([$otro, '9223372036854775807'] as $id) {
            $this->navegador($cookies, 'PATCH', $ruta.'/'.$id, ['hora_fin' => '13:00:00', 'actualizado_en_original' => $version])->assertNotFound();
        }
        $this->navegador($cookies, 'GET', '/api/medicos/9223372036854775808/horarios')->assertUnprocessable();
        $this->navegador($cookies, 'DELETE', $ruta.'/'.$otro)->assertStatus(405);
    }

    public static function entradasInvalidas(): array
    {
        return [['dia_semana', 0], ['dia_semana', 8], ['dia_semana', []], ['hora_inicio', '08:00'], ['hora_fin', '24:00:00'],
            ['actualizado_en_original', '2026-02-30T12:00:00.000000Z'], ['id_medico', '1'], ['id_objetivo', '1'], ['activo', false], ['estado', 'Atendida']];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_create_rejects_invalid_input_and_control_fields(string $campo, mixed $valor): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $this->horario($cookies, $ruta, $version, [$campo => $valor])->assertUnprocessable()->assertJsonValidationErrors($campo, 'error.details');
        $this->assertSame(0, DB::table('horarios_atencion')->count());
    }

    public static function duracionesInvalidas(): array
    {
        return [[0], [-1], [1.5], [2147483648], [null], [[]]];
    }

    #[DataProvider('duracionesInvalidas')]
    public function test_duration_rejects_out_of_range_or_noninteger_values(mixed $duracion): void
    {
        [$cookies] = $this->contexto();
        $config = $this->configuracion($cookies);
        $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => $duracion, 'actualizado_en_original' => $config['actualizado_en']])->assertUnprocessable();
    }

    public function test_preview_and_partial_edit_reject_irrelevant_fields_and_route_spoofing(): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $h = $this->horario($cookies, $ruta, $version)->assertCreated()->json('data');
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['actualizado_en_original' => $h['actualizado_en']])->assertUnprocessable();
        $this->navegador($cookies, 'PATCH', $ruta.'/'.$h['id_horario'], ['hora_fin' => '13:00:00', 'id_horario' => $h['id_horario'], 'actualizado_en_original' => $h['actualizado_en']])->assertUnprocessable();
        foreach ([['accion' => 'BORRAR'], ['accion' => []], ['accion' => 'ESTADO', 'activo' => 'false', 'id_horario' => $h['id_horario']], ['accion' => 'CREAR', 'id_horario' => $h['id_horario']]] as $datos) {
            $this->navegador($cookies, 'POST', $ruta.'/impacto', [...$datos, 'actualizado_en_original' => $h['actualizado_en']])->assertUnprocessable();
        }
        $this->navegador($cookies, 'GET', $ruta.'?per_page=101')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/configuracion-citas?zona_horaria=UTC')->assertUnprocessable();
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_eight_operations_are_exclusively_for_normal_administrators(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach ([['GET', '/api/medicos/1/horarios'], ['POST', '/api/medicos/1/horarios'], ['POST', '/api/medicos/1/horarios/impacto'],
            ['PATCH', '/api/medicos/1/horarios/1'], ['PATCH', '/api/medicos/1/horarios/1/estado'], ['GET', '/api/configuracion-citas'],
            ['POST', '/api/configuracion-citas/impacto'], ['PATCH', '/api/configuracion-citas']] as [$metodo, $ruta]) {
            $this->navegador($cookies, $metodo, $ruta)->assertForbidden();
        }
    }

    public function test_session_origin_and_csrf_controls(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/configuracion-citas')->assertUnauthorized();
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $this->navegador($cookies, 'POST', $ruta, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/configuracion-citas', headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
    }

    public static function operacionesAuditadas(): array
    {
        return [['horario'], ['duracion']];
    }

    #[DataProvider('operacionesAuditadas')]
    public function test_failure_after_audit_rolls_back_all_mutations_and_event(string $operacion): void
    {
        [$cookies, $r, $ruta, $version] = $this->contexto();
        $config = $this->configuracion($cookies);
        $antes = $this->snapshot();
        $eventos = DB::table('bitacora')->count();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('planificacionModificada')->once()->andReturnUsing(function (...$args): void {
            (new BitacoraRepository)->planificacionModificada(...$args);
            throw new RuntimeException('Error privado de auditoría');
        });
        $this->app->instance(BitacoraRepository::class, $mock);
        $response = $operacion === 'horario' ? $this->horario($cookies, $ruta, $version)
            : $this->navegador($cookies, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 20, 'actualizado_en_original' => $config['actualizado_en']]);
        $response->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Error privado');
        $this->assertSame($antes, $this->snapshot());
        $this->assertSame($eventos, DB::table('bitacora')->count());
    }
}
