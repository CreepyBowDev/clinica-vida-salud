<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\AsistenciaService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
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

class AtencionCitaTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-01T12:05:00Z');
    }

    private function contexto(string $rol = 'Administrador', array $campos = []): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        if ($rol === 'Médico') {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actor->getKey()]);
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, [
            'version_programacion' => 4, 'actualizada_en' => '2026-11-01T12:05:00.000000Z',
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
            'actualizada_en_original' => $vista['actualizada_en'], 'atencion_realizada' => true];
    }

    private function atender(array &$cookies, string $id, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion', $datos);
    }

    private function snapshot(): array
    {
        $datos = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'consultas_medicas', 'versiones_consulta', 'bitacora'] as $tabla) {
            $datos[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $datos;
    }

    public static function operadores(): array
    {
        return [['Administrador', 'Programada'], ['Administrador', 'Confirmada'], ['Médico', 'Programada'], ['Médico', 'Confirmada']];
    }

    #[DataProvider('operadores')]
    public function test_real_attention_preserves_identity_programming_and_actor_separation(string $rol, string $estado): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol, ['estado' => $estado]);
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
        $response = $this->atender($cookies, $id, $datos)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.estado', 'Atendida')->assertJsonPath('data.version_programacion', 4)
            ->assertJsonPath('data.actualizada_en', '2026-11-01T12:05:00.000001Z')
            ->assertJsonPath('data.id_medico', (string) $r['doctors'][0])
            ->assertJsonPath('data.ultimo_cambio.id_actor', (string) $actor->getKey())
            ->assertJsonPath('data.ultimo_cambio.operacion', 'ATENCION')
            ->assertJsonMissingPath('data.atencion_realizada')->assertJsonMissingPath('data.acciones');
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach ($antes as $campo => $valor) {
            if (! in_array($campo, ['estado', 'actualizada_en'], true)) {
                $this->assertSame($valor, $despues[$campo], $campo);
            }
        }
        $this->assertSame(0, DB::table('consultas_medicas')->count());
        $this->assertSame(0, DB::table('versiones_consulta')->count());
        $this->assertSame(4, DB::table('recordatorios')->count());
        $this->assertEquals($avisoAnterior, DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', 2)->first());
        $this->assertEquals($avisoAjeno, DB::table('recordatorios')->where('id_cita', $otro)->first());
        foreach ([3, 4] as $version) {
            $aviso = DB::table('recordatorios')->where('id_cita', $id)->where('version_programacion', $version)->sole();
            $this->assertTrue(CarbonImmutable::parse($aviso->invalidado_en)->equalTo(CarbonImmutable::now('UTC')));
            $this->assertSame($version === 4, $aviso->leido_en !== null);
        }
        $cambio = DB::table('cambios_cita')->where('id_cita', $id)->sole();
        $this->assertSame('ATENCION', $cambio->operacion);
        $this->assertSame($estado, $cambio->estado_anterior);
        $this->assertSame('Atendida', $cambio->estado_nuevo);
        $this->assertSame((string) $actor->getKey(), (string) $cambio->id_actor);
        $this->assertNull($cambio->motivo);
        $this->assertTrue(json_decode($cambio->datos_nuevos)->atencion_realizada);
        $evento = DB::table('bitacora')->where('tipo_evento', 'CITA_ATENDIDA')->sole();
        $this->assertSame((string) $actor->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame($rol, $evento->rol_responsable);
        $this->assertSame((string) $r['doctors'][0], json_decode($evento->referencias)->id_medico);
        foreach ([$response->getContent(), json_encode($evento), $cambio->datos_anteriores, $cambio->datos_nuevos] as $salida) {
            $this->assertStringNotContainsString('MOTIVO-CLINICO-PRIVADO', $salida);
        }
        $this->assertStringNotContainsString('atencion_realizada', json_encode($evento));
        if ($rol === 'Administrador') {
            $this->assertNull(DB::table('medicos')->where('id_medico', $r['doctors'][0])->value('id_usuario'));
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$evento->id_evento)->assertOk()
                ->assertJsonPath('data.cambios_seguros.antes.estado', $estado)
                ->assertJsonPath('data.cambios_seguros.despues.estado', 'Atendida')
                ->assertJsonPath('data.referencias.id_medico', (string) $r['doctors'][0]);
        }
        $beforeReplay = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->atender($cookies, $id, $this->datos($cookies, $id))->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.confirmar_llegada', false)
            ->assertJsonPath('data.acciones.registrar_inasistencia', false)->assertJsonPath('data.acciones.marcar_atendida', false);
        $this->assertSame($beforeReplay, $this->snapshot());
    }

    public static function instantes(): array
    {
        return [['2026-11-01T03:59:59.999999Z', 409], ['2026-11-01T11:59:59.999999Z', 409],
            ['2026-11-01T12:00:00.000000Z', 200], ['2026-11-01T12:00:00.000001Z', 200],
            ['2026-11-01T12:14:59.999999Z', 200], ['2026-11-01T12:15:00.000000Z', 200],
            ['2026-11-02T04:00:00.000000Z', 200]];
    }

    #[DataProvider('instantes')]
    public function test_attention_requires_start_but_not_end_or_same_day(string $ahora, int $status): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        CarbonImmutable::setTestNow($ahora);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.marcar_atendida', $status === 200);
        $response = $this->atender($cookies, $id, $datos)->assertStatus($status);
        if ($status === 409) {
            $response->assertJsonPath('error.code', 'CITA_ATENCION_ANTICIPADA');
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function finales(): array
    {
        return [['Atendida'], ['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('finales')]
    public function test_no_ordinary_reopening_of_final_states(string $estado): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['estado' => $estado]);
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.marcar_atendida', false);
        $this->atender($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_TRANSICION_NO_PERMITIDA');
        $this->assertSame($before, $this->snapshot());
    }

    public static function consultas(): array
    {
        return [['Administrador', null], ['Administrador', '2026-11-01T12:00:00Z'],
            ['Médico', null], ['Médico', '2026-11-01T12:00:00Z']];
    }

    #[DataProvider('consultas')]
    public function test_consultation_is_neither_required_nor_altered_nor_automatic_evidence(string $rol, ?string $iniciada): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto($rol);
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $id, 'codigo_consulta' => 'CONS-FICTICIA',
            'iniciada_en' => $iniciada], 'id_consulta');
        DB::table('versiones_consulta')->insert(['id_consulta' => $consulta, 'numero_version' => 1,
            'id_autor' => $actor->getKey(), 'registrada_en' => now(), 'tipo_consulta' => 'Control',
            'motivo_consulta' => 'CONTENIDO-CLINICO-PRIVADO', 'diagnostico' => 'DIAGNOSTICO-PRIVADO']);
        $clinica = DB::table('consultas_medicas')->get()->toJson();
        $versiones = DB::table('versiones_consulta')->get()->toJson();
        $datos = $this->datos($cookies, $id);
        $this->assertSame('Programada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $this->assertSame(0, DB::table('cambios_cita')->count());
        $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.marcar_atendida', true)->assertDontSee('CLINICO-PRIVADO');
        $incompleto = $datos;
        unset($incompleto['atencion_realizada']);
        $this->atender($cookies, $id, $incompleto)->assertUnprocessable();
        $this->assertSame('Programada', DB::table('citas')->where('id_cita', $id)->value('estado'));
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldNotReceive('tieneConsulta');
        $this->app->instance(CitaRepository::class, $repo);
        $response = $this->atender($cookies, $id, $datos)->assertOk()->assertJsonPath('data.estado', 'Atendida');
        $this->assertSame($clinica, DB::table('consultas_medicas')->get()->toJson());
        $this->assertSame($versiones, DB::table('versiones_consulta')->get()->toJson());
        $this->assertStringNotContainsString('DIAGNOSTICO-PRIVADO', $response->getContent());
        $this->assertStringNotContainsString('CLINICO-PRIVADO', DB::table('bitacora')->get()->toJson());
    }

    public static function invalidos(): array
    {
        return [[['atencion_realizada' => false]], [['atencion_realizada' => null]], [['atencion_realizada' => 0]],
            [['atencion_realizada' => 1]], [['atencion_realizada' => 'true']], [['atencion_realizada' => '1']],
            [['atencion_realizada' => []]], [['atencion_realizada' => ['valor' => true]]],
            [['presencia_verificada' => true]], [['ausencia_verificada' => true]], [['estado' => 'Atendida']],
            [['id_actor' => '1']], [['id_medico' => '1']], [['id_paciente' => '1']], [['rol' => 'Administrador']],
            [['motivo' => 'PRIVADO']], [['diagnostico' => 'PRIVADO']], [['iniciada_en' => '2026-11-01T12:00:00Z']],
            [['id_objetivo' => '1']], [['version_programacion_original' => 0]], [['version_programacion_original' => 2147483648]],
            [['version_programacion_original' => '04']], [['actualizada_en_original' => '2026-11-01T12:05:00Z']],
            [['actualizada_en_original' => '2026-02-30T12:05:00.000000Z']]];
    }

    #[DataProvider('invalidos')]
    public function test_strict_attention_declaration_and_closed_payload(array $extra): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $before = $this->snapshot();
        $this->atender($cookies, $id, [...$datos, ...$extra])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_fields_query_ids_and_unknown_cita(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        foreach (array_keys($datos) as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->atender($cookies, $id, $incompleto)->assertUnprocessable();
        }
        foreach (['0', '01', '-1', '1e2', '9223372036854775808'] as $invalido) {
            $this->atender($cookies, $invalido, $datos)->assertUnprocessable();
        }
        $this->atender($cookies, '9223372036854775807', $datos)->assertNotFound();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion?atencion_realizada=1', $datos)->assertUnprocessable();
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public static function originalesObsoletos(): array
    {
        return [['version_programacion_original', 3], ['actualizada_en_original', '2026-11-01T12:05:00.000001Z']];
    }

    #[DataProvider('originalesObsoletos')]
    public function test_stale_originals_do_not_invalidate_reminders(string $campo, mixed $valor): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $before = $this->snapshot();
        $this->atender($cookies, $id, [...$datos, $campo => $valor])->assertConflict()->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_bigint_maximum_programming_version_and_monotonic_timestamp(): void
    {
        [$cookies, $r, $id] = $this->contexto(campos: ['id_cita' => '9223372036854775807',
            'version_programacion' => 2147483647, 'actualizada_en' => '2026-11-01T12:05:00.123456Z']);
        $this->atender($cookies, $id, $this->datos($cookies, $id))->assertOk()->assertJsonPath('data.id_cita', '9223372036854775807')
            ->assertJsonPath('data.version_programacion', 2147483647)->assertJsonPath('data.actualizada_en', '2026-11-01T12:05:00.123457Z');
    }

    public function test_inactive_professional_with_active_account_can_complete_assigned_attention(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => null]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['activo' => false]);
        $this->atender($cookies, $id, $datos)->assertOk()->assertJsonPath('data.id_consultorio', (string) $r['rooms'][0]);
    }

    public static function otrosRoles(): array
    {
        return [['Paciente'], ['Recepcionista']];
    }

    #[DataProvider('otrosRoles')]
    public function test_forbidden_roles_in_route_and_service(string $rol): void
    {
        [$cookies, $r, $id] = $this->contexto($rol);
        if ($rol === 'Recepcionista') {
            $this->consultar($cookies, $id)->assertOk()->assertJsonPath('data.acciones.marcar_atendida', false)
                ->assertJsonPath('data.impedimentos.marcar_atendida', ['ACCESO_DENEGADO']);
        } else {
            $this->consultar($cookies, $id)->assertForbidden();
        }
        $this->atender($cookies, $id, [])->assertForbidden();
        $this->expectException(AppError::class);
        $this->app->make(AsistenciaService::class)->marcarAtendida($id, [], []);
    }

    public function test_service_also_requires_boolean_true(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Comprueba que la atención');
        $this->app->make(AsistenciaService::class)->marcarAtendida($id, [...$datos, 'atencion_realizada' => 1], []);
    }

    public function test_doctor_cannot_mark_foreign_cita_through_route_or_service(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1],
            'id_consultorio' => $r['rooms'][1], 'inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']), 'id_cita');
        $before = $this->snapshot();
        $response = $this->atender($cookies, $otro, $datos)->assertNotFound()->assertJsonPath('error.code', 'RECURSO_NO_ENCONTRADO');
        $missing = $this->atender($cookies, '9223372036854775807', $datos)->assertNotFound();
        $this->assertSame($response->json(), $missing->json());
        $this->assertSame($before, $this->snapshot());
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No se encontró el recurso');
        $this->app->make(AsistenciaService::class)->marcarAtendida($otro, $datos, []);
    }

    public function test_doctor_with_incompatible_dual_identity_is_denied(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actor->getKey()]);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_doctor_link_change_after_planning_is_rejected_before_any_write(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($r, $actor): void {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => null]);
            DB::table('medicos')->where('id_medico', $r['doctors'][1])->update(['id_usuario' => $actor->getKey()]);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_session_csrf_origin_and_revocation_are_required(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion', $datos, csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/atencion', $datos,
            ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->consultar($cookies, $id)->assertUnauthorized();
        $this->atender($cookies, $id, $datos)->assertUnauthorized();
        $anonimo = $this->csrfAuth();
        $this->atender($anonimo, $id, $datos)->assertUnauthorized();
    }

    public function test_real_restricted_medical_session_cannot_mark_attention(): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $target = $this->usuarioAuth('Médico');
        $temporal = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $temporal)->assertOk();
        $this->atender($restricted, $id, [])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public static function fallos(): array
    {
        return [['guardar'], ['agregarCambio'], ['invalidarPorCita'], ['citaAtendida'], ['ultimoCambio']];
    }

    #[DataProvider('fallos')]
    public function test_failure_after_real_writes_or_final_read_rolls_back_everything(string $metodo): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $clase = match ($metodo) {
            'invalidarPorCita' => RecordatorioRepository::class,
            'citaAtendida' => BitacoraRepository::class,
            default => CitaRepository::class,
        };
        $real = new $clase;
        $mock = Mockery::mock($clase)->makePartial();
        $mock->shouldReceive($metodo)->once()->andReturnUsing(function (...$args) use ($real, $metodo): never {
            $real->$metodo(...$args);
            throw new RuntimeException('FALLO-ATENCION-PRIVADO');
        });
        $this->app->instance($clase, $mock);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertStatus(500)->assertDontSee('FALLO-ATENCION-PRIVADO');
        $this->assertSame($before, $this->snapshot());
    }

    public static function despuesDePlanificacion(): array
    {
        return [['cuenta', 401], ['rol', 401]];
    }

    #[DataProvider('despuesDePlanificacion')]
    public function test_account_and_role_are_revalidated_after_wait(string $cambio, int $status): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $otroRol = $this->usuarioAuth('Recepcionista')->id_rol;
        $plan = Mockery::mock(PlanificacionRepository::class);
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($cambio, $actor, $otroRol): void {
            DB::table('usuarios')->where('id_usuario', $actor->getKey())->update($cambio === 'cuenta'
                ? ['estado_cuenta' => 'Desactivada'] : ['id_rol' => $otroRol]);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
    }

    public static function cambiosBajoLock(): array
    {
        return [['version', 'CITA_EDICION_OBSOLETA'], ['timestamp', 'CITA_EDICION_OBSOLETA'],
            ['estado', 'CITA_TRANSICION_NO_PERMITIDA']];
    }

    #[DataProvider('cambiosBajoLock')]
    public function test_cita_is_reread_under_lock(string $cambio, string $codigo): void
    {
        [$cookies, $r, $id] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porIdParaAsistencia')->with($id, null, true)->once()->andReturnUsing(function () use ($id, $cambio) {
            DB::table('citas')->where('id_cita', $id)->update(match ($cambio) {
                'version' => ['version_programacion' => 5],
                'timestamp' => ['actualizada_en' => '2026-11-01T12:05:00.123456Z'],
                'estado' => ['estado' => 'Cancelada'],
            });

            return (new CitaRepository)->porIdParaAsistencia($id, null, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_medical_scope_is_applied_again_when_acquiring_cita_lock(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        $datos = $this->datos($cookies, $id);
        $medico = (string) $r['doctors'][0];
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porIdParaAsistencia')->with($id, $medico, true)->once()->andReturnUsing(function () use ($id, $medico, $r) {
            DB::table('citas')->where('id_cita', $id)->update(['id_medico' => $r['doctors'][1]]);

            return (new CitaRepository)->porIdParaAsistencia($id, $medico, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clock_is_fresh_after_final_session_revalidation(): void
    {
        [$cookies, $r, $id, $actor] = $this->contexto();
        $datos = $this->datos($cookies, $id);
        $repo = Mockery::mock(UsuarioRepository::class)->makePartial();
        $reads = 0;
        $repo->shouldReceive('porId')->with($actor->getKey(), true)->andReturnUsing(function ($userId) use (&$reads) {
            if (++$reads === 3) {
                CarbonImmutable::setTestNow('2026-11-01T11:59:59.999999Z');
            }

            return (new UsuarioRepository)->porId($userId, true);
        });
        $this->app->instance(UsuarioRepository::class, $repo);
        $before = $this->snapshot();
        $this->atender($cookies, $id, $datos)->assertConflict()->assertJsonPath('error.code', 'CITA_ATENCION_ANTICIPADA');
        $this->assertSame(3, $reads);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_get_head_only_offer_eligible_action_without_attesting_attention(): void
    {
        [$cookies, $r, $id] = $this->contexto('Médico');
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 4, 'generado_en' => now()]);
        $before = $this->snapshot();
        $this->consultar($cookies, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.acciones.marcar_atendida', true)->assertJsonPath('data.acciones.confirmar_llegada', false)
            ->assertJsonPath('data.acciones.registrar_inasistencia', false)->assertJsonPath('data.ultimo_cambio', null)
            ->assertJsonMissingPath('data.atencion_realizada')->assertDontSee('MOTIVO-CLINICO-PRIVADO');
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id.'/asistencia')->assertOk()->assertContent('');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_audit_resource_filters_injected_fields_and_invalid_states(): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['id_evento' => 1, 'tipo_evento' => 'CITA_ATENDIDA', 'version_formato' => 1,
            'ocurrido_en' => now(), 'registrado_en' => now(), 'referencias' => ['id_cita' => '1', 'id_medico' => '-1', 'motivo' => 'SECRETO'],
            'cambios_seguros' => ['antes' => ['estado' => 'Inasistencia', 'version_programacion' => 4],
                'despues' => ['estado' => 'Cancelada', 'version_programacion' => 2147483648, 'atencion_realizada' => true, 'diagnostico' => 'SECRETO']],
            'metadatos_seguros' => ['motivo' => 'SECRETO']]);
        $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
        $this->assertSame('1', $salida['referencias']->id_cita);
        $this->assertObjectNotHasProperty('id_medico', $salida['referencias']);
        $this->assertObjectNotHasProperty('estado', $salida['cambios_seguros']->antes);
        $this->assertObjectNotHasProperty('despues', $salida['cambios_seguros']);
        $this->assertStringNotContainsString('SECRETO', json_encode($salida));
        $this->assertStringNotContainsString('atencion_realizada', json_encode($salida));
    }
}
