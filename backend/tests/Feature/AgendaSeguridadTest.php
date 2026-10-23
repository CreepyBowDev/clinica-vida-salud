<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\agenda\AgendaRepository;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AgendaSeguridadTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-01T12:15:00Z');
    }

    private function preparar(string $endpoint): array
    {
        $actor = $this->usuarioAuth($endpoint === 'mis' ? 'Paciente' : 'Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($endpoint === 'mis') {
            $r['patient'] = $actor->paciente->getKey();
        }
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => 'Cancelada', 'motivo_breve' => 'MOTIVO_PRIVADO']), 'id_cita');
        $path = match ($endpoint) {
            'selector' => '/api/agenda/medicos', 'agenda' => '/api/agenda?id_medico='.$r['doctors'][0],
            'mis' => '/api/mis-citas?periodo=todas', 'historial' => '/api/pacientes/'.$r['patient'].'/citas',
            default => '/api/citas/'.$id,
        };

        return [$cookies, $r, $path];
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['citas', 'pacientes', 'medicos', 'cambios_cita', 'recordatorios', 'bitacora'] as $table) {
            $rows[$table] = DB::table($table)->get()->toJson();
        }

        return $rows;
    }

    public static function escrituras(): array
    {
        $cases = [];
        foreach (['selector', 'agenda', 'mis', 'historial', 'detalle'] as $endpoint) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $cases[] = [$endpoint, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('escrituras')]
    public function test_consultation_routes_do_not_add_writes_or_mutate_domain(string $endpoint, string $method): void
    {
        [$cookies, $r, $path] = $this->preparar($endpoint);
        $before = $this->snapshot();
        $this->navegador($cookies, $method, $path, ['estado' => 'Atendida', 'id_paciente' => '17'])
            ->assertStatus(405)->assertJsonPath('error.code', 'METODO_NO_PERMITIDO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function endpoints(): array
    {
        return [['selector', 'medicos'], ['agenda', 'citas'], ['mis', 'historial'], ['historial', 'historial'], ['detalle', 'detalle']];
    }

    #[DataProvider('endpoints')]
    public function test_safe_500_and_no_rollback_claim_or_sensitive_output(string $endpoint, string $method): void
    {
        [$cookies, $r, $path] = $this->preparar($endpoint);
        $before = $this->snapshot();
        $repository = Mockery::mock(AgendaRepository::class)->makePartial();
        $repository->shouldReceive($method)->once()->andThrow(new RuntimeException('SQL_INTERNO /ruta/privada MOTIVO_PRIVADO password_hash'));
        $this->app->instance(AgendaRepository::class, $repository);
        $response = $this->navegador($cookies, 'GET', $path)->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'ERROR_INTERNO');
        foreach (['SQL_INTERNO', '/ruta/privada', 'MOTIVO_PRIVADO', 'password_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('endpoints')]
    public function test_get_and_head_only_require_session_not_csrf_header_and_head_is_empty(string $endpoint, string $method): void
    {
        [$cookies, $r, $path] = $this->preparar($endpoint);
        $before = $this->snapshot();
        $this->navegador($cookies, 'GET', $path, csrf: false)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->navegador($cookies, 'HEAD', $path, csrf: false)->assertOk()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->snapshot());
    }

    public static function fechas(): array
    {
        return [['0001-01-01'], ['2000-02-29'], ['9999-12-31']];
    }

    #[DataProvider('fechas')]
    public function test_civil_date_bounds_and_leap_year_are_not_a_reservation_horizon(string $date): void
    {
        [$cookies, $r, $path] = $this->preparar('agenda');
        $this->navegador($cookies, 'GET', $path.'&fecha='.$date)->assertOk()->assertJsonPath('meta.filtros.fecha', $date)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_database_timezone_does_not_change_day_intersection_or_utc_output(): void
    {
        [$cookies, $r, $path] = $this->preparar('agenda');
        DB::table('citas')->update(['inicio_at' => '2026-11-02T03:45:00Z', 'fin_at' => '2026-11-02T04:00:00Z']);
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Auckland'");
        $this->navegador($cookies, 'GET', $path.'&fecha=2026-11-01')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.inicio_at', '2026-11-02T03:45:00.000000Z');
        $this->navegador($cookies, 'GET', $path.'&fecha=2026-11-02')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public static function hostiles(): array
    {
        return [['selector', ['nombre' => "'; DELETE FROM citas; --"]], ['selector', ['nombre' => '%_']],
            ['agenda', ['page' => ['1']]], ['agenda', ['per_page' => '1e2']], ['agenda', ['estado' => ['Programada']]],
            ['mis', ['periodo' => ['todas']]], ['historial', ['estado' => ['Cancelada']]],
            ['detalle', ['rol' => ['Administrador']]], ['detalle', ['fields' => 'motivo_breve']],
            ['mis', ['orderBy' => 'inicio_at; DELETE FROM citas']]];
    }

    #[DataProvider('hostiles')]
    public function test_hostile_values_are_literal_or_rejected_without_domain_changes(string $endpoint, array $extra): void
    {
        [$cookies, $r, $path] = $this->preparar($endpoint);
        $before = $this->snapshot();
        $path .= (str_contains($path, '?') ? '&' : '?').http_build_query($extra);
        $response = $this->navegador($cookies, 'GET', $path);
        $endpoint === 'selector' ? $response->assertOk()->assertJsonCount(0, 'data') : $response->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_response_keeps_its_sql_snapshot_but_the_next_request_sees_new_rows(): void
    {
        [$cookies, $r, $path] = $this->preparar('historial');
        $inserted = false;
        DB::listen(function ($query) use (&$inserted, $r): void {
            if (! $inserted && str_contains($query->sql, 'WITH filtradas')) {
                $inserted = true;
                DB::table('citas')->insert($this->citaRow($r, ['estado' => 'Cancelada']));
            }
        });
        $this->navegador($cookies, 'GET', $path)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        $this->assertTrue($inserted);
        $this->navegador($cookies, 'GET', $path)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
    }
}
