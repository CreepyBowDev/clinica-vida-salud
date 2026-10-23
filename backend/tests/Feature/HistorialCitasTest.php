<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\HistorialCitasService;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class HistorialCitasTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-01T12:20:00.000001Z');
    }

    private function contexto(string $rol = 'Paciente'): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }

        return [$cookies, $r, $actor];
    }

    private function consultar(array &$cookies, array $r, array $filters = [], bool $recepcion = false): TestResponse
    {
        $path = $recepcion ? '/api/pacientes/'.$r['patient'].'/citas' : '/api/mis-citas';

        return $this->navegador($cookies, 'GET', $path.'?'.http_build_query($filters));
    }

    private function cita(array $r, string $inicio = '2026-11-01T12:15:00Z', array $extra = []): string
    {
        return (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'inicio_at' => $inicio, 'fin_at' => CarbonImmutable::parse($inicio)->addMinutes(15)->format('Y-m-d H:i:s.uP'),
            'motivo_breve' => 'MOTIVO_NO_LISTAR', ...$extra]), 'id_cita');
    }

    private function citasEstados(array $r): array
    {
        return [
            'Inasistencia' => $this->cita($r, '2026-11-01T09:00:00Z', ['estado' => 'Inasistencia']),
            'Atendida' => $this->cita($r, '2026-11-01T10:00:00Z', ['estado' => 'Atendida']),
            'Programada pasada' => $this->cita($r, '2026-11-01T11:00:00Z'),
            'Confirmada pasada' => $this->cita($r, '2026-11-01T12:00:00Z', ['estado' => 'Confirmada']),
            'En curso' => $this->cita($r),
            'Confirmada futura' => $this->cita($r, '2026-11-01T12:30:00Z', ['estado' => 'Confirmada']),
            'Cancelada futura' => $this->cita($r, '2026-11-01T12:45:00Z', ['estado' => 'Cancelada']),
        ];
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['citas', 'pacientes', 'medicos', 'cambios_cita', 'recordatorios', 'bitacora'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }

    public static function roles(): array
    {
        return [['Paciente', 'proximas'], ['Recepcionista', 'todas']];
    }

    #[DataProvider('roles')]
    public function test_defaults_privacy_and_readonly_history_are_authorized_per_role(string $rol, string $periodo): void
    {
        [$cookies, $r] = $this->contexto($rol);
        $ids = $this->citasEstados($r);
        $otra = $this->usuarioAuth('Paciente');
        $this->cita($r, '2026-11-02T12:00:00Z', ['id_paciente' => $otra->paciente->getKey(), 'motivo_breve' => 'AJENO_NO_REVELAR']);
        $before = $this->snapshot();
        $response = $this->consultar($cookies, $r, recepcion: $rol === 'Recepcionista')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('meta.filtros.periodo', $periodo)
            ->assertJsonPath('meta.filtros.estado', null)->assertJsonPath('meta.paciente.id_paciente', (string) $r['patient'])
            ->assertJsonPath('meta.consultado_en', '2026-11-01T12:20:00.000001Z')->assertJsonPath('meta.zona_horaria', 'America/La_Paz');
        $expected = $rol === 'Paciente' ? [$ids['En curso'], $ids['Confirmada futura']] : array_reverse(array_values($ids));
        $this->assertSame($expected, array_column($response->json('data'), 'id_cita'));
        $this->assertEqualsCanonicalizing(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'], array_keys($response->json('meta.paciente')));
        foreach (['motivo_breve', 'MOTIVO_NO_LISTAR', 'AJENO_NO_REVELAR', 'documento_identidad', 'telefono', 'fecha_nacimiento',
            'correo_acceso', 'correo_contacto', 'registro_profesional', 'id_propuesta', 'creada_por', 'diagnostico'] as $field) {
            $this->assertStringNotContainsString($field, $response->getContent());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function filtros(): array
    {
        return [['proximas', null, ['En curso', 'Confirmada futura']],
            ['anteriores', null, ['Confirmada pasada', 'Programada pasada', 'Atendida', 'Inasistencia']],
            ['todas', 'Cancelada', ['Cancelada futura']], ['proximas', 'Cancelada', []], ['anteriores', 'Cancelada', []],
            ['anteriores', 'Programada', ['Programada pasada']], ['proximas', 'Programada', ['En curso']],
            ['proximas', 'Confirmada', ['Confirmada futura']], ['todas', 'Atendida', ['Atendida']], ['todas', 'Inasistencia', ['Inasistencia']]];
    }

    #[DataProvider('filtros')]
    public function test_time_and_real_state_are_independent_filters(string $periodo, ?string $estado, array $keys): void
    {
        [$cookies, $r] = $this->contexto();
        $ids = $this->citasEstados($r);
        $response = $this->consultar($cookies, $r, ['periodo' => $periodo, ...($estado ? ['estado' => $estado] : [])])
            ->assertOk()->assertJsonPath('meta.total', count($keys));
        $this->assertSame(array_map(fn ($key) => $ids[$key], $keys), array_column($response->json('data'), 'id_cita'));
        $this->assertSame('Programada', DB::table('citas')->where('id_cita', $ids['Programada pasada'])->value('estado'));
    }

    public static function instantes(): array
    {
        return [['2026-11-01T12:14:59.999999Z', 'proximas', 1], ['2026-11-01T12:15:00.000000Z', 'proximas', 0],
            ['2026-11-01T12:15:00.000000Z', 'anteriores', 1], ['2026-11-01T12:14:59.999999Z', 'anteriores', 0]];
    }

    #[DataProvider('instantes')]
    public function test_exact_finish_instant_and_microseconds(string $now, string $periodo, int $count): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, '2026-11-01T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $this->consultar($cookies, $r, ['periodo' => $periodo])->assertOk()->assertJsonPath('meta.total', $count)
            ->assertJsonPath('meta.consultado_en', $now);
    }

    public function test_all_history_keeps_old_citations_and_historical_room_with_inactive_resources(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, '2000-01-01T12:00:00Z');
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'NUEVO_NOMBRE', 'activo' => false]);
        $this->consultar($cookies, $r, ['periodo' => 'todas'])->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.consultorio_atencion', 'PRUEBA — Consultorio ficticio');
    }

    public function test_empty_patient_without_ci_or_account_has_context_but_unknown_patient_is_404(): void
    {
        [$cookies, $r] = $this->contexto('Recepcionista');
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        $this->consultar($cookies, $r, recepcion: true)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.paciente.id_paciente', (string) $r['patient']);
        $this->navegador($cookies, 'GET', '/api/pacientes/9223372036854775807/citas')->assertNotFound();
    }

    public function test_pagination_uses_effective_filters_bigint_ties_and_empty_page_count(): void
    {
        [$cookies, $r] = $this->contexto();
        $big = '9223372036854775807';
        $this->cita($r, extra: ['id_cita' => $big, 'estado' => 'Cancelada']);
        $small = $this->cita($r, extra: ['estado' => 'Cancelada']);
        $page = $this->consultar($cookies, $r, ['periodo' => 'todas', 'estado' => 'Cancelada', 'per_page' => 1])->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id_cita', $big);
        $this->assertStringContainsString('periodo=todas', $page->json('links.next'));
        $this->assertStringNotContainsString('id_paciente', $page->json('links.next'));
        $this->navegador($cookies, 'GET', $page->json('links.next'))->assertOk()->assertJsonPath('data.0.id_cita', $small);
        $this->consultar($cookies, $r, ['periodo' => 'todas', 'page' => 3, 'per_page' => 1])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
    }

    public static function invalidos(): array
    {
        return [[['periodo' => 'pendientes']], [['periodo' => '']], [['periodo' => ['todas']]], [['estado' => 'Pendiente']],
            [['page' => 0]], [['per_page' => 101]], [['id_paciente' => '1']], [['id_objetivo' => '1']],
            [['id_medico' => '1']], [['id_especialidad' => '1']], [['fecha' => '2026-11-01']], [['sort' => 'inicio_at']]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_inputs_do_not_allow_patient_selection_or_unapproved_filters(array $filters): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r, $filters)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
    }

    public function test_reception_route_ids_are_canonical_and_cannot_be_overridden_or_sent_in_body(): void
    {
        [$cookies, $r] = $this->contexto('Recepcionista');
        foreach (['0', '01', '-1', '9223372036854775808', 'texto'] as $id) {
            $this->navegador($cookies, 'GET', '/api/pacientes/'.$id.'/citas')->assertUnprocessable();
        }
        $this->consultar($cookies, $r, ['id_objetivo' => '17'], true)->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['patient'].'/citas', ['periodo' => 'todas'])->assertUnprocessable();
    }

    public static function denegados(): array
    {
        return [['Administrador', '/api/mis-citas'], ['Administrador', '/api/pacientes/1/citas'],
            ['Médico', '/api/mis-citas'], ['Médico', '/api/pacientes/1/citas'],
            ['Recepcionista', '/api/mis-citas'], ['Paciente', '/api/pacientes/1/citas']];
    }

    #[DataProvider('denegados')]
    public function test_roles_cannot_use_other_history_operations(string $role, string $path): void
    {
        [$cookies] = $this->contexto($role);
        $this->navegador($cookies, 'GET', $path)->assertForbidden();
    }

    public function test_guest_expired_session_origin_and_head(): void
    {
        $cookies = [];
        $this->navegador($cookies, 'GET', '/api/mis-citas')->assertUnauthorized();
        $this->navegador($cookies, 'GET', '/api/pacientes/1/citas')->assertUnauthorized();
        [$cookies, $r, $actor] = $this->contexto();
        $this->navegador($cookies, 'HEAD', '/api/mis-citas')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->navegador($cookies, 'GET', '/api/mis-citas', [], ['Origin' => 'https://untrusted.example.test'])->assertForbidden();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->consultar($cookies, $r)->assertUnauthorized();
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['vinculo'], ['sesion']];
    }

    #[DataProvider('revocaciones')]
    public function test_service_revalidates_actor_after_middleware(string $change): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r);
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original, $change) {
            if ($change === 'estado') {
                DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada']);
            } elseif ($change === 'rol') {
                $role = DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol');
                DB::table('usuarios')->where('id_usuario', $id)->update(['id_rol' => $role]);
            } elseif ($change === 'vinculo') {
                DB::table('pacientes')->where('id_usuario', $id)->update(['id_usuario' => null]);
            } else {
                DB::table('sessions')->where('user_id', $id)->delete();
            }

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->consultar($cookies, $r)->assertUnauthorized();
    }

    public function test_patient_service_rejects_explicit_other_patient_even_without_http(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r)->assertOk();
        $this->expectException(AppError::class);
        $this->app->make(HistorialCitasService::class)->consultar([], '9223372036854775807');
    }

    public function test_service_rejects_filter_based_patient_id_without_http(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->consultar($cookies, $r)->assertOk();
        $this->expectException(ValidationException::class);
        $this->app->make(HistorialCitasService::class)->consultar(['id_paciente' => '1']);
    }

    public function test_history_count_and_page_share_one_query_for_large_list(): void
    {
        [$cookies, $r] = $this->contexto();
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = $this->citaRow($r, ['estado' => 'Cancelada', 'motivo_breve' => 'SECRETO']);
        }
        DB::table('citas')->insert($rows);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH filtradas')) {
                $queries[] = $query->sql;
            }
        });
        $this->consultar($cookies, $r, ['periodo' => 'todas', 'per_page' => 1])->assertOk()->assertJsonPath('meta.total', 300)->assertJsonCount(1, 'data');
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('motivo_breve', $queries[0]);
        $this->assertStringNotContainsString('documento_identidad', $queries[0]);
    }

    public function test_past_cancellation_is_preserved_and_classified_without_changing_state(): void
    {
        [$cookies, $r] = $this->contexto();
        $id = $this->cita($r, '1999-01-01T12:00:00Z', ['estado' => 'Cancelada']);
        $before = $this->snapshot();
        $this->consultar($cookies, $r, ['periodo' => 'anteriores', 'estado' => 'Cancelada'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('data.0.estado', 'Cancelada');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clock_for_temporal_filter_is_captured_after_session_revalidation(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->cita($r, '2026-11-01T12:00:00Z');
        CarbonImmutable::setTestNow('2026-11-01T12:14:59.999999Z');
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original) {
            CarbonImmutable::setTestNow('2026-11-01T12:15:00.000000Z');

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $this->consultar($cookies, $r)->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.consultado_en', '2026-11-01T12:15:00.000000Z');
    }
}
