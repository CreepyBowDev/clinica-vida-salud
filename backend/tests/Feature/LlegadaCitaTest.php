<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\AsistenciaService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
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

class LlegadaCitaTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function contexto(string $rol = 'Administrador', array $campos = []): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        if ($rol === 'Médico') {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actor->getKey(), 'activo' => false]);
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'version_programacion' => 4, 'actualizada_en' => '2026-11-01T11:00:00.000000Z',
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
            'actualizada_en_original' => $vista['actualizada_en'], 'presencia_verificada' => true];
    }

    private function llegada(array &$cookies, string $id, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/llegada', $datos);
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
    public function test_arrival_is_atomic_preserves_programming_and_all_reminders(string $rol): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol);
        DB::table('recordatorios')->insert([
            ['id_cita' => $id, 'version_programacion' => 3, 'generado_en' => now(), 'invalidado_en' => now(), 'leido_en' => null],
            ['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now(), 'invalidado_en' => null, 'leido_en' => now()],
        ]);
        $datos = $this->datos($cookies, $id);
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        $avisos = DB::table('recordatorios')->get()->toJson();
        $respuesta = $this->llegada($cookies, $id, $datos)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.estado', 'Confirmada')->assertJsonPath('data.version_programacion', 4)
            ->assertJsonPath('data.actualizada_en', '2026-11-01T11:00:00.000001Z')
            ->assertJsonPath('data.ultimo_cambio.id_actor', (string) $actor->getKey())
            ->assertJsonPath('data.ultimo_cambio.operacion', 'LLEGADA')->assertJsonMissingPath('data.presencia_verificada');
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['estado', 'actualizada_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $this->assertSame($avisos, DB::table('recordatorios')->get()->toJson());
        $this->assertSame(0, DB::table('consultas_medicas')->count());
        $cambio = DB::table('cambios_cita')->where('id_cita', $id)->sole();
        $this->assertSame('LLEGADA', $cambio->operacion);
        $this->assertSame('Programada', $cambio->estado_anterior);
        $this->assertSame('Confirmada', $cambio->estado_nuevo);
        $this->assertNull($cambio->motivo);
        $this->assertSame((string) $actor->getKey(), (string) $cambio->id_actor);
        $this->assertTrue(json_decode($cambio->datos_nuevos)->presencia_verificada);
        $this->assertTrue(CarbonImmutable::parse($cambio->realizado_en)->equalTo(CarbonImmutable::now('UTC')));
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_LLEGADA_CONFIRMADA')->sole();
        $this->assertSame((string) $actor->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        $this->assertSame((string) $r['doctors'][0], json_decode($evento->referencias)->id_medico);
        foreach ([$respuesta->getContent(), json_encode($evento), $cambio->datos_anteriores, $cambio->datos_nuevos] as $salida) {
            $this->assertStringNotContainsString('MOTIVO-CLINICO-PRIVADO', $salida);
        }
        $this->assertStringNotContainsString('presencia_verificada', json_encode($evento));
        if ($rol === 'Administrador') {
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento->id_evento)->assertOk()
                ->assertJsonPath('data.cambios_seguros.antes.estado', 'Programada')
                ->assertJsonPath('data.cambios_seguros.despues.estado', 'Confirmada')
                ->assertJsonPath('data.referencias.id_cita', $id);
        }
        $beforeReplay = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->llegada($cookies, $id, $this->datos($cookies, $id))->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->assertSame($beforeReplay, $this->snapshot());
    }

    public static function instantes(): array
    {
        return [
            ['2026-11-01T03:59:59.999999Z', 409], // Aún es el día anterior en La Paz.
            ['2026-11-01T04:00:00.000000Z', 200],
            ['2026-11-01T11:59:59.999999Z', 200],
            ['2026-11-01T12:00:00.000000Z', 200],
            ['2026-11-01T12:14:59.999999Z', 200],
            ['2026-11-01T12:15:00.000000Z', 409],
            ['2026-11-01T12:15:00.000001Z', 409],
            ['2026-11-02T04:00:00.000000Z', 409],
        ];
    }

    #[DataProvider('instantes')]
    public function test_local_day_and_half_open_interval(string $ahora, int $status): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        CarbonImmutable::setTestNow($ahora);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', $status === 200);
        $response = $this->llegada($cookies, $id, $datos)->assertStatus($status);
        if ($status === 409) {
            $response->assertJsonPath('error.code', 'CITA_LLEGADA_FUERA_DE_PLAZO');
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function otrosEstados(): array
    {
        return [['Confirmada'], ['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('otrosEstados')]
    public function test_only_programada_can_confirm_arrival(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => $estado]);
        $datos = $this->datos($cookies, $id);
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', false);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function consultas(): array
    {
        return [[null], ['2026-11-01T11:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_any_consultation_blocks_arrival(?string $iniciada): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA', 'iniciada_en' => $iniciada]);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', false);
        $this->llegada($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidos(): array
    {
        return [
            [['presencia_verificada' => false]], [['presencia_verificada' => null]], [['presencia_verificada' => 0]],
            [['presencia_verificada' => 1]], [['presencia_verificada' => 'true']], [['presencia_verificada' => '1']],
            [['presencia_verificada' => []]], [['presencia_verificada' => ['valor' => true]]],
            [['estado' => 'Confirmada']], [['id_actor' => '1']], [['rol' => 'Administrador']], [['id_medico' => '1']],
            [['id_paciente' => '1']], [['id_objetivo' => '1']], [['atencion_realizada' => true]], [['ausencia_verificada' => true]],
            [['fecha' => '2026-11-01']], [['motivo' => 'PRIVADO']],
            [['version_programacion_original' => 0]], [['version_programacion_original' => 2147483648]],
            [['version_programacion_original' => '04']], [['version_programacion_original' => '4e0']],
            [['actualizada_en_original' => '2026-11-01T11:00:00Z']],
            [['actualizada_en_original' => '2026-02-30T11:00:00.000000Z']],
            [['actualizada_en_original' => '2026-11-01T07:00:00.000000-04:00']],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_closed_inputs_and_strict_declaration(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, [...$datos, ...$extra])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_required_fields_and_queries_are_rejected(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        foreach (array_keys($datos) as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->llegada($cookies, $id, $incompleto)->assertUnprocessable();
        }
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/llegada?presencia_verificada=1', $datos)->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia?estado=Programada')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia', ['id_actor' => '1'])->assertUnprocessable();
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public function test_bigint_ids_canonical_originals_and_monotonic_timestamp(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['id_cita' => '9223372036854775807',
            'version_programacion' => 2147483647, 'actualizada_en' => '2026-11-01T11:00:00.123456Z']);
        $this->assertSame('9223372036854775807', $id);
        $datos = $this->datos($cookies, $id);
        $this->llegada($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_cita', $id)
            ->assertJsonPath('data.version_programacion', 2147483647)->assertJsonPath('data.actualizada_en', '2026-11-01T11:00:00.123457Z');
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $invalido) {
            $this->consultar($cookies, $invalido)->assertUnprocessable();
            $this->llegada($cookies, $invalido, $datos)->assertUnprocessable();
        }
        $this->consultar($cookies, '9223372036854775806')->assertNotFound();
        $this->llegada($cookies, '9223372036854775806', $datos)->assertNotFound();
    }

    public static function versiones(): array
    {
        return [['version_programacion_original', 3], ['actualizada_en_original', '2026-11-01T11:00:00.000001Z']];
    }

    #[DataProvider('versiones')]
    public function test_stale_originals_reject_without_effects(string $campo, mixed $valor): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, [...$datos, $campo => $valor])->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_cita_with_inactive_resources_and_no_schedule_can_confirm_arrival(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => null]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        $this->llegada($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_consultorio', (string) $r['rooms'][0]);
    }

    public function test_get_head_are_private_and_only_eligible_actions_are_advertised(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $before = $this->snapshot();
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id.'/asistencia')->assertOk()->assertContent('')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->consultar($cookies, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.acciones.confirmar_llegada', true)->assertJsonPath('data.acciones.registrar_inasistencia', false)
            ->assertJsonPath('data.acciones.marcar_atendida', false)->assertJsonPath('data.ultimo_cambio', null)
            ->assertJsonPath('data.impedimentos.marcar_atendida', ['CITA_ATENCION_ANTICIPADA'])
            ->assertDontSee('MOTIVO-CLINICO-PRIVADO')->assertJsonMissingPath('data.id_propuesta_reserva')
            ->assertJsonMissingPath('data.creada_por')->assertJsonMissingPath('data.motivo_breve')->assertJsonMissingPath('data.documento_identidad');
        $this->assertSame($before, $this->snapshot());
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion')->assertUnprocessable();
    }

    public function test_doctor_only_reads_own_cita_even_if_professionally_inactive_and_never_confirms_arrival(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', false)
            ->assertJsonPath('data.impedimentos.confirmar_llegada', ['ACCESO_DENEGADO']);
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1],
            'id_consultorio' => $r['rooms'][1], 'inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']), 'id_cita');
        $this->consultar($cookies, $otro)->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
        $this->consultar($cookies, '9223372036854775807')->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
        $this->llegada($cookies, $id, [])->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertForbidden();
    }

    public function test_doctor_with_incompatible_dual_link_is_denied(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico');
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actor->getKey()]);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_doctor_link_is_rechecked_after_waiting_without_returning_old_scope(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico');
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($r, $actor): void {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => null]);
            DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_usuario' => $actor->getKey()]);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertUnauthorized()->assertJsonMissingPath('data');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_read_scope_is_enforced_in_direct_service_calls(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1],
            'id_consultorio' => $r['rooms'][1], 'inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']), 'id_cita');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No se encontró el recurso');
        $this->app->make(AsistenciaService::class)->consultar($otro);
    }

    #[DataProvider('otrosEstados')]
    public function test_reading_final_or_confirmed_states_preserves_last_change_metadata(string $estado): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico', ['estado' => $estado]);
        DB::table('cambios_cita')->insert(['id_cita' => $id, 'id_actor' => $actor->getKey(), 'operacion' => 'FIXTURE',
            'realizado_en' => now(), 'estado_anterior' => 'Programada', 'estado_nuevo' => $estado,
            'datos_nuevos' => json_encode(['diagnostico' => 'NO-EXPONER-SNAPSHOT']), 'motivo' => 'NO-EXPONER-MOTIVO']);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.estado', $estado)
            ->assertJsonPath('data.ultimo_cambio.operacion', 'FIXTURE')->assertJsonPath('data.acciones.marcar_atendida', false)
            ->assertDontSee('NO-EXPONER');
        $this->assertSame($before, $this->snapshot());
    }

    public static function otrosRoles(): array
    {
        return [['Paciente'], ['Médico']];
    }

    #[DataProvider('otrosRoles')]
    public function test_arrival_role_is_checked_in_service_too(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        if ($rol === 'Paciente') {
            $this->consultar($cookies, $id)->assertForbidden();
        }
        $this->llegada($cookies, $id, [])->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(AsistenciaService::class)->confirmarLlegada($id, [], []);
    }

    public function test_service_requires_true_even_with_valid_originals(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Comprueba presencialmente');
        $this->app->make(AsistenciaService::class)->confirmarLlegada($id, [...$datos, 'presencia_verificada' => 1], []);
    }

    public function test_csrf_origin_anonymous_and_revoked_sessions(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/llegada', $datos, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/asistencia', headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->consultar($cookies, $id)->assertUnauthorized();
        $this->llegada($cookies, $id, $datos)->assertUnauthorized();
        $anonymous = $this->csrfAuth();
        $this->consultar($anonymous, $id)->assertUnauthorized();
        $this->llegada($anonymous, $id, $datos)->assertUnauthorized();
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public function test_real_restricted_session_cannot_read_or_confirm(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $target = $this->usuarioAuth('Recepcionista');
        $temporal = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $temporal)->assertOk();
        $this->consultar($restricted, $id)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->llegada($restricted, $id, [])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public static function fallos(): array
    {
        return [['guardar'], ['agregarCambio'], ['citaLlegadaConfirmada'], ['ultimoCambio']];
    }

    #[DataProvider('fallos')]
    public function test_failure_after_each_real_write_or_final_read_rolls_back(string $metodo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $clase = $metodo === 'citaLlegadaConfirmada' ? BitacoraRepository::class : CitaRepository::class;
        $real = new $clase;
        $mock = Mockery::mock($clase)->makePartial();
        $mock->shouldReceive($metodo)->once()->andReturnUsing(function (...$args) use ($real, $metodo): never {
            $real->$metodo(...$args);
            throw new RuntimeException('FALLO-LLEGADA-PRIVADO');
        });
        $this->app->instance($clase, $mock);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-LLEGADA-PRIVADO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function despuesDePlanificacion(): array
    {
        return [['cuenta', 401], ['rol', 401], ['consulta', 409], ['plazo', 409]];
    }

    #[DataProvider('despuesDePlanificacion')]
    public function test_conditions_are_rechecked_after_waiting(string $cambio, int $status): void
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
                'plazo' => CarbonImmutable::setTestNow('2026-11-01T12:15:00Z'),
            };
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clock_is_fresh_after_consultation_query(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('tieneConsulta')->once()->andReturnUsing(function (): bool {
            CarbonImmutable::setTestNow('2026-11-01T12:15:00Z');

            return false;
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_LLEGADA_FUERA_DE_PLAZO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function cambiosBajoLock(): array
    {
        return [['version', 'CITA_EDICION_OBSOLETA'], ['timestamp', 'CITA_EDICION_OBSOLETA'],
            ['estado', 'CITA_TRANSICION_NO_PERMITIDA'], ['consulta', 'CITA_CON_ATENCION_DOCUMENTADA']];
    }

    #[DataProvider('cambiosBajoLock')]
    public function test_cita_is_read_again_under_lock(string $cambio, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porIdParaAsistencia')->with($id, null, true)->once()->andReturnUsing(function () use ($id, $cambio) {
            match ($cambio) {
                'version' => DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 5]),
                'timestamp' => DB::table('citas')->where('id_cita', $id)->update(['actualizada_en' => '2026-11-01T11:00:00.123456Z']),
                'estado' => DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Atendida']),
                'consulta' => DB::table('consultas_medicas')->insert(['id_cita' => $id, 'codigo_consulta' => 'CONS-LOCK']),
            };

            return (new CitaRepository)->porIdParaAsistencia($id, null, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->llegada($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_audit_projection_drops_unexpected_payloads(): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['id_evento' => 1, 'tipo_evento' => 'CITA_LLEGADA_CONFIRMADA', 'version_formato' => 1,
            'ocurrido_en' => now(), 'registrado_en' => now(), 'referencias' => ['id_cita' => '1', 'id_medico' => '-1', 'motivo' => 'SECRETO'],
            'cambios_seguros' => ['antes' => ['estado' => 'Confirmada', 'version_programacion' => 4, 'motivo' => 'SECRETO'],
                'despues' => ['estado' => 'Cancelada', 'version_programacion' => 2147483648, 'presencia_verificada' => true, 'diagnostico' => 'SECRETO']],
            'metadatos_seguros' => ['motivo' => 'SECRETO']]);
        $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
        $this->assertSame('1', $salida['referencias']->id_cita);
        $this->assertObjectNotHasProperty('id_medico', $salida['referencias']);
        $this->assertObjectNotHasProperty('estado', $salida['cambios_seguros']->antes);
        $this->assertObjectNotHasProperty('despues', $salida['cambios_seguros']);
        $this->assertStringNotContainsString('SECRETO', json_encode($salida));
        $this->assertStringNotContainsString('presencia_verificada', json_encode($salida));
    }
}
