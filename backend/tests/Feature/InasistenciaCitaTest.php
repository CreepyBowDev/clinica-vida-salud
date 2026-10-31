<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\AsistenciaService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class InasistenciaCitaTest extends TestCase
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
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'session.lifetime' => 4320]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T13:00:00Z');
    }

    private function contexto(string $rol = 'Administrador', array $campos = []): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'version_programacion' => 4, 'actualizada_en' => '2026-11-01T13:00:00.000000Z',
            'id_propuesta_reserva' => (string) Str::uuid(), 'motivo_breve' => 'MOTIVO-CLINICO-PRIVADO', ...$campos]), 'id_cita');

        return [$cookies, $r, $id, $actor];
    }

    private function consultar(array &$cookies, string $id): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia');
    }

    private function datos(array &$cookies, string $id): array
    {
        $vista = $this->consultar($cookies, $id)->assertOk()->json('data');

        return ['version_programacion_original' => $vista['version_programacion'],
            'actualizada_en_original' => $vista['actualizada_en'], 'ausencia_verificada' => true];
    }

    private function inasistencia(array &$cookies, string $id, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos);
    }

    private function snapshot(): array
    {
        $datos = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'consultas_medicas', 'bitacora'] as $tabla) {
            $datos[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $datos;
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_absence_preserves_programming_and_invalidates_only_existing_own_reminders(string $rol): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol);
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']), 'id_cita');
        DB::table('recordatorios')->insert([
            ['id_cita' => $id, 'version_programacion' => 2, 'generado_en' => now(), 'leido_en' => null, 'invalidado_en' => '2026-10-31T13:00:00Z'],
            ['id_cita' => $id, 'version_programacion' => 3, 'generado_en' => now(), 'leido_en' => null, 'invalidado_en' => null],
            ['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now(), 'leido_en' => now(), 'invalidado_en' => null],
            ['id_cita' => $otro, 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => null, 'invalidado_en' => null],
        ]);
        $datos = $this->datos($cookies, $id);
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        $avisoAnterior = DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 2)->first();
        $avisoAjeno = DB::table('recordatorios')->where('id_cita', $otro)->first();
        $respuesta = $this->inasistencia($cookies, $id, $datos)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.estado', 'Inasistencia')->assertJsonPath('data.version_programacion', 4)
            ->assertJsonPath('data.actualizada_en', '2026-11-01T13:00:00.000001Z')
            ->assertJsonPath('data.ultimo_cambio.operacion', 'INASISTENCIA')
            ->assertJsonPath('data.ultimo_cambio.id_actor', (string) $actor->getKey())
            ->assertJsonMissingPath('data.ausencia_verificada')->assertJsonMissingPath('data.acciones');
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['estado', 'actualizada_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $this->assertEquals($avisoAnterior, DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 2)->first());
        $this->assertEquals($avisoAjeno, DB::table('recordatorios')->where('id_cita', $otro)->first());
        foreach ([3, 4] as $version) {
            $aviso = DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', $version)->sole();
            $this->assertTrue(CarbonImmutable::parse($aviso->invalidado_en)->equalTo(CarbonImmutable::now('UTC')));
            $this->assertSame($version === 4, $aviso->leido_en !== null);
        }
        $this->assertSame(4, DB::table('recordatorios')->count());
        $this->assertSame(0, DB::table('consultas_medicas')->count());
        $cambio = DB::table('cambios_cita')->where('id_cita', $id)->sole();
        $this->assertSame('INASISTENCIA', $cambio->operacion);
        $this->assertSame('Programada', $cambio->estado_anterior);
        $this->assertSame('Inasistencia', $cambio->estado_nuevo);
        $this->assertSame((string) $actor->getKey(), (string) $cambio->id_actor);
        $this->assertNull($cambio->motivo);
        $this->assertTrue(json_decode($cambio->datos_nuevos)->ausencia_verificada);
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_INASISTENCIA_REGISTRADA')->sole();
        $this->assertSame((string) $actor->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        $this->assertSame((string) $r['doctors'][0], json_decode($evento->referencias)->id_medico);
        foreach ([$respuesta->getContent(), json_encode($evento), $cambio->datos_anteriores, $cambio->datos_nuevos] as $salida) {
            $this->assertStringNotContainsString('MOTIVO-CLINICO-PRIVADO', $salida);
        }
        $this->assertStringNotContainsString('ausencia_verificada', json_encode($evento));
        if ($rol === 'Administrador') {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento->id_evento)->assertOk()
                ->assertJsonPath('data.cambios_seguros.antes.estado', 'Programada')
                ->assertJsonPath('data.cambios_seguros.despues.estado', 'Inasistencia')
                ->assertJsonPath('data.referencias.id_cita', $id);
        }
        $beforeReplay = $this->snapshot();
        $this->inasistencia($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $actuales = $this->datos($cookies, $id);
        $this->inasistencia($cookies, $id, $actuales)->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', false)
            ->assertJsonPath('data.acciones.registrar_inasistencia', false)->assertJsonPath('data.acciones.marcar_atendida', false);
        $this->assertSame($beforeReplay, $this->snapshot());
    }

    public static function instantes(): array
    {
        return [['2026-11-01T11:59:59.999999Z', 409], ['2026-11-01T12:00:00.000000Z', 409],
            ['2026-11-01T12:14:59.999999Z', 409], ['2026-11-01T12:15:00.000000Z', 200],
            ['2026-11-01T12:15:00.000001Z', 200], ['2026-11-02T04:00:00.000000Z', 200]];
    }

    #[DataProvider('instantes')]
    public function test_absence_only_at_or_after_end_without_same_day_limit(string $ahora, int $status): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        CarbonImmutable::setTestNow($ahora);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.registrar_inasistencia', $status === 200);
        $response = $this->inasistencia($cookies, $id, $datos)->assertStatus($status);
        if ($status === 409) {
            $response->assertJsonPath('error.code', 'CITA_INASISTENCIA_ANTICIPADA');
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function otrosEstados(): array
    {
        return [['Confirmada'], ['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('otrosEstados')]
    public function test_confirmed_presence_and_final_states_cannot_be_marked_absent(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => $estado]);
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.registrar_inasistencia', false);
        $this->inasistencia($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function consultas(): array
    {
        return [[null], ['2026-11-01T12:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_any_consultation_blocks_absence(?string $iniciada): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA', 'iniciada_en' => $iniciada]);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.registrar_inasistencia', false);
        $this->inasistencia($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidos(): array
    {
        return [[['ausencia_verificada' => false]], [['ausencia_verificada' => null]], [['ausencia_verificada' => 0]],
            [['ausencia_verificada' => 1]], [['ausencia_verificada' => 'true']], [['ausencia_verificada' => '1']],
            [['ausencia_verificada' => []]], [['ausencia_verificada' => ['valor' => true]]],
            [['presencia_verificada' => true]], [['atencion_realizada' => true]], [['estado' => 'Inasistencia']],
            [['id_actor' => '1']], [['id_medico' => '1']], [['id_paciente' => '1']], [['rol' => 'Administrador']],
            [['motivo' => 'PRIVADO']], [['realizado_en' => '2026-11-01T13:00:00Z']], [['id_objetivo' => '1']],
            [['version_programacion_original' => 0]], [['version_programacion_original' => 2147483648]],
            [['version_programacion_original' => '04']], [['actualizada_en_original' => '2026-11-01T13:00:00Z']],
            [['actualizada_en_original' => '2026-02-30T13:00:00.000000Z']]];
    }

    #[DataProvider('invalidos')]
    public function test_strict_declaration_and_closed_payload(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, [...$datos, ...$extra])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_fields_query_ids_and_unknown_cita(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        foreach (array_keys($datos) as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->inasistencia($cookies, $id, $incompleto)->assertUnprocessable();
        }
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $invalido) {
            $this->inasistencia($cookies, $invalido, $datos)->assertUnprocessable();
        }
        $this->inasistencia($cookies, '9223372036854775807', $datos)->assertNotFound();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia?ausencia_verificada=1', $datos)->assertUnprocessable();
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public static function originalesObsoletos(): array
    {
        return [['version_programacion_original', 3], ['actualizada_en_original', '2026-11-01T13:00:00.000001Z']];
    }

    #[DataProvider('originalesObsoletos')]
    public function test_stale_originals_do_not_invalidate_reminders(string $campo, mixed $valor): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, [...$datos, $campo => $valor])->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_maximum_version_bigint_and_future_timestamp_are_preserved(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['id_cita' => '9223372036854775807',
            'version_programacion' => 2147483647, 'actualizada_en' => '2026-11-01T13:00:00.123456Z']);
        $this->inasistencia($cookies, $id, $this->datos($cookies, $id))->assertOk()->assertJsonPath('data.id_cita', '9223372036854775807')
            ->assertJsonPath('data.version_programacion', 2147483647)->assertJsonPath('data.actualizada_en', '2026-11-01T13:00:00.123457Z');
    }

    public function test_inactive_resources_and_no_schedule_do_not_block_old_cita(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => null]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        $this->inasistencia($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_consultorio', (string) $r['rooms'][0]);
        $this->assertSame(0, DB::table('recordatorios')->count());
    }

    public static function otrosRoles(): array
    {
        return [['Paciente'], ['Médico']];
    }

    #[DataProvider('otrosRoles')]
    public function test_forbidden_roles_in_route_and_service(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        $this->inasistencia($cookies, $id, [])->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(AsistenciaService::class)->registrarInasistencia($id, [], []);
    }

    public function test_service_also_requires_boolean_true(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Comprueba la ausencia');
        $this->app->make(AsistenciaService::class)->registrarInasistencia($id, [...$datos, 'ausencia_verificada' => 1], []);
    }

    public function test_session_csrf_origin_and_revocation_are_required(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/inasistencia', $datos,
            ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->consultar($cookies, $id)->assertUnauthorized();
        $this->inasistencia($cookies, $id, $datos)->assertUnauthorized();
        $anonimo = $this->csrfAuth();
        $this->inasistencia($anonimo, $id, $datos)->assertUnauthorized();
    }

    public function test_real_restricted_session_cannot_mark_absence(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $target = $this->usuarioAuth('Recepcionista');
        $temporal = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $temporal)->assertOk();
        $this->inasistencia($restricted, $id, [])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public static function fallos(): array
    {
        return [['guardar'], ['agregarCambio'], ['invalidarPorCita'], ['citaInasistenciaRegistrada'], ['ultimoCambio']];
    }

    #[DataProvider('fallos')]
    public function test_failure_after_real_writes_or_final_read_rolls_back_everything(string $metodo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $clase = match ($metodo) {
            'invalidarPorCita' => RecordatorioRepository::class,
            'citaInasistenciaRegistrada' => BitacoraRepository::class,
            default => CitaRepository::class,
        };
        $real = new $clase;
        $mock = Mockery::mock($clase)->makePartial();
        $mock->shouldReceive($metodo)->once()->andReturnUsing(function (...$args) use ($real, $metodo): never {
            $real->$metodo(...$args);
            throw new RuntimeException('FALLO-INASISTENCIA-PRIVADO');
        });
        $this->app->instance($clase, $mock);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-INASISTENCIA-PRIVADO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function despuesDePlanificacion(): array
    {
        return [['cuenta', 401], ['rol', 401], ['consulta', 409]];
    }

    #[DataProvider('despuesDePlanificacion')]
    public function test_rechecks_after_waiting(string $cambio, int $status): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $otroRol = $this->usuarioAuth('Recepcionista')->id_rol;
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($cambio, $actor, $id, $otroRol): void {
            match ($cambio) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'rol' => DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['id_rol' => $otroRol]),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-ESPERA']),
            };
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, $datos)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
    }

    public static function cambiosBajoLock(): array
    {
        return [['version', 'CITA_EDICION_OBSOLETA'], ['timestamp', 'CITA_EDICION_OBSOLETA'],
            ['estado', 'CITA_TRANSICION_NO_PERMITIDA'], ['consulta', 'CITA_CON_ATENCION_DOCUMENTADA']];
    }

    #[DataProvider('cambiosBajoLock')]
    public function test_cita_is_reread_under_lock(string $cambio, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porIdParaAsistencia')->with($id, null, true)->once()->andReturnUsing(function () use ($id, $cambio) {
            match ($cambio) {
                'version' => DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 5]),
                'timestamp' => DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-11-01T13:00:00.123456Z']),
                'estado' => DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Confirmada']),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-LOCK']),
            };

            return (new CitaRepository)->porIdParaAsistencia($id, null, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_time_is_rechecked_after_consultation_query_before_writing(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('tieneConsulta')->once()->andReturnUsing(function (): bool {
            CarbonImmutable::setTestNow('2026-11-01T12:14:59.999999Z');

            return false;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->inasistencia($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_INASISTENCIA_ANTICIPADA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_wait_can_cross_end_and_manual_confirmation_uses_current_clock(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        CarbonImmutable::setTestNow('2026-11-01T12:14:59.999999Z');
        $datos = $this->datos($cookies, $id);
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.registrar_inasistencia', false);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('tieneConsulta')->once()->andReturnUsing(function (): bool {
            CarbonImmutable::setTestNow('2026-11-01T12:15:00Z');

            return false;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $this->inasistencia($cookies, $id, $datos)->assertOk()->assertJsonPath('data.estado', 'Inasistencia');
    }

    public function test_read_is_private_read_only_and_atendida_requires_its_own_declaration(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.acciones.confirmar_llegada', false)->assertJsonPath('data.acciones.registrar_inasistencia', true)
            ->assertJsonPath('data.acciones.marcar_atendida', true)->assertDontSee('MOTIVO-CLINICO-PRIVADO');
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id.'/asistencia')->assertOk()->assertContent('');
        $this->assertSame($before, $this->snapshot());
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion')->assertUnprocessable();
    }

    public function test_audit_resource_filters_injected_fields_and_invalid_states(): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['id_evento' => 1, 'tipo_evento' => 'CITA_INASISTENCIA_REGISTRADA', 'version_formato' => 1,
            'ocurrido_en' => now(), 'registrado_en' => now(), 'referencias' => ['id_cita' => '1', 'id_medico' => '-1', 'motivo' => 'SECRETO'],
            'cambios_seguros' => ['antes' => ['estado' => 'Confirmada', 'version_programacion' => 4],
                'despues' => ['estado' => 'Cancelada', 'version_programacion' => 2147483648, 'ausencia_verificada' => true, 'diagnostico' => 'SECRETO']],
            'metadatos_seguros' => ['motivo' => 'SECRETO']]);
        $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
        $this->assertSame('1', $salida['referencias']->id_cita);
        $this->assertObjectNotHasProperty('id_medico', $salida['referencias']);
        $this->assertObjectNotHasProperty('estado', $salida['cambios_seguros']->antes);
        $this->assertObjectNotHasProperty('despues', $salida['cambios_seguros']);
        $this->assertStringNotContainsString('SECRETO', json_encode($salida));
        $this->assertStringNotContainsString('ausencia_verificada', json_encode($salida));
    }
}
