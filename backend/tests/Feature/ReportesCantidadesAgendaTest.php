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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReportesCantidadesAgendaTest extends TestCase
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
        $schema = 'prueba_reporte_periodo_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        Carbon::setTestNow('2026-11-02T02:00:00.123456Z');
        CarbonImmutable::setTestNow('2026-11-02T02:00:00.123456Z');
        Mail::fake();
    }

    private function contexto(string $rol = 'Administrador'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return [$cookies, $this->citaResources(), $actor];
    }

    private function cita(array $r, string $estado = 'Cancelada', array $extra = []): string
    {
        $inicio = CarbonImmutable::parse($extra['inicio_at'] ?? '2026-11-01T12:00:00Z');

        return (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado,
            'motivo_breve' => 'MOTIVO_PRIVADO_REP4', 'inicio_at' => $inicio->format('Y-m-d H:i:s.uP'),
            'fin_at' => $inicio->addMinutes($extra['duracion_minutos'] ?? 15)->format('Y-m-d H:i:s.uP'), ...$extra]), 'id_cita');
    }

    private function consultar(array &$cookies, array $r, string $reporte = 'citas/cantidades', array $extra = [])
    {
        return $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte.'?'.http_build_query([
            'fecha' => '2026-11-01', ...($reporte === 'agenda-diaria' ? ['id_medico' => (string) $r['doctors'][0]] : []), ...$extra]));
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['citas', 'pacientes', 'medicos', 'especialidades', 'consultorios', 'cambios_cita', 'preferencias_paciente', 'recordatorios', 'envios_recordatorio_push', 'bitacora'] as $tabla) {
            $result[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $result;
    }

    public function test_empty_quantities_are_four_integer_zeros_without_pagination(): void
    {
        [$cookies, $r] = $this->contexto();
        $response = $this->consultar($cookies, $r)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0],
                'meta' => ['reporte' => 'citas/cantidades', 'zona_horaria' => 'America/La_Paz', 'consultado_en' => '2026-11-02T02:00:00.123456Z',
                    'filtros' => ['fecha' => '2026-11-01', 'desde' => null, 'hasta' => null, 'id_medico' => null,
                        'id_especialidad' => null, 'estados' => ['Atendida', 'Cancelada', 'Inasistencia']],
                    'periodo' => ['desde' => '2026-11-01', 'hasta' => '2026-11-01', 'inicio_at' => '2026-11-01T04:00:00.000000Z',
                        'fin_exclusivo_at' => '2026-11-02T04:00:00.000000Z', 'referencia' => 'citas.inicio_at', 'criterio' => 'inicio_en_periodo']]])
            ->assertJsonMissingPath('links')->assertJsonMissingPath('meta.current_page');
        $this->assertIsInt($response->json('data.total'));
    }

    public function test_quantities_exclude_programada_confirmada_and_aggregate_once_without_writes_or_private_rows(): void
    {
        [$cookies, $r] = $this->contexto();
        foreach (['Atendida', 'Cancelada', 'Inasistencia', 'Programada', 'Confirmada', 'Atendida'] as $i => $estado) {
            $this->cita($r, $estado, ['inicio_at' => CarbonImmutable::parse('2026-11-01T12:00:00Z')->addMinutes($i * 30)->format('Y-m-d\TH:i:s.u\Z')]);
        }
        $antes = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'COUNT(*) FILTER')) {
                $queries[] = $query->sql;
            }
        });
        $response = $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data', ['atendidas' => 2, 'canceladas' => 1, 'inasistencias' => 1, 'total' => 4]);
        $this->assertCount(1, $queries);
        foreach (['motivo_breve', 'documento_identidad', 'consultas', 'cambios_cita', 'SELECT *', 'join'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $queries[0]);
        }
        foreach (['MOTIVO_PRIVADO', 'id_cita', 'paciente', 'codigo_cita', 'nombres'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $response->getContent());
        }
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_quantities_filter_by_historical_specialty_and_medico_even_inactive_without_current_affiliation(): void
    {
        [$cookies, $r] = $this->contexto();
        $other = DB::table('especialidades')->insertGetId(['nombre' => 'Otra especialidad ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $other]);
        $this->cita($r);
        $this->cita($r, extra: ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]);
        $this->cita($r, 'Atendida', ['id_especialidad' => $other]);
        DB::table('medico_especialidad')->where('id_especialidad', $r['specialty'])->update(['habilitada' => false]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 3);
        $this->consultar($cookies, $r, extra: ['id_medico' => $r['doctors'][0]])->assertOk()->assertJsonPath('data.total', 2);
        $this->consultar($cookies, $r, extra: ['id_especialidad' => $r['specialty']])->assertOk()->assertJsonPath('data.total', 2);
        $this->consultar($cookies, $r, extra: ['id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']])->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('meta.filtros.id_medico', (string) $r['doctors'][0]);
        $this->consultar($cookies, $r, extra: ['id_medico' => $r['doctors'][1], 'id_especialidad' => $other])->assertOk()->assertJsonPath('data.total', 0);
        $this->consultar($cookies, $r, extra: ['id_medico' => '9223372036854775807'])->assertNotFound();
        $this->consultar($cookies, $r, extra: ['id_especialidad' => '9223372036854775807'])->assertNotFound();
    }

    public function test_local_microsecond_boundaries_and_range_use_start_not_intersection_or_creation(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Auckland'");
        foreach (['2026-11-01T03:59:59.999999Z', '2026-11-01T04:00:00Z', '2026-11-02T03:59:59.999999Z', '2026-11-02T04:00:00Z'] as $inicio) {
            $this->cita($r, extra: ['inicio_at' => $inicio, 'creada_en' => '2020-01-01T00:00:00Z']);
        }
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.canceladas', 2)->assertJsonPath('data.total', 2);
        $this->consultar($cookies, $r, extra: ['fecha' => null, 'desde' => '2026-11-01', 'hasta' => '2026-11-02'])->assertOk()
            ->assertJsonPath('data.total', 3)->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-03T04:00:00.000000Z');
    }

    public function test_counts_follow_current_programming_and_state_not_dates_or_number_of_change_events(): void
    {
        [$cookies, $r] = $this->contexto();
        $inside = $this->cita($r);
        $outside = $this->cita($r, extra: ['inicio_at' => '2026-11-03T12:00:00Z']);
        foreach ([[$inside, '2026-10-31T12:00:00Z', 'Confirmada'], [$inside, '2026-11-02T12:00:00Z', 'Cancelada'], [$outside, '2026-11-01T12:00:00Z', 'Cancelada']] as [$id, $realizado, $estado]) {
            DB::table('cambios_cita')->insert(['id_cita' => $id, 'id_actor' => $r['actor'], 'operacion' => 'CAMBIO_FICTICIO',
                'realizado_en' => $realizado, 'estado_nuevo' => $estado, 'datos_nuevos' => '{}']);
        }
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.canceladas', 1);
        $reprogramada = $this->cita($r, 'Programada', ['inicio_at' => '2026-11-01T13:00:00Z']);
        DB::table('citas')->where('id_cita', $reprogramada)->update(['inicio_at' => '2026-11-03T13:00:00Z',
            'fin_at' => '2026-11-03T13:15:00Z', 'version_programacion' => 2]);
        DB::table('citas')->where('id_cita', $reprogramada)->update(['estado' => 'Atendida']);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.atendidas', 0);
        $this->consultar($cookies, $r, extra: ['fecha' => '2026-11-03'])->assertOk()->assertJsonPath('data.atendidas', 1)->assertJsonPath('data.total', 2);
        $current = $this->cita($r, 'Programada', ['inicio_at' => '2026-11-01T14:00:00Z']);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 1);
        DB::table('citas')->where('id_cita', $current)->update(['estado' => 'Confirmada']);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 1);
        DB::table('citas')->where('id_cita', $current)->update(['estado' => 'Atendida']);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.atendidas', 1);
    }

    public static function estados(): array
    {
        return array_map(fn ($estado) => [$estado], ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia']);
    }

    #[DataProvider('estados')]
    public function test_report_agenda_is_equivalent_to_operational_agenda_for_each_state(string $estado): void
    {
        [$cookies, $r] = $this->contexto();
        $id = $this->cita($r, $estado);
        $report = $this->consultar($cookies, $r, 'agenda-diaria', ['estado' => $estado])->assertOk()->assertJsonPath('data.0.id_cita', $id)
            ->assertJsonPath('meta.reporte', 'agenda-diaria')->assertJsonPath('meta.periodo.criterio', 'interseccion_diaria')
            ->assertJsonPath('meta.periodo.inicio_at', '2026-11-01T04:00:00.000000Z')->assertHeader('Cache-Control', 'no-store, private');
        $operational = $this->navegador($cookies, 'GET', '/api/agenda?'.http_build_query([
            'id_medico' => $r['doctors'][0], 'fecha' => '2026-11-01', 'estado' => $estado]))->assertOk();
        $this->assertSame($operational->json('data'), $report->json('data'));
        foreach (['total', 'medico', 'filtros', 'zona_horaria', 'consultado_en'] as $campo) {
            $this->assertSame($operational->json('meta.'.$campo), $report->json('meta.'.$campo));
        }
        $operational->assertJsonMissingPath('meta.reporte')->assertJsonMissingPath('meta.periodo');
    }

    public function test_agenda_intersection_at_midnight_is_not_a_quantity_by_start(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, extra: ['inicio_at' => '2026-11-01T03:45:00Z']); // Termina al inicio del día, excluida.
        $cross = $this->cita($r, extra: ['inicio_at' => '2026-11-01T03:45:00Z', 'duracion_minutos' => 30]);
        $first = $this->cita($r, extra: ['inicio_at' => '2026-11-01T04:00:00Z']);
        $last = $this->cita($r, extra: ['inicio_at' => '2026-11-02T03:59:59.999999Z']);
        $this->cita($r, extra: ['inicio_at' => '2026-11-02T04:00:00Z']);
        $this->consultar($cookies, $r, 'agenda-diaria')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id_cita', $cross)->assertJsonPath('data.1.id_cita', $first)->assertJsonPath('data.2.id_cita', $last);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_agenda_keeps_historical_location_minimal_fields_bigint_order_links_and_empty_context(): void
    {
        [$cookies, $r] = $this->contexto();
        $large = '9223372036854775807';
        $this->cita($r, extra: ['id_cita' => $large]);
        $small = $this->cita($r);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false, 'documento_identidad' => 'CI_PRIVADO_REP4']);
        $before = $this->snapshot();
        $first = $this->consultar($cookies, $r, 'agenda-diaria', ['per_page' => 1])->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id_cita', $small)->assertJsonPath('data.0.consultorio_atencion', 'PRUEBA — Consultorio ficticio')
            ->assertJsonPath('data.0.duracion_minutos', 15)->assertJsonPath('meta.medico.id_medico', (string) $r['doctors'][0]);
        foreach (['motivo_breve', 'MOTIVO_PRIVADO', 'CI_PRIVADO', 'correo_contacto', 'telefono', 'fecha_nacimiento', 'password', 'id_propuesta'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $first->getContent());
        }
        $this->assertStringContainsString('/api/reportes/agenda-diaria?', $first->json('links.next'));
        $this->navegador($cookies, 'GET', $first->json('links.next'))->assertOk()->assertJsonPath('data.0.id_cita', $large);
        $this->consultar($cookies, $r, 'agenda-diaria', ['page' => 3, 'per_page' => 1])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
        $this->consultar($cookies, $r, 'agenda-diaria', ['fecha' => '2030-01-01'])->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.medico.id_medico', (string) $r['doctors'][0]);
        $this->consultar($cookies, $r, 'agenda-diaria', ['id_medico' => '9223372036854775807'])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_agenda_delegates_validated_inputs_once_and_uses_existing_single_page_count_query(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r);
        $original = $this->app->make(AgendaService::class);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('consultar')->once()->with(['fecha' => '2026-11-01', 'id_medico' => (string) $r['doctors'][0],
            'estado' => 'Cancelada', 'page' => '1', 'per_page' => '1'])->andReturnUsing(fn ($datos) => $original->consultar($datos));
        $this->app->instance(AgendaService::class, $agenda);
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldNotReceive('citas');
        $repository->shouldNotReceive('cantidades');
        $repository->shouldNotReceive('existeMedico');
        $this->app->instance(ReporteRepository::class, $repository);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH filtradas')) {
                $queries[] = $query->sql;
            }
        });
        $this->consultar($cookies, $r, 'agenda-diaria', ['estado' => 'Cancelada', 'page' => 1, 'per_page' => 1])->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('total_agenda', $queries[0]);
    }

    public static function entradasInvalidas(): array
    {
        return [
            'cantidades sin fecha' => ['citas/cantidades', ['fecha' => null]],
            'cantidades rango incompleto' => ['citas/cantidades', ['fecha' => null, 'desde' => '2026-11-01']],
            'cantidades mezcla' => ['citas/cantidades', ['desde' => '2026-11-01', 'hasta' => '2026-11-02']],
            'cantidades invertido' => ['citas/cantidades', ['fecha' => null, 'desde' => '2026-11-02', 'hasta' => '2026-11-01']],
            'cantidades 367 dias' => ['citas/cantidades', ['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-02']],
            'cantidades estado fijo' => ['citas/cantidades', ['estado' => 'Cancelada']],
            'cantidades estados fijos' => ['citas/cantidades', ['estados' => ['Cancelada']]],
            'cantidades no pagina' => ['citas/cantidades', ['page' => 1]],
            'cantidades no tamano' => ['citas/cantidades', ['per_page' => 1]],
            'cantidades no orden' => ['citas/cantidades', ['orden' => 'fecha_desc']],
            'cantidades no grupos' => ['citas/cantidades', ['agrupar' => 'mes']],
            'cantidades id enorme' => ['citas/cantidades', ['id_especialidad' => '9223372036854775808']],
            'cantidades id array' => ['citas/cantidades', ['id_medico' => ['1']]],
            'cantidades id sql' => ['citas/cantidades', ['id_medico' => '1 OR 1=1']],
            'cantidades fecha array' => ['citas/cantidades', ['fecha' => ['2026-11-01']]],
            'cantidades fecha imposible' => ['citas/cantidades', ['fecha' => '2026-02-29']],
            'cantidades extremo' => ['citas/cantidades', ['fecha' => '9999-12-31']],
            'agenda sin fecha' => ['agenda-diaria', ['fecha' => null]],
            'agenda sin medico' => ['agenda-diaria', ['id_medico' => null]],
            'agenda id no canonico' => ['agenda-diaria', ['id_medico' => '01']],
            'agenda id array' => ['agenda-diaria', ['id_medico' => ['1']]],
            'agenda sin rango' => ['agenda-diaria', ['desde' => '2026-11-01', 'hasta' => '2026-11-01']],
            'agenda orden fijo' => ['agenda-diaria', ['orden' => 'fecha_asc']],
            'agenda sin especialidad' => ['agenda-diaria', ['id_especialidad' => 1]],
            'agenda estado invalido' => ['agenda-diaria', ['estado' => 'Pendiente']],
            'agenda estado array' => ['agenda-diaria', ['estado' => ['Cancelada']]],
            'agenda pagina cero' => ['agenda-diaria', ['page' => 0]],
            'agenda tamano enorme' => ['agenda-diaria', ['per_page' => 101]],
            'agenda zona ajena' => ['agenda-diaria', ['zona_horaria' => 'UTC']],
            'agenda exportacion' => ['agenda-diaria', ['formato' => 'pdf']],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_new_reports_keep_closed_validated_inputs(string $reporte, array $extra): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r, $reporte, $extra)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR')
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data');
    }

    public function test_valid_period_limits_and_dates_are_not_reservation_restrictions(): void
    {
        [$cookies, $r] = $this->contexto();
        foreach (['0001-01-01', '2028-02-29', '9999-12-30'] as $fecha) {
            $this->consultar($cookies, $r, extra: ['fecha' => $fecha])->assertOk()->assertJsonPath('data.total', 0);
            $this->consultar($cookies, $r, 'agenda-diaria', ['fecha' => $fecha])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->consultar($cookies, $r, extra: ['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-01'])->assertOk()
            ->assertJsonPath('meta.periodo.desde', '2026-01-01')->assertJsonPath('meta.periodo.hasta', '2027-01-01');
    }

    public static function rolesDenegados(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('rolesDenegados')]
    public function test_both_reports_are_admin_only_even_if_operational_agenda_is_allowed(string $rol): void
    {
        [$cookies, $r, $actor] = $this->contexto($rol);
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            $this->consultar($cookies, $r, $reporte)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
        }
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('exigirSesionNormal')->with(true)->twice()->andReturn($actor);
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldNotReceive('cantidades');
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldNotReceive('consultar');
        $service = new ReporteService($auth, $repository, $agenda);
        foreach (['cantidades', 'agendaDiaria'] as $metodo) {
            try {
                $service->{$metodo}(['fecha' => '2026-11-01', 'id_medico' => (string) $r['doctors'][0]]);
                $this->fail('El Service debe exigir Administrador.');
            } catch (AppError $error) {
                $this->assertSame('ACCESO_DENEGADO', $error->codigo);
            }
        }
    }

    public function test_guest_origin_body_methods_restricted_and_expired_session_are_enforced(): void
    {
        $cookies = $this->csrfAuth();
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            $this->navegador($cookies, 'GET', '/api/reportes/'.$reporte)->assertUnauthorized();
        }
        [$cookies, $r] = $this->contexto();
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            $uri = '/api/reportes/'.$reporte.'?fecha=2026-11-01&id_medico='.$r['doctors'][0];
            $this->navegador($cookies, 'GET', $uri, ['fecha' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('body', 'error.details');
            $this->navegador($cookies, 'GET', $uri, headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
            $this->navegador($cookies, 'POST', '/api/reportes/'.$reporte)->assertStatus(405);
        }
        $target = $this->usuarioAuth();
        $receipt = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])
            ->assertCreated()->json('data');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $receipt['credencial_temporal'])->assertOk();
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            $this->consultar($restricted, $r, $reporte)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        }
        DB::table('sessions')->where('last_activity', '>', 0)->update(['last_activity' => 1]);
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            $this->consultar($cookies, $r, $reporte)->assertUnauthorized();
        }
        Mail::assertNothingSent();
    }

    public static function revocaciones(): array
    {
        $result = [];
        foreach (['citas/cantidades', 'agenda-diaria'] as $reporte) {
            foreach (['estado', 'rol', 'sesion'] as $cambio) {
                $result[] = [$reporte, $cambio];
            }
        }

        return $result;
    }

    #[DataProvider('revocaciones')]
    public function test_revocation_after_middleware_denies_before_aggregation_or_delegation(string $reporte, string $cambio): void
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
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldNotReceive('cantidades');
        $repository->shouldNotReceive('existeMedico');
        $this->app->instance(ReporteRepository::class, $repository);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldNotReceive('consultar');
        $this->app->instance(AgendaService::class, $agenda);
        $this->consultar($cookies, $r, $reporte)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO');
    }

    public static function reportesNuevos(): array
    {
        return [['citas/cantidades'], ['agenda-diaria']];
    }

    #[DataProvider('reportesNuevos')]
    public function test_failures_are_safe_without_sql_or_sensitive_data(string $reporte): void
    {
        [$cookies, $r] = $this->contexto();
        if ($reporte === 'citas/cantidades') {
            $repository = Mockery::mock(ReporteRepository::class)->makePartial();
            $repository->shouldReceive('cantidades')->once()->andThrow(new RuntimeException('SQL SELECT motivo_breve PRIVADO'));
            $this->app->instance(ReporteRepository::class, $repository);
        } else {
            $agenda = Mockery::mock(AgendaService::class);
            $agenda->shouldReceive('consultar')->once()->andThrow(new RuntimeException('SQL SELECT motivo_breve PRIVADO'));
            $this->app->instance(AgendaService::class, $agenda);
        }
        $response = $this->consultar($cookies, $r, $reporte)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertJsonMissingPath('data');
        foreach (['SELECT', 'PRIVADO', 'motivo_breve', 'trace'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $response->getContent());
        }
    }
}
