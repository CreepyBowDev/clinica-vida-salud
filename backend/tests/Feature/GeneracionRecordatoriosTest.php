<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\data\models\SuscripcionPush;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\recordatorios\EnvioRecordatorioPushRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class GeneracionRecordatoriosTest extends TestCase
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
        $schema = 'prueba_recordatorio_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
        Carbon::setTestNow('2026-10-31T12:00:00Z');
        config(['push.habilitado' => true, 'push.vapid_publica' => $this->publica()]);
        Mail::fake();
    }

    private function publica(): string
    {
        return rtrim(strtr(base64_encode(hex2bin('046b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c2964fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5')), '+/', '-_'), '=');
    }

    private function cita(int $minutos = 60, string $estado = 'Programada'): array
    {
        $r = $this->citaResources();
        $inicio = CarbonImmutable::now('UTC')->addMinutes($minutos);
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => $inicio,
            'fin_at' => $inicio->addMinutes(15), 'estado' => $estado]), 'id_cita');

        return [$id, $r];
    }

    private function suscribir(array $r, array $cambios = []): SuscripcionPush
    {
        $usuario = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $usuario->getKey(), 'activo' => false]);
        $usuario = $usuario->fresh(['rol', 'paciente']);
        $s = new SuscripcionPush;
        $s->forceFill(array_replace(['instalacion_hash' => hash('sha256', Str::random(32)), 'endpoint_hash' => hash('sha256', Str::random(32)),
            'datos_cifrados' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ficticio', 'expirationTime' => null,
                'keys' => ['p256dh' => $this->publica(), 'auth' => 'AAAAAAAAAAAAAAAAAAAAAA']],
            'id_usuario' => $usuario->getKey(), 'id_paciente' => $r['patient'], 'version_autorizacion' => 1,
            'huella_cuenta' => app(AutorizacionPushValidator::class)->huella($usuario), 'autorizada_en' => now()->subDay(),
            'actualizada_en' => now()->subDay(), 'expira_en' => now()->addDays(30), 'revocada_en' => null], $cambios))->saveOrFail();

        return $s;
    }

    private function generar(int $lote = 100): array
    {
        return app(RecordatorioService::class)->generar($lote);
    }

    private function administradorManual(): array
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        $admin = $this->usuarioAuth('Administrador');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();

        return [$cookies, $admin];
    }

    private function revisionManual(array &$cookies, string $paciente): array
    {
        return $this->navegador($cookies, 'GET', '/api/recordatorios/proxima-cita?id_paciente='.$paciente)
            ->assertOk()->json('data.cita');
    }

    private function datosManual(array &$cookies, string $paciente): array
    {
        $cita = $this->revisionManual($cookies, $paciente);

        return ['id_paciente' => $paciente, 'id_cita' => $cita['id_cita'], 'revision' => $cita['revision'], 'operacion' => (string) Str::uuid()];
    }

    #[DataProvider('ordenManual')]
    public function test_manual_and_automatic_are_independent_idempotent_and_audited(bool $automaticoPrimero): void
    {
        [$id, $r] = $this->cita($automaticoPrimero ? 60 : 2880);
        $this->suscribir($r);
        [$cookies, $admin] = $this->administradorManual();
        if ($automaticoPrimero) {
            $this->generar();
        }
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $citasAntes = DB::table('citas')->get()->toJson();
        $primero = $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk()
            ->assertJsonPath('data.push', 'Pendiente')->json('data');
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk()->assertJsonPath('data', $primero);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', [...$datos, 'operacion' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'RECORDATORIO_MANUAL_EXISTENTE');
        $this->navegador($cookies, 'GET', '/api/recordatorios/proxima-cita?id_paciente='.$r['patient'].'&operacion='.$datos['operacion'])
            ->assertOk()->assertJsonPath('data.operacion', $primero)->assertJsonPath('data.cita.manual_generado', true);
        $this->assertSame($citasAntes, DB::table('citas')->get()->toJson());
        if (! $automaticoPrimero) {
            $this->assertSame(0, $this->generar()['avisos_creados']);
            $this->assertSame(1, DB::table('envios_recordatorio_push')->where('estado', 'Pendiente')->count());
            // El manual a >24 h ya aparece en la consulta del titular/administrador.
            $this->navegador($cookies, 'GET', '/api/recordatorios?id_paciente='.$r['patient'])->assertOk()->assertJsonPath('meta.total', 1);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
            Carbon::setTestNow(CarbonImmutable::now());
            $this->assertSame(1, $this->generar()['avisos_creados']);
        }
        $this->assertSame(2, DB::table('recordatorios')->where('id_cita', $id)->count());
        $this->assertSame(2, DB::table('envios_recordatorio_push')->where('estado', 'Pendiente')->count());
        $this->assertSame(0, $this->generar()['avisos_creados']);
        $evento = DB::table('bitacora')->where('tipo_evento', 'RECORDATORIO_GENERADO')->where('tipo_actor', 'USUARIO')->sole();
        $this->assertSame((string) $admin->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertStringContainsString('resultado_push', $evento->cambios_seguros);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function ordenManual(): array
    {
        return [[true], [false]];
    }

    public function test_next_appointment_is_selected_without_notices_and_never_substituted_on_send(): void
    {
        [$id, $r] = $this->cita(2880);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $this->assertSame($id, $datos['id_cita']);
        $inicio = CarbonImmutable::now('UTC')->addHour();
        $nuevo = DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15)]), 'id_cita');
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertStatus(409)
            ->assertJsonPath('error.code', 'RECORDATORIO_REVISION_OBSOLETA');
        $this->assertSame((string) $nuevo, $this->revisionManual($cookies, (string) $r['patient'])['id_cita']);
        $this->assertSame(0, DB::table('recordatorios')->count());
        DB::table('citas')->where('id_cita', $nuevo)->update(['estado' => 'Cancelada']);
        $this->assertSame($id, $this->revisionManual($cookies, (string) $r['patient'])['id_cita']);
    }

    #[DataProvider('cambiosRevisionManual')]
    public function test_manual_rejects_changes_since_review(array $cambios): void
    {
        [$id, $r] = $this->cita(60);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        if (isset($cambios['iniciada'])) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        } else {
            DB::table('citas')->where('id_cita', $id)->update($cambios);
        }
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertStatus(409);
        $this->assertSame(0, DB::table('recordatorios')->count());
    }

    public static function cambiosRevisionManual(): array
    {
        return [[['estado' => 'Cancelada']], [['estado' => 'Atendida']], [['estado' => 'Inasistencia']],
            [['estado' => 'Confirmada']], [['version_programacion' => 2]], [['consultorio_atencion' => 'Ubicación corregida']],
            [['actualizada_en' => '2026-11-08T12:00:00.000001Z']], [['iniciada' => true]]];
    }

    public function test_manual_respects_preferences_no_devices_disabled_channel_and_strict_input(): void
    {
        [, $r] = $this->cita(2880);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        DB::table('preferencias_paciente')->insert(['id_paciente' => $r['patient'], 'recordatorios_habilitados' => false, 'preferencias_privacidad' => '{}']);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertStatus(409)
            ->assertJsonPath('error.code', 'RECORDATORIO_PREFERENCIA_DESHABILITADA');
        $this->assertSame(0, DB::table('recordatorios')->count());
        DB::table('preferencias_paciente')->where('id_paciente', $r['patient'])->update(['recordatorios_habilitados' => true]);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', [...$datos, 'estado' => 'Programada'])->assertStatus(422);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk()->assertJsonPath('data.push', 'Sin_dispositivos');
        [, $otro] = $this->cita(2880);
        $this->suscribir($otro);
        $datos = $this->datosManual($cookies, (string) $otro['patient']);
        config(['push.habilitado' => false]);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk()->assertJsonPath('data.push', 'No_disponible');
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
        Http::assertNothingSent();
    }

    #[DataProvider('rolesSinManual')]
    public function test_manual_and_next_appointment_are_admin_only(string $rol): void
    {
        [, $r] = $this->cita();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $this->usuarioAuth($rol))->assertOk();
        $this->navegador($cookies, 'GET', '/api/recordatorios/proxima-cita?id_paciente='.$r['patient'])->assertForbidden();
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', [])->assertForbidden();
    }

    public static function rolesSinManual(): array
    {
        return [['Paciente'], ['Recepcionista'], ['Médico']];
    }

    public function test_manual_rollback_keeps_notice_audit_and_outbox_atomic(): void
    {
        [, $r] = $this->cita(2880);
        $this->suscribir($r);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $bitacora = Mockery::mock(BitacoraRepository::class)->makePartial();
        $bitacora->shouldReceive('recordatorioGenerado')->once()->andThrow(new RuntimeException('Fallo ficticio de auditoría'));
        $this->app->instance(BitacoraRepository::class, $bitacora);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertStatus(500);
        $this->assertSame(0, DB::table('recordatorios')->count());
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'RECORDATORIO_GENERADO')->count());
    }

    public function test_manual_patient_binding_empty_state_and_idempotency_conflicts(): void
    {
        [, $r] = $this->cita(2880);
        [$idOtro, $otro] = $this->cita(2880, 'Cancelada');
        [$cookies] = $this->administradorManual();
        $this->navegador($cookies, 'GET', '/api/recordatorios/proxima-cita?id_paciente='.$otro['patient'])
            ->assertOk()->assertJsonPath('data.cita', null);
        $this->navegador($cookies, 'GET', '/api/recordatorios/proxima-cita?id_paciente=9223372036854775807')->assertNotFound();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', [...$datos, 'id_cita' => $idOtro])->assertStatus(409);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk();
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', [...$datos, 'id_paciente' => (string) $otro['patient']])
            ->assertStatus(409)->assertJsonPath('error.code', 'RECORDATORIO_OPERACION_CONFLICTO');
        $this->assertSame(1, DB::table('recordatorios')->count());
    }

    public function test_manual_migration_never_erases_notices_on_rollback(): void
    {
        [, $r] = $this->cita(2880);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk();
        try {
            (require base_path('src/data/database/migrations/2026_11_07_000050_ampliar_recordatorios_manuales.php'))->down();
            $this->fail('No debe eliminar datos manuales para revertir el esquema.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se puede revertir', $e->getMessage());
        }
        $this->assertSame(1, DB::table('recordatorios')->where('origen', 'Manual')->count());
    }

    public function test_manual_migration_preserves_automatic_notice_and_outbox_on_down_up(): void
    {
        [, $r] = $this->cita();
        $this->suscribir($r);
        $this->generar();
        $antes = DB::table('envios_recordatorio_push')->get()->toJson();
        $id = DB::table('recordatorios')->value('id_recordatorio');
        $migracion = require base_path('src/data/database/migrations/2026_11_07_000050_ampliar_recordatorios_manuales.php');
        $migracion->down();
        $migracion->up();
        $this->assertSame($antes, DB::table('envios_recordatorio_push')->get()->toJson());
        $aviso = DB::table('recordatorios')->sole();
        $this->assertSame((string) $id, (string) $aviso->id_recordatorio);
        $this->assertSame('Automatico', $aviso->origen);
        $this->assertNull($aviso->operacion_manual);
        $this->assertNull($aviso->id_administrador);
    }

    public function test_manual_is_invalidated_on_rescheduling_and_new_version_has_its_own_limit(): void
    {
        [$id, $r] = $this->cita(2880);
        [$cookies] = $this->administradorManual();
        $datos = $this->datosManual($cookies, (string) $r['patient']);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $datos)->assertOk();
        DB::transaction(function () use ($id): void {
            app(RecordatorioService::class)->invalidarPorCita($id, CarbonImmutable::now('UTC'));
            DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2]);
        });
        $nuevo = $this->datosManual($cookies, (string) $r['patient']);
        $this->assertNotSame($datos['revision'], $nuevo['revision']);
        $this->navegador($cookies, 'POST', '/api/recordatorios/manual', $nuevo)->assertOk();
        $this->assertSame(1, DB::table('recordatorios')->whereNotNull('invalidado_en')->count());
        $this->assertSame(1, DB::table('recordatorios')->whereNull('invalidado_en')->count());
    }

    #[DataProvider('ventanas')]
    public function test_exact_windows_internal_until_end_and_push_only_before_start(int $minutos, string $estado, int $avisos, int $envios): void
    {
        [, $r] = $this->cita($minutos, $estado);
        $this->suscribir($r);
        $antes = DB::table('citas')->get()->toJson();
        $result = $this->generar();
        $this->assertSame($avisos, $result['avisos_creados']);
        $this->assertSame($envios, $result['envios_preparados']);
        $this->assertSame($avisos, DB::table('recordatorios')->count());
        $this->assertSame($envios, DB::table('envios_recordatorio_push')->count());
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
        $this->assertSame(0, $this->generar()['avisos_creados']);
        $this->assertSame(0, $this->generar()['envios_preparados']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function ventanas(): array
    {
        return [[1441, 'Programada', 0, 0], [1440, 'Programada', 1, 1], [1, 'Programada', 1, 1],
            [60, 'Confirmada', 1, 1], [0, 'Programada', 1, 0], [-14, 'Confirmada', 1, 0],
            [-15, 'Programada', 0, 0], [60, 'Atendida', 0, 0], [60, 'Cancelada', 0, 0], [60, 'Inasistencia', 0, 0]];
    }

    public function test_microsecond_boundaries_use_clinic_instants_without_rounding(): void
    {
        [$id] = $this->cita(1440);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->subMicrosecond());
        $this->assertSame(0, $this->generar()['avisos_creados']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMicrosecond());
        $this->assertSame(1, $this->generar()['avisos_creados']);
        $recordatorio = DB::table('recordatorios')->sole();
        $this->assertSame($id, (string) $recordatorio->id_cita);
    }

    public function test_no_web_account_or_preference_still_gets_one_internal_notice_and_safe_audit(): void
    {
        [$id, $r] = $this->cita();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false]);
        $this->generar();
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        $audit = DB::table('bitacora')->sole();
        $this->assertSame('SISTEMA', $audit->tipo_actor);
        $this->assertNull($audit->id_usuario_responsable);
        $this->assertSame('RECORDATORIO_GENERADO', $audit->tipo_evento);
        $this->assertSame($id, json_decode($audit->referencias, true)['id_cita']);
        $this->assertStringNotContainsString('Paciente ficticio', json_encode($audit));
        $this->assertNull(DB::table('recordatorios')->sole()->leido_en);
    }

    public function test_disabled_preference_blocks_generation_then_enabled_reconciles_without_duplicate(): void
    {
        [, $r] = $this->cita();
        $this->suscribir($r);
        DB::table('preferencias_paciente')->insert(['id_paciente' => $r['patient'], 'recordatorios_habilitados' => false, 'preferencias_privacidad' => '{}']);
        $this->assertSame(0, $this->generar()['avisos_creados']);
        DB::table('preferencias_paciente')->update(['recordatorios_habilitados' => true]);
        $this->assertSame(1, $this->generar()['avisos_creados']);
        DB::table('preferencias_paciente')->update(['recordatorios_habilitados' => false]);
        $this->assertSame(1, $this->generar()['envios_descartados']);
        DB::table('preferencias_paciente')->update(['recordatorios_habilitados' => true]);
        $this->assertSame(0, $this->generar()['envios_preparados']);
        $this->assertSame(1, DB::table('recordatorios')->count());
        $this->assertSame('Descartado', DB::table('envios_recordatorio_push')->sole()->estado);
    }

    #[DataProvider('autorizacionesInvalidas')]
    public function test_push_requires_current_account_and_subscription_not_browser_session(string $caso): void
    {
        [, $r] = $this->cita();
        $s = $this->suscribir($r);
        match ($caso) {
            'revocada' => DB::table('suscripciones_push')->update(['revocada_en' => now()]),
            'expirada' => DB::table('suscripciones_push')->update(['expira_en' => now()->subMinute()]),
            'version' => DB::table('suscripciones_push')->update(['huella_cuenta' => str_repeat('a', 64)]),
            'cuenta' => DB::table('usuarios')->where('id_usuario', $s->id_usuario)->update(['estado_cuenta' => 'Desactivada']),
            'password' => DB::table('usuarios')->where('id_usuario', $s->id_usuario)->update(['password_hash' => 'otra-huella-ficticia']),
            'vinculo' => DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => null]),
            'config' => config(['push.habilitado' => false]),
        };
        $this->assertSame(1, $this->generar()['avisos_creados']);
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public static function autorizacionesInvalidas(): array
    {
        return array_map(fn ($s) => [$s], ['revocada', 'expirada', 'version', 'cuenta', 'password', 'vinculo', 'config']);
    }

    public function test_multiple_devices_are_prepared_once_and_terminal_results_never_reopened(): void
    {
        [, $r] = $this->cita();
        $s = $this->suscribir($r);
        $otro = $s->replicate();
        $otro->forceFill(['instalacion_hash' => hash('sha256', 'otra-instalacion'), 'endpoint_hash' => hash('sha256', 'otro-endpoint')])->saveOrFail();
        $this->assertSame(2, $this->generar()['envios_preparados']);
        DB::table('envios_recordatorio_push')->update(['estado' => 'Aceptado']);
        $this->assertSame(0, $this->generar()['envios_preparados']);
        $this->assertSame(2, DB::table('envios_recordatorio_push')->where('estado', 'Aceptado')->count());
    }

    public function test_crash_gap_or_late_activation_recovers_outbox_from_already_persisted_notice(): void
    {
        [, $r] = $this->cita();
        $this->assertSame(1, $this->generar()['avisos_creados']);
        $notice = DB::table('recordatorios')->sole();
        $this->suscribir($r);
        $r = $this->generar();
        $this->assertSame(0, $r['avisos_creados']);
        $this->assertSame(1, $r['envios_preparados']);
        $this->assertEquals($notice, DB::table('recordatorios')->sole());
        $this->assertSame(1, DB::table('bitacora')->count());
    }

    public function test_reprogramming_new_version_invalidates_old_and_prepares_only_at_new_threshold(): void
    {
        [$id, $r] = $this->cita();
        $this->suscribir($r);
        $this->generar();
        $start = CarbonImmutable::now('UTC')->addHours(25);
        DB::table('citas')->where('id_cita', $id)->update(['inicio_at' => $start, 'fin_at' => $start->addMinutes(15), 'version_programacion' => 2]);
        $r = $this->generar();
        $this->assertSame(1, $r['avisos_invalidados']);
        $this->assertSame(1, $r['envios_descartados']);
        $this->assertSame(0, $r['avisos_creados']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame(1, $this->generar()['avisos_creados']);
        $this->assertSame(2, DB::table('recordatorios')->count());
        $this->assertSame(1, DB::table('envios_recordatorio_push')->where('estado', 'Pendiente')->count());
        $this->assertSame(2, DB::table('citas')->sole()->version_programacion);
    }

    public function test_authorized_location_change_keeps_notice_and_late_internal_notice_has_no_push(): void
    {
        [$id, $r] = $this->cita();
        $this->suscribir($r);
        $this->generar();
        $n = DB::table('recordatorios')->sole();
        DB::table('citas')->where('id_cita', $id)->update(['consultorio_atencion' => 'Ubicación corregida ficticia']);
        $this->assertSame(0, $this->generar()['avisos_creados']);
        $this->assertEquals($n, DB::table('recordatorios')->sole());
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame(1, $this->generar()['envios_descartados']);
        $this->assertNull(DB::table('recordatorios')->sole()->invalidado_en);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(15));
        $this->assertSame(1, $this->generar()['avisos_invalidados']);
    }

    #[DataProvider('finales')]
    public function test_final_state_withdraws_notice_and_pending_delivery_without_touching_appointment(string $estado): void
    {
        [$id, $r] = $this->cita();
        $this->suscribir($r);
        $this->generar();
        DB::table('citas')->where('id_cita', $id)->update(['estado' => $estado]);
        $before = DB::table('citas')->sole();
        $this->assertSame(1, $this->generar()['envios_descartados']);
        $this->assertNotNull(DB::table('recordatorios')->sole()->invalidado_en);
        $this->assertEquals($before, DB::table('citas')->sole());
        $this->assertSame(1, DB::table('recordatorios')->count());
    }

    public static function finales(): array
    {
        return [['Cancelada'], ['Atendida'], ['Inasistencia']];
    }

    public function test_invalidated_notice_is_not_resurrected_and_cursor_does_not_starve_later_rows(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->cita();
        }
        $this->assertSame(5, $this->generar(2)['avisos_creados']);
        DB::table('recordatorios')->update(['invalidado_en' => now()]);
        $this->assertSame(0, $this->generar(2)['avisos_creados']);
        $this->assertSame(5, DB::table('recordatorios')->count());
    }

    public function test_generation_and_outbox_roll_back_when_audit_or_outbox_fails_then_recover_once(): void
    {
        [, $r] = $this->cita();
        $this->suscribir($r);
        foreach (['audit', 'outbox'] as $tipo) {
            $class = $tipo === 'audit' ? BitacoraRepository::class : EnvioRecordatorioPushRepository::class;
            $original = app($class);
            $mock = Mockery::mock($class)->makePartial();
            $mock->shouldReceive($tipo === 'audit' ? 'recordatorioGenerado' : 'preparar')->andThrow(new RuntimeException('FALLO_FICTICIO'));
            $this->app->instance($class, $mock);
            try {
                $this->generar();
                $this->fail('Debía fallar.');
            } catch (RuntimeException $e) {
                $this->assertSame('FALLO_FICTICIO', $e->getMessage());
            } finally {
                $this->app->instance($class, $original);
            }
            $this->assertSame(0, DB::table('recordatorios')->count());
            $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
            $this->assertSame(0, DB::table('bitacora')->count());
        }
        $this->assertSame(1, $this->generar()['envios_preparados']);
        $this->assertSame(0, $this->generar()['envios_preparados']);
    }

    public function test_fresh_clock_after_subscription_wait_does_not_generate_past_end(): void
    {
        [, $r] = $this->cita(1);
        $this->suscribir($r);
        $repo = app(SuscripcionPushRepository::class);
        $mock = Mockery::mock(SuscripcionPushRepository::class)->makePartial();
        $mock->shouldReceive('paraPaciente')->andReturnUsing(function ($u, $p) use ($repo) {
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(16));

            return $repo->paraPaciente($u, $p);
        });
        $this->app->instance(SuscripcionPushRepository::class, $mock);
        $this->assertSame(0, $this->generar()['avisos_creados']);
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
    }

    public function test_console_validates_batch_and_schedule_runs_generation_each_minute_not_delivery(): void
    {
        $this->cita();
        $this->artisan('recordatorios:generar', ['--lote' => '01'])->assertFailed();
        $this->assertSame(0, DB::table('recordatorios')->count());
        $this->artisan('recordatorios:generar', ['--lote' => '2'])->assertSuccessful();
        $events = collect(Schedule::events())->filter(fn ($e) => str_contains($e->command ?? '', 'recordatorios:generar'))->values();
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(1, DB::table('recordatorios')->count());
        Http::assertNothingSent();
    }

    public function test_real_cancellation_api_invalidates_generated_notice_and_next_tick_discards_its_outbox(): void
    {
        [$id, $r] = $this->cita();
        $this->suscribir($r);
        $this->assertSame(1, $this->generar()['envios_preparados']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        $admin = $this->usuarioAuth('Administrador');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $d = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', [
            'version_programacion_original' => $d['version_programacion'], 'actualizada_en_original' => $d['actualizada_en'],
        ])->assertOk()->assertJsonPath('data.estado', 'Cancelada');
        $this->assertNotNull(DB::table('recordatorios')->sole()->invalidado_en);
        $this->assertSame(1, $this->generar()['envios_descartados']);
        $this->assertSame(1, DB::table('recordatorios')->count());
        $this->assertSame(1, DB::table('cambios_cita')->where('operacion', 'CANCELACION')->count());
        Http::assertNothingSent();
    }
}
