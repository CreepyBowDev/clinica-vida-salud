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
use Tests\Fixtures\ReportesAisladosFixtures;
use Tests\TestCase;

class ReportePacientesRegistradosTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures, ReportesAisladosFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aislarReportes();
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

        return [$cookies, $actor];
    }

    private function paciente(array $extra = []): string
    {
        return (string) DB::table('pacientes')->insertGetId([
            'codigo_paciente' => 'PRUEBA-'.Str::random(16), 'nombres' => 'Paciente ficticio', 'apellidos' => 'De prueba',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => 'TELEFONO_PRIVADO', 'correo_contacto' => 'privado@example.test',
            'direccion' => 'DIRECCION_PRIVADA', 'sexo' => 'No especificado', 'creado_en' => '2026-11-01T12:00:00.123456Z', ...$extra,
        ], 'id_paciente');
    }

    private function consultar(array &$cookies, array $filtros = [])
    {
        return $this->navegador($cookies, 'GET', '/api/reportes/pacientes/registrados?'.http_build_query([
            'fecha' => '2026-11-01', ...$filtros]));
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['pacientes', 'usuarios', 'citas', 'registros_paciente_pendientes', 'preferencias_paciente', 'recordatorios', 'bitacora'] as $tabla) {
            $result[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $result;
    }

    public function test_contract_is_minimal_private_readonly_and_includes_fichas_without_ci_or_account(): void
    {
        [$cookies] = $this->contexto();
        $active = $this->paciente();
        $inactive = $this->paciente(['activo' => false, 'documento_identidad' => 'CI_PRIVADO']);
        $antes = $this->snapshot();
        $response = $this->consultar($cookies)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.reporte', 'pacientes/registrados')->assertJsonPath('meta.zona_horaria', 'America/La_Paz')
            ->assertJsonPath('meta.consultado_en', '2026-11-02T02:00:00.123456Z')->assertJsonPath('meta.filtros.activo', null)
            ->assertJsonPath('meta.filtros.orden', 'registro_asc')->assertJsonPath('meta.periodo.referencia', 'pacientes.creado_en')
            ->assertJsonPath('meta.periodo.criterio', 'registro_en_periodo')->assertJsonPath('data.0.id_paciente', $active)
            ->assertJsonPath('data.1.id_paciente', $inactive)->assertJsonPath('data.0.activo', true)->assertJsonPath('data.1.activo', false)
            ->assertJsonPath('data.0.creado_en', '2026-11-01T12:00:00.123456Z');
        $this->assertEqualsCanonicalizing(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos', 'activo', 'creado_en'], array_keys($response->json('data.0')));
        foreach (['documento_identidad', 'CI_PRIVADO', 'TELEFONO_PRIVADO', 'DIRECCION_PRIVADA', 'privado@example.test', 'fecha_nacimiento',
            'id_usuario', 'correo_contacto', 'preferencias', 'password', 'diagnostico', 'sexo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $response->getContent());
        }
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_period_is_ficha_creation_not_account_creation_link_ci_or_edit_date(): void
    {
        [$cookies] = $this->contexto();
        $new = $this->paciente();
        $old = $this->paciente(['creado_en' => '2020-01-01T12:00:00Z']);
        $oldAccount = $this->usuarioAuth('Paciente', ['creado_en' => '2020-01-01T12:00:00Z'], vincular: false);
        $newAccount = $this->usuarioAuth('Paciente', ['creado_en' => '2026-11-01T12:00:00Z'], vincular: false);
        DB::table('registros_paciente_pendientes')->insert(['tipo_registro' => 'Nuevo', 'correo_propuesto' => 'pendiente@example.test',
            'password_hash_temporal' => 'RESUMEN_FICTICIO_NO_UTILIZABLE', 'datos_registro' => '{}',
            'iniciado_en' => '2026-11-01T12:00:00Z', 'expira_en' => '2026-11-02T12:00:00Z']);
        $this->consultar($cookies)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_paciente', $new);
        DB::table('pacientes')->where('id_paciente', $new)->update(['id_usuario' => $oldAccount->getKey()]);
        DB::table('pacientes')->where('id_paciente', $old)->update(['id_usuario' => $newAccount->getKey(),
            'documento_identidad' => 'CI-FICTICIO-INCORPORADO', 'actualizado_en' => '2026-11-01T12:00:00Z']);
        $this->consultar($cookies)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_paciente', $new);
        $this->assertSame(2, DB::table('pacientes')->count());
    }

    public function test_activity_filter_is_optional_and_uses_current_ficha_status_not_web_account(): void
    {
        [$cookies] = $this->contexto();
        $active = $this->paciente();
        $inactive = $this->paciente(['activo' => false]);
        $this->consultar($cookies, ['activo' => '1'])->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_paciente', $active)->assertJsonPath('meta.filtros.activo', true);
        $first = $this->consultar($cookies, ['activo' => '0', 'per_page' => 1])->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_paciente', $inactive)->assertJsonPath('meta.filtros.activo', false);
        $this->navegador($cookies, 'GET', $first->json('links.first'))->assertOk()->assertJsonPath('meta.filtros.activo', false);
        DB::table('pacientes')->where('id_paciente', $active)->update(['activo' => false]);
        $this->consultar($cookies, ['activo' => '0'])->assertOk()->assertJsonPath('meta.total', 2);
        $this->consultar($cookies, ['activo' => '1'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_local_midnights_microseconds_inclusive_range_and_connection_timezone_are_correct(): void
    {
        [$cookies] = $this->contexto();
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Auckland'");
        $this->paciente(['creado_en' => '2026-11-01T03:59:59.999999Z']);
        $first = $this->paciente(['creado_en' => '2026-11-01T04:00:00Z']);
        $last = $this->paciente(['creado_en' => '2026-11-02T03:59:59.999999Z']);
        $next = $this->paciente(['creado_en' => '2026-11-02T04:00:00Z']);
        $this->consultar($cookies)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id_paciente', $first)
            ->assertJsonPath('data.1.id_paciente', $last)->assertJsonPath('meta.periodo.inicio_at', '2026-11-01T04:00:00.000000Z')
            ->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-02T04:00:00.000000Z');
        $this->consultar($cookies, ['fecha' => null, 'desde' => '2026-11-01', 'hasta' => '2026-11-02'])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.2.id_paciente', $next)
            ->assertJsonPath('meta.periodo.fin_exclusivo_at', '2026-11-03T04:00:00.000000Z');
    }

    public function test_orders_use_registration_or_names_with_stable_bigint_ties_and_pagination(): void
    {
        [$cookies] = $this->contexto();
        $large = '9223372036854775807';
        $this->paciente(['id_paciente' => $large, 'nombres' => 'Ana', 'apellidos' => 'A']);
        $small = $this->paciente(['nombres' => 'Ana', 'apellidos' => 'A']);
        $late = $this->paciente(['creado_en' => '2026-11-01T13:00:00Z', 'apellidos' => 'Z']);
        $first = $this->consultar($cookies, ['per_page' => 1])->assertOk()->assertJsonPath('data.0.id_paciente', $small)->assertJsonPath('meta.total', 3);
        $this->navegador($cookies, 'GET', $first->json('links.next'))->assertOk()->assertJsonPath('data.0.id_paciente', $large);
        $this->consultar($cookies, ['orden' => 'registro_desc'])->assertOk()->assertJsonPath('data.0.id_paciente', $late)
            ->assertJsonPath('data.1.id_paciente', $large)->assertJsonPath('data.2.id_paciente', $small);
        $this->consultar($cookies, ['orden' => 'nombre_asc'])->assertOk()->assertJsonPath('data.0.id_paciente', $small)
            ->assertJsonPath('data.1.id_paciente', $large)->assertJsonPath('data.2.id_paciente', $late);
        $this->consultar($cookies, ['page' => 5, 'per_page' => 1])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 3);
        $this->consultar($cookies, ['fecha' => '2030-01-01'])->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.filtros.fecha', '2030-01-01');
    }

    public function test_related_appointments_do_not_duplicate_fichas_and_page_count_share_one_minimal_query(): void
    {
        [$cookies] = $this->contexto();
        $r = $this->citaResources();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['creado_en' => '2026-11-01T12:00:00Z']);
        for ($i = 0; $i < 3; $i++) {
            DB::table('citas')->insert($this->citaRow($r, ['estado' => 'Cancelada']));
        }
        for ($i = 0; $i < 30; $i++) {
            $this->paciente();
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH filtradas')) {
                $queries[] = $query->sql;
            }
        });
        $this->consultar($cookies, ['per_page' => 1])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 31);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('COUNT(*)', $queries[0]);
        $this->assertStringNotContainsString('join', strtolower(strstr($queries[0], 'pagina AS', true)));
        foreach (['documento_identidad', 'telefono', 'correo_contacto', 'fecha_nacimiento', 'usuarios', 'citas', 'registros_paciente_pendientes', 'SELECT * FROM "pacientes"'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $queries[0]);
        }
    }

    public static function entradasInvalidas(): array
    {
        return [
            'sin periodo' => [['fecha' => null]],
            'rango incompleto' => [['fecha' => null, 'desde' => '2026-11-01']],
            'mezcla' => [['desde' => '2026-11-01', 'hasta' => '2026-11-02']],
            'invertido' => [['fecha' => null, 'desde' => '2026-11-02', 'hasta' => '2026-11-01']],
            '367 dias' => [['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-02']],
            'fecha imposible' => [['fecha' => '2026-02-29']],
            'ano cero' => [['fecha' => '0000-01-01']],
            'extremo no representable' => [['fecha' => '9999-12-31']],
            'array fecha' => [['fecha' => ['2026-11-01']]],
            'activo texto' => [['activo' => 'false']],
            'activo fuera' => [['activo' => '2']],
            'activo vacio' => [['activo' => '']],
            'activo array' => [['activo' => ['0']]],
            'orden de citas' => [['orden' => 'fecha_asc']],
            'orden sql' => [['orden' => 'creado_en DESC; DROP TABLE pacientes']],
            'pagina cero' => [['page' => 0]],
            'pagina enorme' => [['page' => 1000001]],
            'pagina array' => [['page' => ['1']]],
            'tamano enorme' => [['per_page' => 101]],
            'estado ajeno' => [['estado' => 'Activa']],
            'medico ajeno' => [['id_medico' => 1]],
            'paciente ajeno' => [['id_paciente' => 1]],
            'datos personales' => [['documento_identidad' => 'CI-FICTICIO']],
            'zona ajena' => [['zona_horaria' => 'UTC']],
            'exportacion' => [['formato' => 'pdf']],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_invalid_or_extra_filters_are_rejected(array $extra): void
    {
        [$cookies] = $this->contexto();
        $this->consultar($cookies, $extra)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR')
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data');
    }

    public function test_valid_leap_day_366_day_period_and_representable_extremes_remain_supported(): void
    {
        [$cookies] = $this->contexto();
        foreach (['2028-02-29', '0001-01-01', '9999-12-30'] as $fecha) {
            $this->consultar($cookies, ['fecha' => $fecha])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->consultar($cookies, ['fecha' => null, 'desde' => '2026-01-01', 'hasta' => '2027-01-01'])->assertOk()
            ->assertJsonPath('meta.periodo.desde', '2026-01-01')->assertJsonPath('meta.periodo.hasta', '2027-01-01');
    }

    public static function rolesDenegados(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('rolesDenegados')]
    public function test_non_admin_roles_are_denied_by_route_and_service(string $rol): void
    {
        [$cookies, $actor] = $this->contexto($rol);
        $this->consultar($cookies)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO')->assertJsonMissingPath('data');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('exigirSesionNormal')->with(true)->once()->andReturn($actor);
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldNotReceive('pacientesRegistrados');
        $this->expectExceptionObject(new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.'));
        (new ReporteService($auth, $repository, Mockery::mock(AgendaService::class)))->pacientesRegistrados(['fecha' => '2026-11-01']);
    }

    public function test_authentication_origin_body_methods_restricted_session_and_expiration_are_enforced(): void
    {
        $cookies = $this->csrfAuth();
        $this->consultar($cookies)->assertUnauthorized();
        [$cookies] = $this->contexto();
        $uri = '/api/reportes/pacientes/registrados?fecha=2026-11-01';
        $this->navegador($cookies, 'GET', $uri, ['activo' => '0'])->assertUnprocessable()->assertJsonValidationErrors('body', 'error.details');
        $this->navegador($cookies, 'GET', $uri, headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/reportes/pacientes/registrados')->assertStatus(405);
        $target = $this->usuarioAuth();
        $receipt = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $receipt['credencial_temporal'])->assertOk();
        $this->consultar($restricted)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        DB::table('sessions')->where('last_activity', '>', 0)->update(['last_activity' => 1]);
        $this->consultar($cookies)->assertUnauthorized();
        Mail::assertNothingSent();
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['sesion']];
    }

    #[DataProvider('revocaciones')]
    public function test_actor_revocation_after_middleware_prevents_report_query(string $cambio): void
    {
        [$cookies] = $this->contexto();
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($id, $lock) use ($original, $cambio) {
            if ($cambio === 'estado') {
                DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada']);
            } elseif ($cambio === 'rol') {
                $rol = DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol');
                DB::table('usuarios')->where('id_usuario', $id)->update(['id_rol' => $rol]);
            } else {
                DB::table('sessions')->where('user_id', $id)->delete();
            }

            return $original->porId($id, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldNotReceive('pacientesRegistrados');
        $this->app->instance(ReporteRepository::class, $repository);
        $this->consultar($cookies)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_NO_AUTENTICADO');
    }

    public function test_unexpected_failure_has_a_safe_error_without_internal_or_personal_data(): void
    {
        [$cookies] = $this->contexto();
        $repository = Mockery::mock(ReporteRepository::class);
        $repository->shouldReceive('pacientesRegistrados')->once()->andThrow(new RuntimeException('SQL SELECT documento_identidad PRIVADO'));
        $this->app->instance(ReporteRepository::class, $repository);
        $response = $this->consultar($cookies)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertJsonMissingPath('data');
        foreach (['SELECT', 'PRIVADO', 'trace', 'documento_identidad'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $response->getContent());
        }
    }
}
