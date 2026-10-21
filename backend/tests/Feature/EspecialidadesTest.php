<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Especialidad;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class EspecialidadesTest extends TestCase
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
        return $this->navegador($cookies, 'POST', '/api/especialidades', array_replace(['nombre' => 'Especialidad Ficticia'], $datos))
            ->assertCreated()->json('data');
    }

    private function editar(array &$cookies, array $especialidad, array $datos, string $sufijo = ''): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/especialidades/'.$especialidad['id_especialidad'].$sufijo,
            ['actualizado_en_original' => $especialidad['actualizado_en'], ...$datos]);
    }

    public function test_administrator_creates_reads_and_audits_only_public_catalog_fields(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies, ['nombre' => '  Especialidad Ficticia  ', 'descripcion' => 'Descripción ficticia']);
        $this->assertSame('Especialidad Ficticia', $f['nombre']);
        $this->assertTrue($f['activa']);
        $this->assertIsString($f['id_especialidad']);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $f['actualizado_en']);
        $this->navegador($cookies, 'GET', '/api/especialidades/'.$f['id_especialidad'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => $f]);
        $this->assertSame(['id_especialidad', 'nombre', 'descripcion', 'activa', 'actualizado_en'], array_keys($f));
        $evento = DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_CREADA')->sole();
        $this->assertSame('ESPECIALIDADES', $evento->modulo);
        $this->assertSame('Administrador', $evento->rol_responsable);
        $this->assertSame(['id_especialidad' => $f['id_especialidad']], json_decode($evento->referencias, true));
        $this->assertStringNotContainsString('Descripción ficticia', json_encode($evento));
    }

    public function test_normalized_duplicate_on_create_and_rename_rolls_back_with_safe_conflict(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies);
        $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => ' especialidad FICTICIA '])->assertConflict()
            ->assertJsonPath('error.code', 'ESPECIALIDAD_NOMBRE_DUPLICADO')->assertJsonMissingPath('error.details');
        $b = $this->crear($cookies, ['nombre' => 'Otra Ficticia']);
        $this->editar($cookies, $b, ['nombre' => $a['nombre'], 'descripcion' => 'No debe guardarse'])->assertConflict();
        $this->navegador($cookies, 'GET', '/api/especialidades/'.$b['id_especialidad'])->assertExactJson(['data' => $b]);
        $this->assertSame(2, Especialidad::query()->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_ACTUALIZADA')->count());
    }

    public function test_accents_and_internal_spacing_are_not_arbitrarily_normalized(): void
    {
        $cookies = $this->administrador();
        foreach (['Clínica Ficticia', 'Clinica Ficticia', 'Clinica  Ficticia'] as $nombre) {
            $this->crear($cookies, ['nombre' => $nombre]);
        }
        $this->assertSame(3, Especialidad::query()->count());
    }

    public function test_literal_filters_pagination_and_empty_result(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies, ['nombre' => 'Prueba % Especialidad']);
        $b = $this->crear($cookies, ['nombre' => 'PRUEBA _ Especialidad']);
        $this->editar($cookies, $a, ['activa' => false], '/estado')->assertOk();
        $this->navegador($cookies, 'GET', '/api/especialidades?nombre=prueba&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id_especialidad', $b['id_especialidad']);
        $this->navegador($cookies, 'GET', '/api/especialidades?nombre=%25&activa=0')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_especialidad', $a['id_especialidad']);
        $this->navegador($cookies, 'GET', '/api/especialidades?nombre=_&activa=1')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/especialidades?nombre=prueba&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id_especialidad', $a['id_especialidad']);
        $this->navegador($cookies, 'GET', '/api/especialidades?nombre=inexistente')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_edits_nullable_description_and_noop_preserve_version_when_unchanged(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies, ['descripcion' => 'Texto inicial']);
        $r = $this->editar($cookies, $f, ['nombre' => 'Nombre Ficticio Editado'])->assertOk()
            ->assertJsonPath('data.descripcion', 'Texto inicial')->assertJsonPath('data.id_especialidad', $f['id_especialidad'])->json('data');
        $this->assertNotSame($f['actualizado_en'], $r['actualizado_en']);
        $r = $this->editar($cookies, $r, ['descripcion' => null])->assertOk()->assertJsonPath('data.descripcion', null)->json('data');
        $this->editar($cookies, $r, ['descripcion' => null])->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $this->editar($cookies, $r, [])->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $this->assertSame(2, DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_ACTUALIZADA')->count());
    }

    public function test_stale_edit_or_state_including_apparent_noop_is_rejected(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->editar($cookies, $f, ['activa' => false], '/estado')->assertOk();
        foreach ([['', ['descripcion' => 'Borrador']], ['/estado', ['activa' => false]]] as [$sufijo, $datos]) {
            $this->editar($cookies, $f, $datos, $sufijo)->assertConflict()->assertJsonPath('error.code', 'ESPECIALIDAD_EDICION_OBSOLETA');
        }
        $this->assertNull(Especialidad::query()->findOrFail($f['id_especialidad'])->descripcion);
    }

    public function test_monotonic_version_advances_past_future_timestamp_and_microsecond_rollover(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $futuro = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(999999);
        DB::table('especialidades')->where('id_especialidad', $f['id_especialidad'])->update(['actualizado_en' => $futuro->format('Y-m-d H:i:s.uP')]);
        $f['actualizado_en'] = $futuro->format('Y-m-d\TH:i:s.u\Z');
        $this->editar($cookies, $f, ['descripcion' => 'Actualizada'])->assertOk()
            ->assertJsonPath('data.actualizado_en', $futuro->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function test_state_changes_preserve_doctors_associations_and_full_appointments(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $recursos = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($recursos));
        $antes = DB::table('citas')->get()->toJson();
        $pares = DB::table('medico_especialidad')->get()->toJson();
        $medicos = DB::table('medicos')->get()->toJson();
        $r = $this->editar($cookies, $f, ['activa' => false], '/estado')->assertOk()->assertJsonPath('data.activa', false)->json('data');
        $this->editar($cookies, $r, ['activa' => false], '/estado')->assertOk()->assertJsonPath('data.actualizado_en', $r['actualizado_en']);
        $this->editar($cookies, $r, ['activa' => true], '/estado')->assertOk()->assertJsonPath('data.activa', true);
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
        $this->assertSame($pares, DB::table('medico_especialidad')->get()->toJson());
        $this->assertSame($medicos, DB::table('medicos')->get()->toJson());
        $this->assertSame(2, DB::table('bitacora')->where('tipo_evento', 'ESPECIALIDAD_ESTADO_CAMBIADO')->count());
    }

    public function test_missing_resources_bigint_bounds_and_no_delete_endpoint(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->navegador($cookies, 'GET', '/api/especialidades/9223372036854775807')->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/especialidades/9223372036854775808')->assertUnprocessable();
        $this->editar($cookies, [...$f, 'id_especialidad' => '9223372036854775807'], ['nombre' => 'Ausente'])->assertNotFound();
        $this->navegador($cookies, 'DELETE', '/api/especialidades/'.$f['id_especialidad'])->assertStatus(405);
    }

    public static function invalidos(): array
    {
        return [
            ['POST', '', ['nombre' => ' '], 'nombre'],
            ['POST', '', ['nombre' => ['Objeto']], 'nombre'],
            ['POST', '', ['nombre' => str_repeat('a', 121)], 'nombre'],
            ['POST', '', ['nombre' => "Nombre\0invalido"], 'nombre'],
            ['POST', '', ['nombre' => 'Válido', 'descripcion' => str_repeat('a', 2001)], 'descripcion'],
            ['POST', '', ['nombre' => 'Válido', 'activa' => false], 'activa'],
            ['PATCH', '', ['nombre' => null], 'nombre'],
            ['PATCH', '', ['id_especialidad' => '123'], 'id_especialidad'],
            ['PATCH', '/estado', ['activa' => 'false'], 'activa'],
            ['PATCH', '/estado', ['activa' => true, 'nombre' => 'Ajeno'], 'nombre'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_rejects_invalid_and_unexpected_fields(string $metodo, string $sufijo, array $datos, string $campo): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $path = '/api/especialidades';
        if ($metodo === 'PATCH') {
            $path .= '/'.$f['id_especialidad'].$sufijo;
            $datos = ['actualizado_en_original' => $f['actualizado_en'], ...$datos];
        }
        $this->navegador($cookies, $metodo, $path, $datos)->assertUnprocessable()->assertJsonValidationErrors($campo, 'error.details');
        $this->assertSame(1, Especialidad::query()->count());
    }

    public function test_rejects_invalid_filters_and_requires_exact_version_format(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        foreach (['per_page=101', 'page=0', 'activa=false', 'orden=nombre', 'nombre[]=objeto'] as $query) {
            $this->navegador($cookies, 'GET', '/api/especialidades?'.$query)->assertUnprocessable();
        }
        foreach ([null, '2026-10-21T12:00:00Z', '2026-02-30T12:00:00.123456Z'] as $version) {
            $this->editar($cookies, $f, ['actualizado_en_original' => $version, 'descripcion' => 'Prueba'])->assertUnprocessable()
                ->assertJsonValidationErrors('actualizado_en_original', 'error.details');
        }
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_access_any_operation(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach ([['GET', ''], ['GET', '/1'], ['POST', ''], ['PATCH', '/1'], ['PATCH', '/1/estado']] as [$metodo, $sufijo]) {
            $this->navegador($cookies, $metodo, '/api/especialidades'.$sufijo)->assertForbidden();
        }
        $this->assertSame(0, Especialidad::query()->count());
    }

    public function test_anonymous_csrf_origin_revoked_and_restricted_sessions_are_rejected(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/especialidades')->assertUnauthorized();
        $cookies = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Prueba'], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/especialidades', headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        DB::table('usuarios')->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'GET', '/api/especialidades')->assertUnauthorized();
        $this->assertSame(0, Especialidad::query()->count());
    }

    public function test_restricted_administrator_session_cannot_use_catalog(): void
    {
        $cookies = $this->administrador();
        $objetivo = $this->usuarioAuth();
        $secreto = $this->navegador($cookies, 'POST', '/api/usuarios/'.$objetivo->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restringida = $this->csrfAuth();
        $this->loginAuth($restringida, $objetivo, $secreto)->assertOk();
        $this->navegador($restringida, 'GET', '/api/especialidades')->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($restringida, 'POST', '/api/especialidades', ['nombre' => 'Prueba'])->assertForbidden();
    }

    public static function escrituras(): array
    {
        return [['crear'], ['editar'], ['estado']];
    }

    #[DataProvider('escrituras')]
    public function test_audit_failure_rolls_back_data_version_and_success_event(string $operacion): void
    {
        $cookies = $this->administrador();
        $f = $operacion === 'crear' ? null : $this->crear($cookies);
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('especialidadModificada')->once()->andThrow(new RuntimeException('Detalle privado simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $r = $operacion === 'crear'
            ? $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'No confirmar'])
            : $this->editar($cookies, $f, $operacion === 'estado' ? ['activa' => false] : ['descripcion' => 'No confirmar'], $operacion === 'estado' ? '/estado' : '');
        $r->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Detalle privado');
        if ($f !== null) {
            $this->navegador($cookies, 'GET', '/api/especialidades/'.$f['id_especialidad'])->assertExactJson(['data' => $f]);
        }
        $this->assertSame($f === null ? 0 : 1, Especialidad::query()->count());
        $this->assertSame(0, DB::table('bitacora')->whereIn('tipo_evento', ['ESPECIALIDAD_ACTUALIZADA', 'ESPECIALIDAD_ESTADO_CAMBIADO'])->count());
    }

    public function test_unrelated_unique_failure_is_not_misreported_as_duplicate_name(): void
    {
        $cookies = $this->administrador();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('especialidadModificada')->once()->andReturnUsing(function (Especialidad $especialidad): void {
            DB::table('especialidades')->insert(['id_especialidad' => $especialidad->getKey(), 'nombre' => 'Otro nombre']);
        });
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Prueba'])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertSame(0, Especialidad::query()->count());
    }

    public function test_additive_migration_initializes_existing_rows_without_changing_catalog(): void
    {
        $migration = require base_path('src/data/database/migrations/2026_10_21_000030_add_actualizado_en_to_especialidades.php');
        $migration->down();
        $id = DB::table('especialidades')->insertGetId(['nombre' => 'Anterior Ficticia', 'descripcion' => 'Conservar', 'activa' => false], 'id_especialidad');
        $migration->up();
        $row = DB::table('especialidades')->where('id_especialidad', $id)->first();
        $this->assertSame('Anterior Ficticia', $row->nombre);
        $this->assertSame('Conservar', $row->descripcion);
        $this->assertFalse($row->activa);
        $this->assertNotNull($row->actualizado_en);
        $column = DB::selectOne("SELECT data_type, datetime_precision, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'especialidades' AND column_name = 'actualizado_en'");
        $this->assertSame('timestamp with time zone', $column->data_type);
        $this->assertSame(6, $column->datetime_precision);
        $this->assertSame('NO', $column->is_nullable);
        $this->assertSame('CURRENT_TIMESTAMP', $column->column_default);
    }
}
