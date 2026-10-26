<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\citas\DisponibilidadService;
use Clinica\business\services\citas\ReservaService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\ConfiguracionClinica;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Especialidad;
use Clinica\data\models\HorarioAtencion;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\citas\DisponibilidadRepository;
use Clinica\data\repositories\horarios\ConfiguracionCitasRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// HTTP, Services, cálculo de turnos, HMAC y cifrado reales; sesión y persistencia simuladas.
// No ejecuta migraciones ni acredita carreras/rollback/SQL de PostgreSQL.
class ReservaPermisosAdministrativosTest extends TestCase
{
    private Usuario $actor;

    private Paciente $paciente;

    private Medico $medico;

    private Especialidad $especialidad;

    private Consultorio $sala;

    private ConfiguracionClinica $configuracion;

    private bool $habilitado = true;

    private array $citas = [];

    private array $eventos = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8000',
            'cors.allowed_origins' => ['http://localhost:5173'], 'sanctum.stateful' => ['localhost:5173']]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('connection')->never();
        $fecha = '2026-10-24T12:00:00.000000Z';
        $this->paciente = (new Paciente)->forceFill(['id_paciente' => 25, 'id_usuario' => null, 'codigo_paciente' => 'PAC-FICTICIO',
            'nombres' => 'Paciente', 'apellidos' => 'Ficticio', 'activo' => true]);
        $this->medico = (new Medico)->forceFill(['id_medico' => 31, 'codigo_medico' => 'MED-FICTICIO', 'nombres' => 'Profesional',
            'apellidos' => 'Ficticio', 'activo' => true, 'id_consultorio' => 41, 'actualizado_en' => $fecha]);
        $this->especialidad = (new Especialidad)->forceFill(['id_especialidad' => 51, 'nombre' => 'Especialidad ficticia', 'activa' => true, 'actualizado_en' => $fecha]);
        $this->sala = (new Consultorio)->forceFill(['id_consultorio' => 41, 'codigo_consultorio' => 'SALA-FICTICIA', 'nombre' => 'Consultorio ficticio', 'activo' => true, 'actualizado_en' => $fecha]);
        $this->configuracion = (new ConfiguracionClinica)->forceFill(['nombre_sede' => 'Sede ficticia', 'direccion_sede' => 'Dirección ficticia',
            'duracion_turno_minutos' => 20, 'actualizado_en' => $fecha]);
        $continuidad = Mockery::mock(ContinuidadAdministrativaService::class);
        $continuidad->shouldReceive('coordinar');
        $this->app->instance(ContinuidadAdministrativaService::class, $continuidad);
        $planificacion = Mockery::mock(PlanificacionRepository::class);
        $planificacion->shouldReceive('coordinar');
        $this->app->instance(PlanificacionRepository::class, $planificacion);
        $config = Mockery::mock(ConfiguracionCitasRepository::class);
        $config->shouldReceive('bloqueada')->andReturnUsing(fn () => $this->configuracion);
        $this->app->instance(ConfiguracionCitasRepository::class, $config);
        $datos = Mockery::mock(DisponibilidadRepository::class);
        $datos->shouldReceive('especialidades')->andReturn(new LengthAwarePaginator([['id_especialidad' => '51', 'nombre' => 'Especialidad ficticia']], 1, 20));
        $datos->shouldReceive('medicos')->andReturn(new LengthAwarePaginator([['id_medico' => '31', 'nombres' => 'Profesional', 'apellidos' => 'Ficticio']], 1, 20));
        $datos->shouldReceive('medico')->with('31')->andReturnUsing(fn () => $this->medico);
        $datos->shouldReceive('especialidad')->with('51')->andReturnUsing(fn () => $this->especialidad);
        $datos->shouldReceive('habilitado')->with('31', '51')->andReturnUsing(fn () => $this->habilitado);
        $datos->shouldReceive('consultorio')->with('41')->andReturnUsing(fn () => $this->sala);
        $datos->shouldReceive('horarios')->with('31', '41')->andReturn(new Collection([(new HorarioAtencion)->forceFill([
            'id_horario' => 61, 'id_medico' => 31, 'dia_semana' => 7, 'hora_inicio' => '08:00:01', 'hora_fin' => '09:00:01'])]));
        $datos->shouldReceive('paciente')->andReturnUsing(function ($actor, $id) {
            $this->assertSame((string) $this->actor->getKey(), $actor);
            $this->assertSame($this->actor->rol->nombre === 'Paciente' ? null : '25', $id);

            return $this->paciente;
        });
        $datos->shouldReceive('ocupacion')->andReturnUsing(fn () => new Collection(array_values($this->citas)));
        $this->app->instance(DisponibilidadRepository::class, $datos);
        $citas = Mockery::mock(CitaRepository::class);
        $citas->shouldReceive('porPropuesta')->andReturnUsing(fn ($uuid) => $this->citas[$uuid] ?? null);
        $citas->shouldReceive('pacienteSolapado')->andReturnFalse()->byDefault();
        $citas->shouldReceive('crear')->andReturnUsing(function ($datos) {
            $this->assertSame($this->actor->getKey(), $datos['creada_por']);
            $this->assertSame('31', $datos['id_medico']);
            $cita = (new Cita)->forceFill([...$datos, 'id_cita' => 81, 'creada_en' => CarbonImmutable::now('UTC'), 'actualizada_en' => CarbonImmutable::now('UTC')]);
            $this->citas[$datos['id_propuesta_reserva']] = $cita;

            return $cita;
        });
        $this->app->instance(CitaRepository::class, $citas);
        $bitacora = Mockery::mock(BitacoraRepository::class);
        $bitacora->shouldReceive('citaProgramada')->andReturnUsing(function ($cita, $actor, $contexto) {
            $this->assertSame($this->actor, $actor);
            $this->assertArrayHasKey('correlacion', $contexto);
            $this->eventos[] = ['actor' => $actor->getKey(), 'rol' => $actor->rol->nombre, 'paciente' => $cita->id_paciente, 'medico' => $cita->id_medico];
        });
        $this->app->instance(BitacoraRepository::class, $bitacora);
    }

    private function autenticar(string $rol = 'Administrador', int $id = 71): void
    {
        $this->actor = (new Usuario)->forceFill(['id_usuario' => $id, 'estado_cuenta' => 'Activa', 'password_hash' => 'hash-ficticio']);
        $this->actor->setRelation('rol', (new Rol)->forceFill(['nombre' => $rol]));
        $this->actingAs($this->actor, 'web');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->andReturnUsing(fn () => $this->actor);
        $auth->shouldReceive('exigirSesionNormal')->andReturnUsing(fn () => $this->actor);
        $this->app->instance(AutenticacionService::class, $auth);
    }

    private function seleccion(): array
    {
        return ['id_medico' => '31', 'id_especialidad' => '51', 'fecha' => '2026-11-01',
            ...($this->actor->rol->nombre === 'Paciente' ? [] : ['id_paciente' => '25'])];
    }

    private function propuesta(): array
    {
        $seleccion = $this->seleccion();
        $turno = $this->getJson('/api/reservas/disponibilidad?'.http_build_query($seleccion))->assertOk()->json('data.turnos.0');
        $datos = [...$seleccion, 'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno']];

        return $this->postJson('/api/reservas/propuesta', $datos)->assertOk()->json('data');
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Paciente']];
    }

    #[DataProvider('operadores')]
    public function test_six_shared_endpoints_preserve_identity_proposal_result_and_replay(string $rol): void
    {
        $this->autenticar($rol);
        $this->getJson('/api/reservas/especialidades')->assertOk()->assertJsonPath('data.0.id_especialidad', '51');
        $this->getJson('/api/reservas/medicos?id_especialidad=51')->assertOk()->assertJsonPath('data.0.id_medico', '31');
        $propuesta = $this->propuesta();
        $datos = ['propuesta' => $propuesta['propuesta']];
        $prueba = json_decode(Crypt::decryptString($datos['propuesta']), true);
        $this->assertSame($rol, $prueba['rol']);
        $this->assertSame('71', $prueba['actor']);
        $this->assertSame('25', $prueba['id_paciente']);
        $this->assertSame([], $this->citas);
        $this->postJson('/api/reservas/resultado', $datos)->assertOk()->assertJsonPath('data.registrada', false);
        $this->postJson('/api/citas', $datos)->assertCreated()->assertJsonPath('data.estado', 'Programada')
            ->assertJsonPath('data.id_paciente', '25')->assertJsonPath('data.id_medico', '31')->assertJsonPath('meta.repetida', false)
            ->assertJsonMissingPath('data.creada_por')->assertJsonMissingPath('data.id_propuesta_reserva');
        $this->postJson('/api/citas', $datos)->assertOk()->assertJsonPath('meta.repetida', true);
        $this->postJson('/api/reservas/resultado', $datos)->assertOk()->assertJsonPath('data.registrada', true)->assertJsonPath('data.cita.id_cita', '81');
        $this->assertCount(1, $this->citas);
        $this->assertSame([['actor' => 71, 'rol' => $rol, 'paciente' => 25, 'medico' => '31']], $this->eventos);
    }

    public static function rutas(): array
    {
        return [['GET', '/api/reservas/especialidades'], ['GET', '/api/reservas/medicos'], ['GET', '/api/reservas/disponibilidad'],
            ['POST', '/api/reservas/propuesta'], ['POST', '/api/citas'], ['POST', '/api/reservas/resultado']];
    }

    #[DataProvider('rutas')]
    public function test_doctor_cannot_use_reservation_endpoints(string $metodo, string $ruta): void
    {
        $this->autenticar('Médico');
        $this->json($metodo, $ruta)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
    }

    #[DataProvider('rutas')]
    public function test_anonymous_requests_are_not_allowed(string $metodo, string $ruta): void
    {
        $this->json($metodo, $ruta)->assertUnauthorized();
    }

    public static function seleccionAdministrativa(): array
    {
        return [['Administrador', false, 422], ['Recepcionista', false, 422], ['Paciente', true, 422]];
    }

    #[DataProvider('seleccionAdministrativa')]
    public function test_patient_selection_is_required_only_in_administrative_context(string $rol, bool $incluir, int $status): void
    {
        $this->autenticar($rol);
        $datos = ['id_medico' => '31', 'id_especialidad' => '51', 'fecha' => '2026-11-01', ...($incluir ? ['id_paciente' => '25'] : [])];
        $this->getJson('/api/reservas/disponibilidad?'.http_build_query($datos))->assertStatus($status)->assertJsonValidationErrors('id_paciente', 'error.details');
        $this->postJson('/api/reservas/propuesta', [...$datos, 'hora_inicio' => '08:00:01', 'revision_turno' => str_repeat('a', 64)])
            ->assertStatus($status)->assertJsonValidationErrors('id_paciente', 'error.details');
    }

    public static function cambios(): array
    {
        return [['medico', 'CITA_TURNO_NO_DISPONIBLE'], ['especialidad', 'CITA_TURNO_NO_DISPONIBLE'],
            ['sala', 'CITA_TURNO_NO_DISPONIBLE'], ['habilitacion', 'CITA_TURNO_NO_DISPONIBLE'],
            ['paciente', 'CITA_PACIENTE_NO_DISPONIBLE'], ['duracion', 'CITA_PROPUESTA_OBSOLETA'],
            ['nombre_paciente', 'CITA_PROPUESTA_OBSOLETA'], ['hora_pasada', 'CITA_PROPUESTA_OBSOLETA']];
    }

    #[DataProvider('cambios')]
    public function test_administrator_revalidates_changed_conditions_before_confirming(string $cambio, string $codigo): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        match ($cambio) {
            'medico' => $this->medico->activo = false, 'especialidad' => $this->especialidad->activa = false,
            'sala' => $this->sala->activo = false, 'habilitacion' => $this->habilitado = false,
            'paciente' => $this->paciente->activo = false, 'duracion' => $this->configuracion->duracion_turno_minutos = 15,
            'nombre_paciente' => $this->paciente->nombres = 'Identidad corregida',
            'hora_pasada' => CarbonImmutable::setTestNow('2026-11-01T12:00:01Z'),
        };
        $this->postJson('/api/citas', ['propuesta' => $propuesta['propuesta']])->assertConflict()->assertJsonPath('error.code', $codigo);
        $this->assertSame([], $this->citas);
        $this->assertSame([], $this->eventos);
    }

    public static function transferencias(): array
    {
        return [['Administrador', 72], ['Recepcionista', 71], ['Paciente', 71]];
    }

    #[DataProvider('transferencias')]
    public function test_proposal_cannot_be_transferred_to_another_actor_or_role(string $rol, int $id): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        $this->autenticar($rol, $id);
        foreach (['/api/citas', '/api/reservas/resultado'] as $ruta) {
            $this->postJson($ruta, ['propuesta' => $propuesta['propuesta']])->assertForbidden();
        }
        $this->assertSame([], $this->citas);
    }

    public function test_distinct_proposals_for_the_same_slot_do_not_both_confirm(): void
    {
        $this->autenticar();
        $una = $this->propuesta();
        $otra = $this->propuesta();
        $this->postJson('/api/citas', ['propuesta' => $una['propuesta']])->assertCreated();
        $this->postJson('/api/citas', ['propuesta' => $otra['propuesta']])->assertConflict();
        $this->assertCount(1, $this->citas);
        $this->assertCount(1, $this->eventos);
    }

    public function test_body_cannot_replace_patient_or_professional_from_reviewed_proposal(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        foreach (['id_paciente', 'id_medico', 'creada_por', 'rol', 'estado'] as $campo) {
            $this->postJson('/api/citas', ['propuesta' => $propuesta['propuesta'], $campo => '999'])->assertUnprocessable()
                ->assertJsonValidationErrors($campo, 'error.details');
        }
        $this->assertSame([], $this->citas);
    }

    public function test_replay_returns_current_state_without_reopening_appointment(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        $datos = ['propuesta' => $propuesta['propuesta']];
        $this->postJson('/api/citas', $datos)->assertCreated();
        $cita = reset($this->citas);
        $cita->estado = 'Cancelada';
        $this->paciente->activo = false;
        $this->postJson('/api/citas', $datos)->assertOk()->assertJsonPath('data.estado', 'Cancelada')->assertJsonPath('meta.repetida', true);
        $this->postJson('/api/reservas/resultado', $datos)->assertOk()->assertJsonPath('data.cita.estado', 'Cancelada');
        $this->assertCount(1, $this->eventos);
    }

    public function test_service_guards_remain_effective_without_http_middleware(): void
    {
        $this->autenticar('Médico');
        foreach (['especialidades', 'medicos', 'turnos', 'proponer', 'confirmar', 'resultado'] as $metodo) {
            try {
                $servicio = $this->app->make(in_array($metodo, ['especialidades', 'medicos', 'turnos'], true) ? DisponibilidadService::class : ReservaService::class);
                if ($metodo === 'confirmar') {
                    $servicio->confirmar(['propuesta' => 'invalido'], []);
                } else {
                    $servicio->$metodo($metodo === 'resultado' ? 'invalido' : ['propuesta' => 'invalido']);
                }
                $this->fail('Se permitió un rol sin autorización.');
            } catch (AppError $error) {
                $this->assertSame('ACCESO_DENEGADO', $error->codigo);
            }
        }
    }

    public function test_tampered_token_is_not_accepted_for_confirmation_or_result(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        foreach (['/api/citas', '/api/reservas/resultado'] as $ruta) {
            $this->postJson($ruta, ['propuesta' => 'x'.substr($propuesta['propuesta'], 1)])->assertUnprocessable()->assertJsonValidationErrors('propuesta', 'error.details');
        }
        $this->assertSame([], $this->citas);
    }

    public function test_changed_reason_cannot_replace_the_reviewed_reason(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        $this->postJson('/api/citas', ['propuesta' => $propuesta['propuesta'], 'motivo_breve' => 'Otro motivo ficticio'])
            ->assertUnprocessable()->assertJsonValidationErrors('propuesta', 'error.details');
        $this->assertSame([], $this->citas);
    }

    public function test_replay_checks_actual_persisted_creator_and_patient(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        $datos = ['propuesta' => $propuesta['propuesta']];
        $this->postJson('/api/citas', $datos)->assertCreated();
        $cita = reset($this->citas);
        foreach (['creada_por', 'id_paciente'] as $campo) {
            $anterior = $cita->$campo;
            $cita->$campo = 999;
            $this->postJson('/api/citas', $datos)->assertForbidden();
            $this->postJson('/api/reservas/resultado', $datos)->assertForbidden();
            $cita->$campo = $anterior;
        }
        $this->assertCount(1, $this->eventos);
    }

    public function test_role_or_session_rejection_during_availability_revalidation_prevents_reservation(): void
    {
        $this->autenticar();
        $propuesta = $this->propuesta();
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->andReturn($this->actor);
        $auth->shouldReceive('exigirSesionNormal')->times(3)->andReturn($this->actor);
        $auth->shouldReceive('exigirSesionNormal')->once()->andThrow(new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.'));
        $this->app->instance(AutenticacionService::class, $auth);
        $this->postJson('/api/citas', ['propuesta' => $propuesta['propuesta']])->assertUnauthorized();
        $this->assertSame([], $this->citas);
    }
}
