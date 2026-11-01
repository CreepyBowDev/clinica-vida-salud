<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\business\services\reportes\ReporteService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\reportes\ReporteRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReportesCitasTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $identity = DB::selectOne('SELECT current_database() AS db, current_user AS usuario');
        $this->assertSame('clinica_vida_salud_test', $identity->db);
        $this->assertSame('clinica_vida_salud_test', $identity->usuario);
        $namespaces = DB::table('pg_namespace')->orderBy('nspname')->pluck('nspname')->all();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($namespaces): void {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertSame($namespaces, DB::table('pg_namespace')->orderBy('nspname')->pluck('nspname')->all());
        });
        $schema = 'prueba_reporte_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local'; // CSRF activo, como el resto de pruebas SPA.
        Carbon::setTestNow('2026-11-02T02:00:00.123456Z');
        CarbonImmutable::setTestNow('2026-11-02T02:00:00.123456Z');
    }

    private function contexto(string $rol = 'Administrador'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return [$cookies, $this->citaResources(), $actor];
    }

    private function filtros(string $reporte, array $r): array
    {
        return match ($reporte) {
            'por-medico' => ['fecha' => '2026-11-01', 'id_medico' => (string) $r['doctors'][0]],
            'por-especialidad' => ['fecha' => '2026-11-01', 'id_especialidad' => (string) $r['specialty']],
            'por-estado' => ['fecha' => '2026-11-01', 'estado' => 'Cancelada'],
            default => ['fecha' => '2026-11-01'],
        };
    }

    private function consultar(array &$cookies, array $r, string $reporte = 'por-medico', array $extra = [])
    {
        return $this->navegador($cookies, 'GET', '/api/reportes/citas/'.$reporte.'?'.http_build_query([
            ...$this->filtros($reporte, $r), ...$extra]));
    }

    private function cita(array $r, array $extra = []): string
    {
        return (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'estado' => 'Cancelada', 'motivo_breve' => 'MOTIVO_PRIVADO_REPORTES', ...$extra]), 'id_cita');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['citas', 'pacientes', 'medicos', 'especialidades', 'consultorios', 'horarios_atencion', 'cambios_cita', 'recordatorios', 'bitacora'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }

    public static function reportes(): array
    {
        return [['programadas'], ['por-medico'], ['por-especialidad'], ['por-estado']];
    }

    #[DataProvider('reportes')]
    public function test_four_reports_have_private_projected_readonly_contracts(string $reporte): void
    {
        [$cookies, $r] = $this->contexto();
        $estado = $reporte === 'programadas' ? 'Programada' : 'Cancelada';
        $id = $this->cita($r, ['estado' => $estado]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['documento_identidad' => 'CI-PRIVADO-REP', 'telefono' => 'TEL-PRIVADO-REP']);
        $antes = $this->snapshot();
        $response = $this->consultar($cookies, $r, $reporte)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.reporte', 'citas/'.$reporte)->assertJsonPath('meta.zona_horaria', 'America/La_Paz')
            ->assertJsonPath('meta.consultado_en', '2026-11-02T02:00:00.123456Z')
            ->assertJsonPath('meta.periodo.inicio_at', '2026-11-01T04:00:00.000000Z')
            ->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-02T04:00:00.000000Z')
            ->assertJsonPath('meta.periodo.referencia', 'citas.inicio_at')->assertJsonPath('meta.periodo.criterio', 'inicio_en_periodo')
            ->assertJsonPath('meta.filtros.orden', 'fecha_asc')->assertJsonPath('meta.per_page', 20);
        $this->assertEqualsCanonicalizing(['id_cita', 'codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos', 'estado', 'modalidad',
            'sede_atencion', 'consultorio_atencion', 'creada_en', 'medico', 'especialidad', 'paciente'], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'], array_keys($response->json('data.0.paciente')));
        $this->assertEqualsCanonicalizing(['id_medico', 'codigo_medico', 'nombres', 'apellidos'], array_keys($response->json('data.0.medico')));
        $this->assertEqualsCanonicalizing(['id_especialidad', 'nombre'], array_keys($response->json('data.0.especialidad')));
        foreach (['motivo_breve', 'MOTIVO_PRIVADO', 'CI-PRIVADO', 'TEL-PRIVADO', 'password', '"consulta"', 'diagnostico', 'correo_contacto', 'fecha_nacimiento', 'creada_por'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $response->getContent());
        }
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_programadas_is_exact_and_filters_do_not_include_confirmada(): void
    {
        [$cookies, $r] = $this->contexto();
        $programada = $this->cita($r, ['estado' => 'Programada']);
        $this->cita($r, ['estado' => 'Confirmada', 'inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']);
        $this->cita($r);
        $this->consultar($cookies, $r, 'programadas')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_cita', $programada)->assertJsonPath('meta.filtros.estado', 'Programada');
        $this->consultar($cookies, $r, 'programadas', ['id_medico' => $r['doctors'][1]])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public static function estados(): array
    {
        return array_map(fn ($estado) => [$estado], ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia']);
    }

    #[DataProvider('estados')]
    public function test_each_state_is_selected_exactly_and_optional_filters_intersect(string $estado): void
    {
        [$cookies, $r] = $this->contexto();
        $id = $this->cita($r, ['estado' => $estado]);
        $this->cita($r, ['estado' => $estado, 'id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1],
            'inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']);
        $this->consultar($cookies, $r, 'por-estado', ['estado' => $estado, 'id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('meta.total', 1);
        $this->consultar($cookies, $r, 'por-medico', ['estado' => $estado])->assertOk()->assertJsonPath('meta.total', 1);
        $this->consultar($cookies, $r, 'por-especialidad', ['estado' => $estado, 'id_medico' => $r['doctors'][0]])
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_historical_specialty_and_location_survive_inactivity_and_current_assignments(): void
    {
        [$cookies, $r] = $this->contexto();
        $id = $this->cita($r);
        $other = DB::table('especialidades')->insertGetId(['nombre' => 'Otra especialidad ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $other]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        $this->consultar($cookies, $r, 'por-especialidad', ['id_medico' => $r['doctors'][0]])->assertOk()
            ->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('data.0.especialidad.id_especialidad', (string) $r['specialty'])
            ->assertJsonPath('data.0.consultorio_atencion', 'PRUEBA — Consultorio ficticio')->assertJsonPath('data.0.duracion_minutos', 15);
        $this->consultar($cookies, $r, 'por-especialidad', ['id_especialidad' => $other])->assertOk()->assertJsonPath('meta.total', 0);
        $this->navegador($cookies, 'GET', '/api/especialidades')->assertOk()->assertJsonFragment(['id_especialidad' => (string) $r['specialty'], 'activa' => false]);
        $this->navegador($cookies, 'GET', '/api/reservas/especialidades')->assertOk()->assertJsonMissing(['id_especialidad' => (string) $r['specialty']]);
    }

    public function test_period_uses_start_not_intersection_registration_or_updates_and_ignores_connection_timezone(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Auckland'");
        $this->cita($r, ['inicio_at' => '2026-11-01T03:59:59.999999Z', 'fin_at' => '2026-11-01T04:14:59.999999Z']);
        $first = $this->cita($r, ['inicio_at' => '2026-11-01T04:00:00Z', 'fin_at' => '2026-11-01T04:15:00Z', 'creada_en' => '2025-01-01T00:00:00Z']);
        $last = $this->cita($r, ['inicio_at' => '2026-11-02T03:59:59.999999Z', 'fin_at' => '2026-11-02T04:14:59.999999Z']);
        $next = $this->cita($r, ['inicio_at' => '2026-11-02T04:00:00Z', 'fin_at' => '2026-11-02T04:15:00Z']);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id_cita', $first)->assertJsonPath('data.1.id_cita', $last)
            ->assertJsonPath('data.1.inicio_at', '2026-11-02T03:59:59.999999Z');
        $this->consultar($cookies, $r, extra: ['fecha' => null, 'desde' => '2026-11-01', 'hasta' => '2026-11-02'])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.2.id_cita', $next)
            ->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-03T04:00:00.000000Z');
    }

    public function test_order_bigint_pagination_links_and_out_of_range_page_are_stable(): void
    {
        [$cookies, $r] = $this->contexto();
        $large = '9223372036854775807';
        $this->cita($r, ['id_cita' => $large]);
        $small = $this->cita($r);
        $first = $this->consultar($cookies, $r, 'por-estado', ['per_page' => 1])->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id_cita', $small);
        $this->navegador($cookies, 'GET', $first->json('links.next'))->assertOk()->assertJsonPath('data.0.id_cita', $large);
        $this->consultar($cookies, $r, 'por-estado', ['per_page' => 1, 'orden' => 'fecha_desc'])->assertOk()->assertJsonPath('data.0.id_cita', $large);
        $this->consultar($cookies, $r, 'por-estado', ['page' => 3, 'per_page' => 1])->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.filtros.estado', 'Cancelada');
        $this->consultar($cookies, $r, 'por-estado', ['fecha' => '2027-01-01'])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $programadas = $this->consultar($cookies, $r, 'programadas', ['per_page' => 1])->assertOk();
        $this->assertStringNotContainsString('estado=', $programadas->json('links.first'));
    }

    public function test_filtered_page_and_count_use_one_closed_projected_statement(): void
    {
        [$cookies, $r] = $this->contexto();
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = $this->citaRow($r, ['estado' => 'Cancelada', 'motivo_breve' => 'PRIVADO']);
        }
        DB::table('citas')->insert($rows);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH filtradas')) {
                $queries[] = $query->sql;
            }
        });
        $this->consultar($cookies, $r, extra: ['per_page' => 1])->assertOk()->assertJsonPath('meta.total', 120)->assertJsonCount(1, 'data');
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('COUNT(*)', $queries[0]);
        foreach (['motivo_breve', 'documento_identidad', 'correo_contacto', 'consultas', 'SELECT * FROM "citas"'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $queries[0]);
        }
    }

    public static function entradasInvalidas(): array
    {
        return [
            'sin fecha' => ['por-medico', ['fecha' => null]],
            'rango incompleto' => ['por-medico', ['fecha' => null, 'desde' => '2026-11-01']],
            'mezcla' => ['por-medico', ['desde' => '2026-11-01', 'hasta' => '2026-11-02']],
            'invertido' => ['por-medico', ['fecha' => null, 'desde' => '2026-11-02', 'hasta' => '2026-11-01']],
            '367 dias' => ['por-medico', ['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-02']],
            'imposible' => ['por-medico', ['fecha' => '2026-02-29']],
            'ano cero' => ['por-medico', ['fecha' => '0000-01-01']],
            'extremo' => ['por-medico', ['fecha' => '9999-12-31']],
            'array fecha' => ['por-medico', ['fecha' => ['2026-11-01']]],
            'medico requerido' => ['por-medico', ['id_medico' => null]],
            'especialidad requerida' => ['por-especialidad', ['id_especialidad' => null]],
            'estado requerido' => ['por-estado', ['estado' => null]],
            'estado inexistente' => ['por-estado', ['estado' => 'Pendiente']],
            'array estado' => ['por-estado', ['estado' => ['Cancelada']]],
            'id con cero' => ['por-medico', ['id_medico' => '01']],
            'id exponente' => ['por-medico', ['id_medico' => '1e1']],
            'id negativo' => ['por-especialidad', ['id_especialidad' => '-1']],
            'id enorme' => ['por-medico', ['id_medico' => '9223372036854775808']],
            'array id' => ['por-medico', ['id_medico' => ['1']]],
            'sql id' => ['por-medico', ['id_medico' => '1 OR 1=1']],
            'sql orden' => ['por-medico', ['orden' => 'inicio_at DESC; DROP TABLE citas']],
            'orden desconocido' => ['por-medico', ['orden' => 'nombre_asc']],
            'pagina cero' => ['por-medico', ['page' => 0]],
            'pagina enorme' => ['por-medico', ['page' => 1000001]],
            'tamano enorme' => ['por-medico', ['per_page' => 101]],
            'array paginacion' => ['por-medico', ['per_page' => ['20']]],
            'campo ajeno' => ['por-medico', ['id_paciente' => 1]],
            'zona ajena' => ['por-medico', ['zona_horaria' => 'UTC']],
            'tipo cliente' => ['por-medico', ['reporte' => 'citas/programadas']],
            'exportacion' => ['por-medico', ['formato' => 'csv']],
            'estado fijo' => ['programadas', ['estado' => 'Programada']],
            'programadas rango' => ['programadas', ['fecha' => null, 'desde' => '2026-11-01', 'hasta' => '2026-11-01']],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_filters_are_closed_and_invalid_requests_are_422(string $reporte, array $extra): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r, $reporte, $extra)->assertUnprocessable()->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDACION_ERROR')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_valid_leap_day_max_period_and_representable_extremes_are_accepted(): void
    {
        [$cookies, $r] = $this->contexto();
        foreach (['2028-02-29', '0001-01-01', '9999-12-30'] as $fecha) {
            $this->consultar($cookies, $r, extra: ['fecha' => $fecha])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->consultar($cookies, $r, extra: ['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-01'])->assertOk()
            ->assertJsonPath('meta.periodo.desde', '2026-01-01')->assertJsonPath('meta.periodo.hasta', '2027-01-01');
    }

    public function test_missing_entities_are_404_but_existing_empty_entities_are_not_errors(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0);
        $this->consultar($cookies, $r, extra: ['id_medico' => '9223372036854775807'])->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
        $this->consultar($cookies, $r, 'por-especialidad', ['id_especialidad' => '9223372036854775807'])->assertNotFound();
        $this->consultar($cookies, $r, 'por-estado', ['id_especialidad' => '9223372036854775807'])->assertNotFound();
    }

    public static function rolesDenegados(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('rolesDenegados')]
    public function test_other_roles_cannot_access_any_report(string $rol): void
    {
        [$cookies, $r, $actor] = $this->contexto($rol);
        foreach (self::reportes() as [$reporte]) {
            $this->consultar($cookies, $r, $reporte)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO')->assertJsonMissingPath('data');
        }
        // El Service también deniega una llamada directa, sin depender del middleware.
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('exigirSesionNormal')->with(true)->once()->andReturn($actor);
        $reports = Mockery::mock(ReporteRepository::class);
        $reports->shouldNotReceive('citas');
        $this->expectExceptionObject(new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.'));
        (new ReporteService($auth, $reports, Mockery::mock(AgendaService::class)))->citas('citas/por-medico', $this->filtros('por-medico', $r));
    }

    public function test_authentication_origin_body_methods_and_required_report_filters_are_not_bypassed(): void
    {
        $cookies = $this->csrfAuth();
        foreach (self::reportes() as [$reporte]) {
            $this->navegador($cookies, 'GET', '/api/reportes/citas/'.$reporte)->assertUnauthorized();
        }
        [$cookies, $r] = $this->contexto();
        $uri = '/api/reportes/citas/por-medico?'.http_build_query($this->filtros('por-medico', $r));
        $this->navegador($cookies, 'GET', $uri, ['estado' => 'Cancelada'])->assertUnprocessable()->assertJsonValidationErrors('body', 'error.details');
        $this->navegador($cookies, 'GET', $uri, headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/reportes/citas/por-medico')->assertStatus(405);
        foreach (['/api/reportes/citas/cantidades', '/api/reportes/agenda-diaria'] as $sinFiltros) {
            $this->navegador($cookies, 'GET', $sinFiltros)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
        }
        DB::table('sessions')->delete();
        $this->navegador($cookies, 'GET', $uri)->assertUnauthorized();
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['sesion']];
    }

    public function test_restricted_session_cannot_query_reports(): void
    {
        [$cookies] = $this->contexto();
        $target = $this->usuarioAuth();
        $receipt = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $receipt['credencial_temporal'])->assertOk()->assertJsonPath('data.acceso', 'cambio_clave');
        foreach (self::reportes() as [$reporte]) {
            $this->navegador($restricted, 'GET', '/api/reportes/citas/'.$reporte)->assertForbidden()
                ->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        }
    }

    #[DataProvider('revocaciones')]
    public function test_actor_is_revalidated_after_middleware_before_querying_reports(string $cambio): void
    {
        [$cookies, $r] = $this->contexto();
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original, $cambio) {
            if ($cambio === 'estado') {
                DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada']);
            } elseif ($cambio === 'rol') {
                $rol = DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol');
                DB::table('usuarios')->where('id_usuario', $id)->update(['id_rol' => $rol]);
            } else {
                DB::table('sessions')->where('user_id', $id)->delete();
            }

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $reports = Mockery::mock(ReporteRepository::class);
        $reports->shouldNotReceive('citas');
        $reports->shouldNotReceive('existeMedico');
        $this->app->instance(ReporteRepository::class, $reports);
        $this->consultar($cookies, $r)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO');
    }

    public function test_unexpected_database_failure_is_safe_and_does_not_expose_sql_or_clinical_data(): void
    {
        [$cookies, $r] = $this->contexto();
        $mock = Mockery::mock(ReporteRepository::class)->makePartial();
        $mock->shouldReceive('citas')->once()->andThrow(new RuntimeException('SQL PRIVADO SELECT motivo_breve FROM citas'));
        $this->app->instance(ReporteRepository::class, $mock);
        $response = $this->consultar($cookies, $r)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertJsonMissingPath('data');
        $this->assertStringNotContainsString('PRIVADO', $response->getContent());
        $this->assertStringNotContainsString('SELECT', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }
}
