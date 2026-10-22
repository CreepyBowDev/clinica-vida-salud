<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Consultorio;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ConsultoriosTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function administrador(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return $cookies;
    }

    private function crear(array &$cookies, array $datos = []): array
    {
        return $this->navegador($cookies, 'POST', '/api/consultorios', array_replace([
            'codigo_consultorio' => 'C-FICTICIO', 'nombre' => 'Consultorio ficticio',
        ], $datos))->assertCreated()->json('data');
    }

    private function editar(array &$cookies, array $consultorio, array $datos, string $sufijo = ''): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/consultorios/'.$consultorio['id_consultorio'].$sufijo,
            ['actualizado_en_original' => $consultorio['actualizado_en'], ...$datos]);
    }

    public function test_catalog_has_minimal_resources_private_headers_and_audit_without_free_text(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies, ['codigo_consultorio' => '  C-FICTICIO  ', 'nombre' => '  Nombre privado ficticio  ']);
        $this->assertSame('C-FICTICIO', $f['codigo_consultorio']);
        $this->assertSame('Nombre privado ficticio', $f['nombre']);
        $this->assertTrue($f['activo']);
        $this->assertIsString($f['id_consultorio']);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $f['actualizado_en']);
        $this->assertSame(['id_consultorio', 'codigo_consultorio', 'nombre', 'activo', 'actualizado_en'], array_keys($f));
        $this->navegador($cookies, 'GET', '/api/consultorios/'.$f['id_consultorio'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [...$f, 'medicos_asignados' => 0, 'citas_vigentes' => 0]]);
        $evento = DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_CREADO')->sole();
        $this->assertSame('CONSULTORIOS', $evento->modulo);
        $this->assertSame('Administrador', $evento->rol_responsable);
        $this->assertSame(['id_consultorio' => $f['id_consultorio']], json_decode($evento->referencias, true));
        $this->assertStringNotContainsString('Nombre privado ficticio', json_encode($evento));
        $this->assertStringNotContainsString('C-FICTICIO', json_encode($evento));
    }

    public function test_exact_code_unique_conflicts_roll_back_create_and_rename_but_case_and_name_can_repeat(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies);
        $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => ' C-FICTICIO ', 'nombre' => 'Otro'])
            ->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_CODIGO_DUPLICADO')->assertDontSee('SQL');
        $b = $this->crear($cookies, ['codigo_consultorio' => 'c-ficticio']);
        $this->editar($cookies, $b, ['codigo_consultorio' => $a['codigo_consultorio'], 'nombre' => 'No guardar'])
            ->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_CODIGO_DUPLICADO');
        $this->navegador($cookies, 'GET', '/api/consultorios/'.$b['id_consultorio'])->assertJsonPath('data.nombre', $b['nombre']);
        $this->assertSame(2, Consultorio::query()->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ACTUALIZADO')->count());
    }

    public function test_literal_search_combines_code_or_name_with_state_and_pages_without_wildcards(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies, ['codigo_consultorio' => 'BUSCAR-%']);
        $b = $this->crear($cookies, ['codigo_consultorio' => 'OTRO', 'nombre' => 'Buscar _ ficticio']);
        $this->editar($cookies, $a, ['activo' => false], '/estado')->assertOk();
        $this->navegador($cookies, 'GET', '/api/consultorios?q=buscar&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id_consultorio', $b['id_consultorio']);
        $this->navegador($cookies, 'GET', '/api/consultorios?q=buscar&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id_consultorio', $a['id_consultorio']);
        $this->navegador($cookies, 'GET', '/api/consultorios?q=buscar&activo=1')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/consultorios?q=%25&activo=0')->assertOk()->assertJsonPath('data.0.id_consultorio', $a['id_consultorio']);
        $this->navegador($cookies, 'GET', '/api/consultorios?q=_')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/consultorios?q=inexistente')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($cookies, 'GET', '/api/consultorios?page=100')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_edits_state_and_noops_have_monotonic_version_and_no_duplicate_events(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $r = $this->editar($cookies, $f, ['nombre' => 'Nombre editado'])->assertOk()->json('data');
        $this->assertSame($f['codigo_consultorio'], $r['codigo_consultorio']);
        $this->assertNotSame($f['actualizado_en'], $r['actualizado_en']);
        $this->editar($cookies, $r, [])->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $this->editar($cookies, $r, ['nombre' => 'Nombre editado'])->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $r = $this->editar($cookies, $r, ['activo' => false], '/estado')->assertOk()->assertJsonPath('data.activo', false)->json('data');
        $this->editar($cookies, $r, ['activo' => false], '/estado')->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $this->editar($cookies, $r, ['activo' => true], '/estado')->assertOk()->assertJsonPath('data.activo', true);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ACTUALIZADO')->count());
        $this->assertSame(2, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ESTADO_CAMBIADO')->count());
    }

    public function test_stale_version_is_rejected_before_noop_and_after_microsecond_rollover(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $future = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(999999);
        DB::table('consultorios')->where('id_consultorio', $f['id_consultorio'])->update(['actualizado_en' => $future->format('Y-m-d H:i:s.uP')]);
        $f['actualizado_en'] = $future->format('Y-m-d\TH:i:s.u\Z');
        $this->editar($cookies, $f, ['nombre' => 'Cambio'])->assertOk()->assertJsonPath('data.actualizado_en', $future->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z'));
        $this->editar($cookies, $f, ['nombre' => 'Cambio'])->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_EDICION_OBSOLETA');
        $this->editar($cookies, $f, ['activo' => true], '/estado')->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_EDICION_OBSOLETA');
    }

    public static function actividadMedico(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('actividadMedico')]
    public function test_assigned_doctor_blocks_deactivation_even_without_active_hours(bool $activo): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => $activo]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'activo' => false]);
        $f = $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertOk()->assertJsonPath('data.medicos_asignados', 1)->json('data');
        $antes = DB::table('medicos')->get()->toJson();
        $horarios = DB::table('horarios_atencion')->get()->toJson();
        $this->editar($cookies, $f, ['activo' => false], '/estado')->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_CON_ASIGNACIONES');
        $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertExactJson(['data' => $f]);
        $this->assertSame($antes, DB::table('medicos')->get()->toJson());
        $this->assertSame($horarios, DB::table('horarios_atencion')->get()->toJson());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'CONSULTORIO_ESTADO_CAMBIADO')->count());
    }

    public static function citasVigentes(): array
    {
        return [['Programada', 10], ['Confirmada', 10], ['Programada', -5], ['Confirmada', -5]];
    }

    #[DataProvider('citasVigentes')]
    public function test_unassigned_room_with_future_or_running_appointment_cannot_be_deactivated(string $estado, int $inicio): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        $now = CarbonImmutable::now('UTC');
        DB::table('citas')->insert($this->citaRow($r, ['estado' => $estado,
            'inicio_at' => $now->addMinutes($inicio), 'fin_at' => $now->addMinutes($inicio + 15)]));
        // La instantánea de una cita puede apuntar a un consultorio anterior al fijo actual.
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $r['rooms'][1]]);
        $f = $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertOk()
            ->assertJsonPath('data.medicos_asignados', 0)->assertJsonPath('data.citas_vigentes', 1)->json('data');
        $this->editar($cookies, $f, ['activo' => false], '/estado')->assertConflict()->assertJsonPath('error.code', 'CONSULTORIO_CON_OBLIGACIONES');
        $this->assertTrue(Consultorio::query()->findOrFail($r['rooms'][0])->activo);
    }

    public function test_catalog_edit_preserves_appointment_snapshot_history_notices_and_doctors(): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        $before = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'medicos'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $f = $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertOk()->json('data');
        $this->editar($cookies, $f, ['nombre' => 'Nombre nuevo', 'codigo_consultorio' => 'CODIGO-NUEVO'])->assertOk();
        foreach ($before as $table => $values) {
            $this->assertSame($values, DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_historical_and_cancelled_appointments_do_not_block_deactivation_or_change_history(): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        $now = CarbonImmutable::now('UTC');
        foreach (['Programada', 'Confirmada', 'Atendida', 'Inasistencia', 'Cancelada'] as $i => $state) {
            $start = $state === 'Cancelada' ? $now->addDay() : $now->subDays($i + 1);
            DB::table('citas')->insert($this->citaRow($r, ['estado' => $state, 'inicio_at' => $start, 'fin_at' => $start->addMinutes(15)]));
        }
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_consultorio' => $r['rooms'][1]]);
        $f = $this->navegador($cookies, 'GET', '/api/consultorios/'.$r['rooms'][0])->assertOk()->assertJsonPath('data.citas_vigentes', 0)->json('data');
        $before = DB::table('citas')->get()->toJson();
        $this->editar($cookies, $f, ['activo' => false], '/estado')->assertOk()->assertJsonPath('data.activo', false);
        $this->assertSame($before, DB::table('citas')->get()->toJson());
    }

    public function test_missing_and_out_of_range_ids_and_delete_are_safe(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->navegador($cookies, 'GET', '/api/consultorios/9223372036854775807')->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/consultorios/9223372036854775808')->assertUnprocessable();
        $this->editar($cookies, [...$f, 'id_consultorio' => '9223372036854775807'], ['nombre' => 'Ausente'])->assertNotFound();
        $this->navegador($cookies, 'DELETE', '/api/consultorios/'.$f['id_consultorio'])->assertStatus(405);
    }

    public function test_bigint_identifier_is_not_rounded_and_detail_does_not_expand_doctor_or_patient_data(): void
    {
        $cookies = $this->administrador();
        DB::table('consultorios')->insert(['id_consultorio' => '9007199254740993', 'codigo_consultorio' => 'BIGINT', 'nombre' => 'Ficticio']);
        $this->navegador($cookies, 'GET', '/api/consultorios/9007199254740993')->assertOk()
            ->assertJsonPath('data.id_consultorio', '9007199254740993')->assertJsonMissingPath('data.medicos')
            ->assertJsonMissingPath('data.citas')->assertJsonMissingPath('data.pacientes');
    }

    public static function invalidos(): array
    {
        return [
            ['POST', '', ['codigo_consultorio' => ' '], 'codigo_consultorio'],
            ['POST', '', ['codigo_consultorio' => ['objeto']], 'codigo_consultorio'],
            ['POST', '', ['codigo_consultorio' => str_repeat('a', 51)], 'codigo_consultorio'],
            ['POST', '', ['nombre' => ' '], 'nombre'],
            ['POST', '', ['nombre' => str_repeat('a', 121)], 'nombre'],
            ['POST', '', ['nombre' => "Nombre\0invalido"], 'nombre'],
            ['POST', '', ['activo' => false], 'activo'],
            ['PATCH', '', ['nombre' => null], 'nombre'],
            ['PATCH', '', ['id_consultorio' => '1'], 'id_consultorio'],
            ['PATCH', '', ['id_objetivo' => '1'], 'id_objetivo'],
            ['PATCH', '/estado', ['activo' => 'false'], 'activo'],
            ['PATCH', '/estado', ['activo' => true, 'nombre' => 'Ajeno'], 'nombre'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_invalid_or_extra_fields_never_persist(string $method, string $suffix, array $data, string $field): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $response = $method === 'POST'
            ? $this->navegador($cookies, 'POST', '/api/consultorios', array_replace(['codigo_consultorio' => 'NUEVO', 'nombre' => 'Nombre'], $data))
            : $this->editar($cookies, $f, $data, $suffix);
        $response->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');
        $this->assertSame(1, Consultorio::query()->count());
        $this->assertSame($f['nombre'], Consultorio::query()->sole()->nombre);
    }

    public function test_filters_and_version_are_strictly_validated(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        foreach (['per_page=101', 'page=0', 'activo=false', 'orden=nombre', 'q[]=objeto', 'q='] as $query) {
            $this->navegador($cookies, 'GET', '/api/consultorios?'.$query)->assertUnprocessable();
        }
        foreach ([null, '2026-10-21T12:00:00Z', '2026-02-30T12:00:00.123456Z'] as $version) {
            $this->editar($cookies, $f, ['actualizado_en_original' => $version, 'nombre' => 'Prueba'])->assertUnprocessable()
                ->assertJsonValidationErrors('actualizado_en_original', 'error.details');
        }
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_access_catalog(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach ([['GET', ''], ['GET', '/1'], ['POST', ''], ['PATCH', '/1'], ['PATCH', '/1/estado']] as [$method, $suffix]) {
            $this->navegador($cookies, $method, '/api/consultorios'.$suffix)->assertForbidden();
        }
        $this->assertSame(0, Consultorio::query()->count());
    }

    public function test_anonymous_missing_csrf_foreign_origin_and_revoked_sessions_cannot_use_catalog(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/consultorios')->assertUnauthorized();
        $cookies = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'X', 'nombre' => 'Prueba'], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/consultorios', headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        DB::table('usuarios')->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'GET', '/api/consultorios')->assertUnauthorized();
        $this->assertSame(0, Consultorio::query()->count());
    }

    public function test_temporary_password_session_cannot_read_or_write_catalog(): void
    {
        $cookies = $this->administrador();
        $target = $this->usuarioAuth();
        $secret = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $secret)->assertOk();
        $this->navegador($restricted, 'GET', '/api/consultorios')->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($restricted, 'POST', '/api/consultorios', ['codigo_consultorio' => 'X', 'nombre' => 'Prueba'])->assertForbidden();
    }

    public static function escrituras(): array
    {
        return [['crear'], ['editar'], ['estado']];
    }

    #[DataProvider('escrituras')]
    public function test_audit_failure_rolls_back_data_and_version_and_hides_technical_details(string $operation): void
    {
        $cookies = $this->administrador();
        $f = $operation === 'crear' ? null : $this->crear($cookies);
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('consultorioModificado')->once()->andThrow(new RuntimeException('Detalle privado simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $response = $operation === 'crear'
            ? $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'NO', 'nombre' => 'No confirmar'])
            : $this->editar($cookies, $f, $operation === 'estado' ? ['activo' => false] : ['nombre' => 'No confirmar'], $operation === 'estado' ? '/estado' : '');
        $response->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Detalle privado');
        $this->assertSame($f === null ? 0 : 1, Consultorio::query()->count());
        if ($f !== null) {
            $saved = Consultorio::query()->sole();
            $this->assertSame($f['nombre'], $saved->nombre);
            $this->assertSame($f['activo'], $saved->activo);
            $this->assertSame($f['actualizado_en'], $saved->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z'));
        }
        $this->assertSame(0, DB::table('bitacora')->whereIn('tipo_evento', ['CONSULTORIO_ACTUALIZADO', 'CONSULTORIO_ESTADO_CAMBIADO'])->count());
    }

    public function test_unrelated_sql_failure_is_not_reported_as_code_duplicate(): void
    {
        $cookies = $this->administrador();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('consultorioModificado')->once()->andReturnUsing(function (Consultorio $consultorio): void {
            DB::table('consultorios')->insert(['id_consultorio' => $consultorio->getKey(), 'codigo_consultorio' => 'OTRO', 'nombre' => 'Otro']);
        });
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'X', 'nombre' => 'Prueba'])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertSame(0, Consultorio::query()->count());
    }

    public function test_real_catalog_events_are_filtered_and_projected_safely_by_cu30(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $f = $this->editar($cookies, $f, ['nombre' => 'Texto administrativo no publicado'])->assertOk()->json('data');
        $this->editar($cookies, $f, ['activo' => false], '/estado')->assertOk();
        $list = $this->navegador($cookies, 'GET', '/api/bitacora?entidad_referida=Consultorio&registro_referido='.$f['id_consultorio'])
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.referencias.id_consultorio', $f['id_consultorio']);
        $event = $list->json('data.0.id_evento');
        DB::table('bitacora')->insert(['tipo_evento' => 'CONSULTORIO_ACTUALIZADO', 'tipo_actor' => 'SISTEMA', 'modulo' => 'CONSULTORIOS',
            'operacion' => 'PRUEBA', 'resultado' => 'EXITO', 'ocurrido_en' => now(), 'correlacion' => (string) Str::uuid(), 'referencias' => json_encode(['id_consultorio' => $f['id_consultorio'], 'secreto' => 'No publicar']),
            'cambios_seguros' => json_encode(['campos' => ['nombre', 'secreto'], 'nombre' => 'No publicar'])]);
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$event)->assertOk()
            ->assertJsonPath('data.cambios_seguros.campos', ['activo'])->assertDontSee('Texto administrativo');
        $unknown = DB::table('bitacora')->where('operacion', 'PRUEBA')->value('id_evento');
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$unknown)->assertOk()
            ->assertJsonPath('data.cambios_seguros.campos', ['nombre'])->assertDontSee('No publicar')->assertDontSee('secreto');
    }

    public function test_additive_migration_initializes_existing_room_and_preserves_assignments_and_snapshot(): void
    {
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        $before = DB::table('citas')->get()->toJson();
        $medicos = DB::table('medicos')->get()->toJson();
        $migration = require base_path('src/data/database/migrations/2026_10_21_000033_add_actualizado_en_to_consultorios.php');
        $migration->down();
        $id = DB::table('consultorios')->insertGetId(['codigo_consultorio' => 'ANTERIOR', 'nombre' => 'Anterior ficticio', 'activo' => false], 'id_consultorio');
        $migration->up();
        $row = DB::table('consultorios')->where('id_consultorio', $id)->first();
        $this->assertSame('ANTERIOR', $row->codigo_consultorio);
        $this->assertSame('Anterior ficticio', $row->nombre);
        $this->assertFalse($row->activo);
        $this->assertNotNull($row->actualizado_en);
        $column = DB::selectOne("SELECT data_type, datetime_precision, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'consultorios' AND column_name = 'actualizado_en'");
        $this->assertSame('timestamp with time zone', $column->data_type);
        $this->assertSame(6, $column->datetime_precision);
        $this->assertSame('NO', $column->is_nullable);
        $this->assertSame('CURRENT_TIMESTAMP', $column->column_default);
        $this->assertSame($before, DB::table('citas')->get()->toJson());
        $this->assertSame($medicos, DB::table('medicos')->get()->toJson());
    }
}
