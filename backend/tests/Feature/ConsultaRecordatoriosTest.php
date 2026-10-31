<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ConsultaRecordatoriosTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        $schema = 'prueba_avisos_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
        Carbon::setTestNow('2026-10-31T12:00:00Z');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        Mail::fake();
    }

    private function paciente(): array
    {
        $r = $this->citaResources();
        $u = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $u->getKey(), 'activo' => false]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $u)->assertOk();

        return [$r, $u, $cookies];
    }

    private function aviso(array $r, int $minutos = 60, string $estado = 'Programada', bool $persistir = true): array
    {
        $inicio = CarbonImmutable::now('UTC')->addMinutes($minutos);
        $cita = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => $inicio,
            'fin_at' => $inicio->addMinutes(15), 'estado' => $estado, 'motivo_breve' => 'CONTENIDO-PRIVADO-FICTICIO']), 'id_cita');
        $id = $persistir ? (string) DB::table('recordatorios')->insertGetId(['id_cita' => $cita,
            'version_programacion' => 1, 'generado_en' => CarbonImmutable::now('UTC')], 'id_recordatorio') : null;

        return [$id, $cita];
    }

    private function snapshot(): array
    {
        $s = [];
        foreach (['citas', 'recordatorios', 'envios_recordatorio_push', 'suscripciones_push', 'preferencias_paciente', 'bitacora',
            'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita'] as $table) {
            $s[$table] = DB::table($table)->orderByRaw('1')->get()->toJson();
        }

        return $s;
    }

    public function test_patient_reads_only_own_current_administrative_data_with_inactive_chart_and_no_effects(): void
    {
        [$r, , $c] = $this->paciente();
        [$id, $cita] = $this->aviso($r);
        $this->aviso($this->citaResources());
        $this->aviso($r, 90, persistir: false);
        $before = $this->snapshot();
        $res = $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.zona_horaria', 'America/La_Paz')->assertJsonPath('data.0.id_recordatorio', $id)
            ->assertJsonPath('data.0.id_cita', $cita)->assertJsonPath('data.0.cita.inicio_at', '2026-10-31T13:00:00.000000Z');
        $this->assertSame(['id_recordatorio', 'id_cita', 'version_programacion', 'generado_en', 'cita'], array_keys($res->json('data.0')));
        $this->assertSame(['codigo_cita', 'inicio_at', 'fin_at', 'estado', 'modalidad', 'sede_atencion', 'consultorio', 'medico', 'especialidad'], array_keys($res->json('data.0.cita')));
        foreach (['CONTENIDO-PRIVADO', 'documento_identidad', 'telefono', 'id_usuario', 'endpoint', 'huella', 'leido_en', 'invalidado_en'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $res->getContent());
        }
        $this->navegador($c, 'HEAD', '/api/recordatorios')->assertOk()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEquals($before, $this->snapshot());
        $this->assertNull(DB::table('recordatorios')->where('id_recordatorio', $id)->value('leido_en'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_admin_must_select_patient_and_can_read_inactive_or_presential_without_web_account(): void
    {
        $r = $this->citaResources();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        [$id] = $this->aviso($r);
        $admin = $this->usuarioAuth('Administrador');
        $c = $this->csrfAuth();
        $this->loginAuth($c, $admin)->assertOk();
        $before = $this->snapshot();
        $this->navegador($c, 'GET', '/api/recordatorios')->assertUnprocessable();
        $this->navegador($c, 'GET', '/api/recordatorios?id_paciente='.$r['patient'])->assertOk()
            ->assertJsonPath('data.0.id_recordatorio', $id)->assertJsonPath('meta.paciente.id_paciente', (string) $r['patient']);
        $this->navegador($c, 'GET', '/api/recordatorios?id_paciente=999999')->assertNotFound();
        $this->assertEquals($before, $this->snapshot());
    }

    #[DataProvider('rolesAjenos')]
    public function test_medical_and_reception_roles_are_denied_without_losing_session(string $rol): void
    {
        $u = $this->usuarioAuth($rol);
        $c = $this->csrfAuth();
        $this->loginAuth($c, $u)->assertOk();
        $this->navegador($c, 'GET', '/api/recordatorios')->assertForbidden();
        $this->navegador($c, 'GET', '/api/auth/me')->assertOk();
    }

    public static function rolesAjenos(): array
    {
        return [['Médico'], ['Recepcionista']];
    }

    #[DataProvider('queryInvalida')]
    public function test_invalid_or_unauthorized_filters_never_query_other_patients(string $query): void
    {
        [, , $c] = $this->paciente();
        $this->navegador($c, 'GET', '/api/recordatorios?'.$query)->assertUnprocessable();
    }

    public static function queryInvalida(): array
    {
        return array_map(fn ($s) => [$s], ['id_paciente=1', 'id_paciente=', 'id_usuario=1', 'estado=Cancelada', 'sort=motivo_breve',
            'page=0', 'page=-1', 'page=2147483648', 'per_page=0', 'per_page=101', 'per_page[]=2',
            'fecha_desde=2026-02-30', 'fecha_desde=31-10-2026', 'fecha_desde=0000-01-01',
            'fecha_desde=2026-11-01&fecha_hasta=2026-10-31', 'fecha_hasta=', 'leido_en=2026-10-31']);
    }

    public function test_strict_admin_patient_ids_and_query_only_inputs(): void
    {
        $c = $this->csrfAuth();
        $this->loginAuth($c, $this->usuarioAuth('Administrador'))->assertOk();
        foreach (['0', '-1', '01', '1e1', '9223372036854775808', 'x'] as $id) {
            $this->navegador($c, 'GET', '/api/recordatorios?id_paciente='.$id)->assertUnprocessable();
        }
        $this->navegador($c, 'GET', '/api/recordatorios', ['id_paciente' => 1])->assertUnprocessable();
        $this->navegador($c, 'POST', '/api/recordatorios', ['id_paciente' => 1])->assertStatus(405);
    }

    public function test_anonymous_and_revoked_sessions_do_not_expose_data(): void
    {
        $anon = $this->csrfAuth();
        $this->navegador($anon, 'GET', '/api/recordatorios')->assertUnauthorized();
        [$r, $u, $c] = $this->paciente();
        $this->aviso($r);
        $before = $this->snapshot();
        DB::table('sessions')->where('user_id', $u->getKey())->delete();
        $this->navegador($c, 'GET', '/api/recordatorios')->assertUnauthorized();
        $this->assertEquals($before, $this->snapshot());
    }

    #[DataProvider('avisosNoVigentes')]
    public function test_stale_invalidated_final_or_finished_notice_is_hidden_without_cleanup_write(string $caso): void
    {
        [$r, , $c] = $this->paciente();
        [$id, $cita] = $this->aviso($r, $caso === 'finalizado' ? -15 : 60, in_array($caso, ['Cancelada', 'Atendida', 'Inasistencia']) ? $caso : 'Programada');
        match ($caso) {
            'version' => DB::table('citas')->where('id_cita', $cita)->update(['version_programacion' => 2]),
            'invalidado' => DB::table('recordatorios')->where('id_recordatorio', $id)->update(['invalidado_en' => CarbonImmutable::now('UTC')]),
            'generado_futuro' => DB::table('recordatorios')->where('id_recordatorio', $id)->update(['generado_en' => CarbonImmutable::now('UTC')->addMinute()]),
            'fuera_24h' => DB::table('citas')->where('id_cita', $cita)->update(['inicio_at' => CarbonImmutable::now('UTC')->addHours(25), 'fin_at' => CarbonImmutable::now('UTC')->addHours(25)->addMinutes(15)]),
            default => null,
        };
        $before = $this->snapshot();
        $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 1);
        $this->assertEquals($before, $this->snapshot());
    }

    public static function avisosNoVigentes(): array
    {
        return array_map(fn ($s) => [$s], ['version', 'invalidado', 'generado_futuro', 'fuera_24h', 'finalizado', 'Cancelada', 'Atendida', 'Inasistencia']);
    }

    public function test_preference_is_evaluated_now_and_read_status_or_push_setting_does_not_grant_visibility(): void
    {
        [$r, , $c] = $this->paciente();
        [$id] = $this->aviso($r, 0, 'Confirmada');
        config(['push.habilitado' => false]);
        DB::table('recordatorios')->where('id_recordatorio', $id)->update(['leido_en' => CarbonImmutable::now('UTC')]);
        $this->navegador($c, 'GET', '/api/recordatorios')->assertJsonCount(1, 'data');
        DB::table('preferencias_paciente')->insert(['id_paciente' => $r['patient'], 'recordatorios_habilitados' => false, 'preferencias_privacidad' => '{}']);
        $before = $this->snapshot();
        $this->navegador($c, 'GET', '/api/recordatorios')->assertJsonCount(0, 'data');
        $this->assertEquals($before, $this->snapshot());
        DB::table('preferencias_paciente')->update(['recordatorios_habilitados' => true]);
        $this->navegador($c, 'GET', '/api/recordatorios')->assertJsonCount(1, 'data');
    }

    public function test_date_filters_are_inclusive_civil_days_in_la_paz_not_utc_days(): void
    {
        [$r, , $c] = $this->paciente();
        [$ultimoDia] = $this->aviso($r, 945); // 2026-11-01 03:45 UTC = 31/10 23:45 sede.
        [$siguienteDia] = $this->aviso($r, 960); // 2026-11-01 04:00 UTC = 01/11 00:00 sede.
        $this->navegador($c, 'GET', '/api/recordatorios?fecha_desde=2026-10-31&fecha_hasta=2026-10-31')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_recordatorio', $ultimoDia);
        $this->navegador($c, 'GET', '/api/recordatorios?fecha_desde=2026-11-01&fecha_hasta=2026-11-01')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_recordatorio', $siguienteDia);
    }

    public function test_order_pagination_empty_page_and_current_location_are_authoritative(): void
    {
        [$r, , $c] = $this->paciente();
        [$ultimo] = $this->aviso($r, 90);
        [$primero, $cita] = $this->aviso($r, 30);
        [$segundo] = $this->aviso($r, 60);
        DB::table('citas')->where('id_cita', $cita)->update(['consultorio_atencion' => 'Ubicación vigente ficticia']);
        $this->navegador($c, 'GET', '/api/recordatorios?per_page=2')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id_recordatorio', $primero)
            ->assertJsonPath('data.1.id_recordatorio', $segundo)->assertJsonPath('data.0.cita.consultorio.ubicacion', 'Ubicación vigente ficticia');
        $this->navegador($c, 'GET', '/api/recordatorios?per_page=2&page=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_recordatorio', $ultimo);
        $this->navegador($c, 'GET', '/api/recordatorios?per_page=2&page=3')->assertJsonCount(0, 'data')->assertJsonPath('meta.current_page', 3)->assertJsonPath('meta.total', 3);
    }

    public function test_clock_is_refreshed_after_waiting_for_patient_context(): void
    {
        [$r, , $c] = $this->paciente();
        $this->aviso($r, 1);
        $real = app(RecordatorioRepository::class);
        $mock = Mockery::mock(RecordatorioRepository::class)->makePartial();
        $mock->shouldReceive('pacienteParaConsulta')->andReturnUsing(function ($id) use ($real) {
            $p = $real->pacienteParaConsulta($id);
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(16));

            return $p;
        });
        $this->app->instance(RecordatorioRepository::class, $mock);
        $this->navegador($c, 'GET', '/api/recordatorios')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revocation_after_query_is_rechecked_before_returning_private_rows(): void
    {
        [$r, $u, $c] = $this->paciente();
        $this->aviso($r);
        $real = app(RecordatorioRepository::class);
        $mock = Mockery::mock(RecordatorioRepository::class)->makePartial();
        $mock->shouldReceive('listarVigentes')->andReturnUsing(function (...$args) use ($real, $u) {
            $pagina = $real->listarVigentes(...$args);
            DB::table('usuarios')->where('id_usuario', $u->getKey())->update(['estado_cuenta' => 'Desactivada']);

            return $pagina;
        });
        $this->app->instance(RecordatorioRepository::class, $mock);
        $this->navegador($c, 'GET', '/api/recordatorios')->assertUnauthorized()->assertJsonMissingPath('data');
    }

    public function test_unexpected_repository_failure_is_safe_and_read_does_not_generate_missing_notices(): void
    {
        [$r, , $c] = $this->paciente();
        $this->aviso($r, persistir: false);
        $this->navegador($c, 'GET', '/api/recordatorios')->assertJsonCount(0, 'data');
        $mock = Mockery::mock(RecordatorioRepository::class)->makePartial();
        $mock->shouldReceive('listarVigentes')->andThrow(new RuntimeException('SQL-PRIVADO-FICTICIO'));
        $this->app->instance(RecordatorioRepository::class, $mock);
        $r = $this->navegador($c, 'GET', '/api/recordatorios')->assertStatus(500);
        $this->assertStringNotContainsString('SQL-PRIVADO', $r->getContent());
        $this->assertSame(0, DB::table('recordatorios')->count());
    }
}
