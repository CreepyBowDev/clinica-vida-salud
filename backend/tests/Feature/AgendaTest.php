<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AgendaTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-02T02:00:00.123456Z'); // Noviembre 1 en La Paz.
    }

    private function contexto(string $rol = 'Recepcionista'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Médico') {
            $r['doctors'][0] = $actor->medico->getKey();
            DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']]);
        }

        return [$cookies, $r, $actor];
    }

    private function consultar(array &$cookies, array $r, array $extra = [], bool $propia = false)
    {
        return $this->navegador($cookies, 'GET', '/api/agenda?'.http_build_query([
            ...($propia ? [] : ['id_medico' => (string) $r['doctors'][0]]), ...$extra]));
    }

    private function cita(array $r, array $extra = []): string
    {
        return (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'motivo_breve' => 'MOTIVO_SENSIBLE_NO_LISTAR', ...$extra]), 'id_cita');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['citas', 'pacientes', 'medicos', 'especialidades', 'consultorios', 'horarios_atencion', 'cambios_cita', 'recordatorios', 'bitacora'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }

    public static function rolesAgenda(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    #[DataProvider('rolesAgenda')]
    public function test_agenda_is_projected_private_and_readonly_for_authorized_roles(string $rol): void
    {
        [$cookies, $r] = $this->contexto($rol);
        $id = $this->cita($r);
        $this->cita($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1], 'estado' => 'Cancelada']);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['documento_identidad' => 'CI-PRIVADO', 'telefono' => 'TEL-PRIVADO']);
        $antes = $this->snapshot();
        $response = $this->consultar($cookies, $r, propia: $rol === 'Médico')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.filtros.fecha', '2026-11-01')->assertJsonPath('meta.filtros.estado', null)
            ->assertJsonPath('meta.filtros.id_medico', (string) $r['doctors'][0])
            ->assertJsonPath('meta.zona_horaria', 'America/La_Paz')->assertJsonPath('meta.consultado_en', '2026-11-02T02:00:00.123456Z');
        $row = $response->json('data.0');
        $this->assertEqualsCanonicalizing(['id_cita', 'codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos', 'estado', 'modalidad',
            'sede_atencion', 'consultorio_atencion', 'creada_en', 'medico', 'especialidad', 'paciente'], array_keys($row));
        $this->assertEqualsCanonicalizing(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'], array_keys($row['paciente']));
        $this->assertEqualsCanonicalizing(['id_medico', 'codigo_medico', 'nombres', 'apellidos'], array_keys($row['medico']));
        foreach (['motivo_breve', 'MOTIVO_SENSIBLE', 'CI-PRIVADO', 'TEL-PRIVADO', 'fecha_nacimiento', 'password', 'id_propuesta', 'creada_por', 'registro_profesional', 'correo_contacto'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
        $this->assertSame('2026-11-01T12:00:00.000000Z', $row['inicio_at']);
        $this->assertSame('2026-11-01T12:15:00.000000Z', $row['fin_at']);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_selector_includes_inactive_professionals_without_accounts_or_schedules_but_not_contact(): void
    {
        [$cookies, $r] = $this->contexto();
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'apellidos' => 'A', 'nombres' => 'Literal%_ Ficticio']);
        $response = $this->navegador($cookies, 'GET', '/api/agenda/medicos?nombre='.urlencode('literal%_').'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_medico', (string) $r['doctors'][0])
            ->assertJsonPath('data.0.activo', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEqualsCanonicalizing(['id_medico', 'codigo_medico', 'nombres', 'apellidos', 'activo'], array_keys($response->json('data.0')));
        $code = DB::table('medicos')->where('id_medico', $r['doctors'][1])->value('codigo_medico');
        $this->navegador($cookies, 'GET', '/api/agenda/medicos?nombre='.$code)->assertOk()->assertJsonPath('meta.total', 1);
        $this->navegador($cookies, 'GET', '/api/medicos')->assertForbidden(); // No se amplían permisos administrativos.
    }

    public function test_selector_order_pagination_empty_and_literal_search_are_stable(): void
    {
        [$cookies, $r] = $this->contexto('Administrador');
        DB::table('medicos')->whereIn('id_medico', $r['doctors'])->update(['nombres' => 'Igual', 'apellidos' => 'Igual']);
        $first = $this->navegador($cookies, 'GET', '/api/agenda/medicos?per_page=1')->assertOk()->assertJsonPath('meta.total', 2);
        $second = $this->navegador($cookies, 'GET', '/api/agenda/medicos?per_page=1&page=2')->assertOk();
        $this->assertSame((string) $r['doctors'][0], $first->json('data.0.id_medico'));
        $this->assertSame((string) $r['doctors'][1], $second->json('data.0.id_medico'));
        $this->navegador($cookies, 'GET', '/api/agenda/medicos?per_page=1&page=3')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
        $this->navegador($cookies, 'GET', '/api/agenda/medicos?nombre='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public static function estados(): array
    {
        return array_map(fn ($s) => [$s], ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia']);
    }

    #[DataProvider('estados')]
    public function test_each_real_state_can_be_read_without_transition(string $estado): void
    {
        [$cookies, $r] = $this->contexto();
        $id = $this->cita($r, ['estado' => $estado]);
        $this->consultar($cookies, $r, ['estado' => $estado])->assertOk()->assertJsonPath('data.0.estado', $estado);
        $other = $estado === 'Cancelada' ? 'Programada' : 'Cancelada';
        $this->consultar($cookies, $r, ['estado' => $other])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($estado, DB::table('citas')->where('id_cita', $id)->value('estado'));
    }

    public function test_day_intersection_handles_local_midnight_adjacent_boundaries_and_multiday(): void
    {
        [$cookies, $r] = $this->contexto();
        $excluded = $this->cita($r, ['estado' => 'Cancelada', 'inicio_at' => '2026-11-01T03:45:00Z', 'fin_at' => '2026-11-01T04:00:00Z']);
        $cross = $this->cita($r, ['estado' => 'Cancelada', 'inicio_at' => '2026-10-31T12:00:00Z', 'fin_at' => '2026-11-01T12:00:00Z', 'duracion_minutos' => 1440]);
        $start = $this->cita($r, ['estado' => 'Cancelada', 'inicio_at' => '2026-11-01T04:00:00Z', 'fin_at' => '2026-11-01T04:15:00Z']);
        $late = $this->cita($r, ['estado' => 'Cancelada', 'inicio_at' => '2026-11-02T03:45:00.000001Z', 'fin_at' => '2026-11-02T04:00:00.000001Z']);
        $next = $this->cita($r, ['estado' => 'Cancelada', 'inicio_at' => '2026-11-02T04:00:00Z', 'fin_at' => '2026-11-02T04:15:00Z']);
        $ids = array_column($this->consultar($cookies, $r)->assertOk()->json('data'), 'id_cita');
        $this->assertSame([$cross, $start, $late], $ids);
        $this->assertNotContains($excluded, $ids);
        $this->assertNotContains($next, $ids);
    }

    public function test_existing_empty_doctor_and_out_of_range_page_keep_context_and_total(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.medico.id_medico', (string) $r['doctors'][0]);
        $id = $this->cita($r);
        $this->consultar($cookies, $r, ['page' => 2, 'per_page' => 1])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1);
        $this->consultar($cookies, $r, ['page' => 1, 'per_page' => 1])->assertOk()->assertJsonPath('data.0.id_cita', $id);
        $this->consultar($cookies, $r, ['id_medico' => '9223372036854775807'])->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
    }

    public function test_historical_location_duration_and_inactive_links_do_not_use_reservation_rules(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, ['inicio_at' => '2020-01-01T12:00:00Z', 'fin_at' => '2020-01-01T12:23:00Z', 'duracion_minutos' => 23]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'NOMBRE_NUEVO', 'activo' => false]);
        $this->consultar($cookies, $r, ['fecha' => '2020-01-01'])->assertOk()->assertJsonPath('data.0.duracion_minutos', 23)
            ->assertJsonPath('data.0.consultorio_atencion', 'PRUEBA — Consultorio ficticio');
    }

    public function test_doctor_links_never_allow_selecting_even_their_own_id_and_links_remain_usable(): void
    {
        [$cookies, $r] = $this->contexto('Médico');
        $this->cita($r);
        $this->cita($r, ['inicio_at' => '2026-11-01T12:15:00Z', 'fin_at' => '2026-11-01T12:30:00Z']);
        $page = $this->consultar($cookies, $r, ['per_page' => 1], propia: true)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString('id_medico', $page->json('links.next'));
        $this->assertStringContainsString('fecha=2026-11-01', $page->json('links.next'));
        $this->navegador($cookies, 'GET', $page->json('links.next'))->assertOk()->assertJsonPath('meta.current_page', 2);
        foreach ($r['doctors'] as $id) {
            $this->consultar($cookies, $r, ['id_medico' => (string) $id], propia: true)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['id_medico']]]);
        }
        $this->expectException(ValidationException::class);
        $this->app->make(AgendaService::class)->consultar(['id_medico' => (string) $r['doctors'][1]]);
    }

    public static function invalidos(): array
    {
        return [[['fecha' => '2026-02-30']], [['fecha' => '0000-01-01']], [['fecha' => '2026-1-01']], [['fecha' => ['2026-11-01']]],
            [['estado' => 'Pendiente']], [['estado' => '']], [['per_page' => 101]], [['per_page' => 0]], [['page' => 0]], [['page' => 1000001]],
            [['id_medico' => '01']], [['id_medico' => '-1']], [['id_medico' => '9223372036854775808']], [['id_medico' => '1 OR 1=1']],
            [['id_paciente' => '1']], [['sort' => 'motivo_breve']], [['periodo' => 'proximas']]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_agenda_inputs_reject_invalid_or_unauthorized_fields(array $extra): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r, $extra)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
    }

    public function test_query_only_and_required_selection_and_selector_validation(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->navegador($cookies, 'GET', '/api/agenda')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/agenda?id_medico='.$r['doctors'][0], ['estado' => 'Cancelada'])->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/agenda/medicos', ['nombre' => 'Profesional'])->assertUnprocessable();
        foreach (['?activo=1', '?nombre=', '?nombre[]=x', '?nombre='.str_repeat('x', 121)] as $query) {
            $this->navegador($cookies, 'GET', '/api/agenda/medicos'.$query)->assertUnprocessable();
        }
    }

    public function test_denied_roles_guests_and_origin_do_not_receive_agenda_data(): void
    {
        $cookies = [];
        $this->navegador($cookies, 'GET', '/api/agenda')->assertUnauthorized();
        $this->navegador($cookies, 'GET', '/api/agenda/medicos')->assertUnauthorized();
        [$cookies, $r] = $this->contexto('Paciente');
        $this->consultar($cookies, $r)->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/agenda/medicos')->assertForbidden();
        [$cookies, $r] = $this->contexto('Médico');
        $this->navegador($cookies, 'GET', '/api/agenda/medicos')->assertForbidden();
        $this->consultar($cookies, $r, propia: true)->assertOk();
        $this->navegador($cookies, 'GET', '/api/agenda', [], ['Origin' => 'https://untrusted.example.test'])->assertForbidden();
        $this->navegador($cookies, 'HEAD', '/api/agenda')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['sesion']];
    }

    #[DataProvider('revocaciones')]
    public function test_service_revalidates_changes_after_middleware(string $cambio): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $this->cita($r);
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original, $cambio) {
            if ($cambio === 'estado') {
                DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada']);
            } elseif ($cambio === 'rol') {
                $rol = DB::table('roles')->where('nombre', 'Paciente')->value('id_rol');
                $rol ??= DB::table('roles')->insertGetId(['nombre' => 'Paciente'], 'id_rol');
                DB::table('usuarios')->where('id_usuario', $id)->update(['id_rol' => $rol]);
            } else {
                DB::table('sessions')->where('user_id', $id)->delete();
            }

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->consultar($cookies, $r)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO');
    }

    public function test_detail_and_future_write_actions_remain_unavailable(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->navegador($cookies, 'GET', '/api/mis-citas')->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['patient'].'/citas')->assertOk();
        $this->navegador($cookies, 'GET', '/api/citas/9223372036854775807')->assertNotFound();
        $this->navegador($cookies, 'POST', '/api/agenda')->assertStatus(405);
    }

    public function test_bigint_ids_remain_text_and_equal_times_use_id_as_tiebreaker(): void
    {
        [$cookies, $r] = $this->contexto();
        $large = '9223372036854775807';
        $this->cita($r, ['id_cita' => $large, 'estado' => 'Cancelada']);
        $small = $this->cita($r, ['estado' => 'Cancelada']);
        $first = $this->consultar($cookies, $r, ['per_page' => 1])->assertOk()->assertJsonPath('data.0.id_cita', $small);
        $this->navegador($cookies, 'GET', $first->json('links.next'))->assertOk()->assertJsonPath('data.0.id_cita', $large);
    }

    public function test_default_date_and_clock_are_captured_after_actor_validation(): void
    {
        [$cookies, $r] = $this->contexto();
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original) {
            CarbonImmutable::setTestNow('2026-11-02T04:00:00.000001Z');

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('meta.filtros.fecha', '2026-11-02')
            ->assertJsonPath('meta.consultado_en', '2026-11-02T04:00:00.000001Z');
    }

    public function test_doctor_losing_the_link_after_middleware_cannot_read_any_other_agenda(): void
    {
        [$cookies, $r] = $this->contexto('Médico');
        $this->cita($r);
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original) {
            DB::table('medicos')->where('id_usuario', $id)->update(['id_usuario' => null]);

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->consultar($cookies, $r, propia: true)->assertUnauthorized();
    }

    public function test_restricted_temporary_session_and_expired_session_cannot_read(): void
    {
        [$adminCookies] = $this->contexto('Administrador');
        $target = $this->usuarioAuth('Recepcionista');
        $receipt = $this->navegador($adminCookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])->assertCreated()->json('data');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $target, $receipt['credencial_temporal'])->assertOk()->assertJsonPath('data.acceso', 'cambio_clave');
        $this->navegador($cookies, 'GET', '/api/agenda')->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($cookies, 'GET', '/api/agenda/medicos')->assertForbidden();
        DB::table('sessions')->where('user_id', $target->getKey())->delete();
        $this->navegador($cookies, 'GET', '/api/agenda/medicos')->assertUnauthorized();
    }

    public static function volumenes(): array
    {
        return [[30], [300]];
    }

    #[DataProvider('volumenes')]
    public function test_page_and_count_share_one_projected_sql_statement_without_loading_all_rows(int $total): void
    {
        [$cookies, $r] = $this->contexto();
        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = $this->citaRow($r, ['estado' => 'Cancelada', 'motivo_breve' => 'PRIVADO']);
        }
        DB::table('citas')->insert($rows);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH filtradas')) {
                $queries[] = $query->sql;
            }
        });
        $this->consultar($cookies, $r, ['per_page' => 1])->assertOk()->assertJsonPath('meta.total', $total)->assertJsonCount(1, 'data');
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('COUNT(*)', $queries[0]);
        $this->assertStringNotContainsString('motivo_breve', $queries[0]);
        $this->assertStringNotContainsString('documento_identidad', $queries[0]);
        $this->assertStringNotContainsString('SELECT * FROM "citas"', $queries[0]);
    }
}
