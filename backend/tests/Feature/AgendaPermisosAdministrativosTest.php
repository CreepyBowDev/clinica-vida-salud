<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\HistorialCitasService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Kernel, Requests, Controllers, Services y Resources reales; sesión y persistencia simuladas.
// No migra ni acredita SQL, cookies persistidas, rollback o concurrencia PostgreSQL.
class AgendaPermisosAdministrativosTest extends TestCase
{
    private Usuario $actor;

    private AgendaRepository $datos;

    private array $fila;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());
        config(['session.driver' => 'array', 'sanctum.stateful' => ['localhost:5173']]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('connection')->never();
        $this->fila = ['id_cita' => '9223372036854775807', 'codigo_cita' => 'CITA-FICTICIA',
            'inicio_at' => '2026-11-01T12:00:00Z', 'fin_at' => '2026-11-01T12:15:00Z', 'duracion_minutos' => 15,
            'estado' => 'Programada', 'modalidad' => 'Presencial', 'sede_atencion' => 'Sede ficticia',
            'consultorio_atencion' => 'Consultorio histórico', 'creada_en' => '2026-10-24T12:00:00Z',
            'id_medico' => '31', 'codigo_medico' => 'MED-FICTICIO', 'medico_nombres' => 'Profesional', 'medico_apellidos' => 'Ficticio',
            'id_especialidad' => '51', 'especialidad_nombre' => 'Especialidad ficticia',
            'id_paciente' => '25', 'codigo_paciente' => 'PAC-FICTICIO', 'paciente_nombres' => 'Paciente', 'paciente_apellidos' => 'Ficticio',
            'motivo_breve' => 'Motivo ficticio', 'paciente_documento_identidad' => 'CI-FICTICIO', 'paciente_telefono' => 'TEL-FICTICIO',
            'paciente_fecha_nacimiento' => '1980-06-07', 'diagnostico' => 'NO-EXPONER', 'id_propuesta_reserva' => 'NO-EXPONER'];
        $this->datos = Mockery::mock(AgendaRepository::class);
        $this->app->instance(AgendaRepository::class, $this->datos);
    }

    private function autenticar(string $rol): void
    {
        $this->actor = (new Usuario)->forceFill(['id_usuario' => 71, 'estado_cuenta' => 'Activa']);
        $this->actor->setRelation('rol', (new Rol)->forceFill(['nombre' => $rol]));
        $this->actor->setRelation('paciente', $rol === 'Paciente' ? (new Paciente)->forceFill(['id_paciente' => 25, 'id_usuario' => 71, 'activo' => false]) : null);
        $this->actor->setRelation('medico', $rol === 'Médico' ? (new Medico)->forceFill(['id_medico' => 31, 'id_usuario' => 71, 'activo' => false]) : null);
        $this->actingAs($this->actor, 'web');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->andReturnUsing(fn () => $this->actor);
        $auth->shouldReceive('exigirSesionNormal')->andReturnUsing(fn () => $this->actor);
        $this->app->instance(AutenticacionService::class, $auth);
    }

    private function pagina(array $filtros): LengthAwarePaginator
    {
        return new LengthAwarePaginator([$this->fila], 3, $filtros['per_page'] ?? 20, $filtros['page'] ?? 1);
    }

    public static function administrativos(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('administrativos')]
    public function test_shared_history_preserves_filters_pagination_and_minimal_list(string $rol): void
    {
        $this->autenticar($rol);
        $this->datos->shouldReceive('paciente')->once()->with('25')->andReturn(['id_paciente' => 25, 'codigo_paciente' => 'PAC-FICTICIO', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio']);
        $this->datos->shouldReceive('historial')->once()->with('25', Mockery::type(CarbonImmutable::class),
            ['periodo' => 'anteriores', 'estado' => 'Atendida', 'per_page' => '1', 'page' => '2'])
            ->andReturnUsing(fn ($id, $ahora, $filtros) => $this->pagina($filtros));
        $this->getJson('/api/pacientes/25/citas?periodo=anteriores&estado=Atendida&per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.paciente.id_paciente', '25')->assertJsonPath('meta.filtros.periodo', 'anteriores')
            ->assertJsonMissingPath('data.0.motivo_breve')->assertJsonMissingPath('data.0.paciente.documento_identidad')
            ->assertJsonMissingPath('data.0.paciente.telefono')->assertDontSee('NO-EXPONER');
    }

    public static function roles(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_detail_uses_role_projection_and_resource_allowlist(string $rol): void
    {
        $this->autenticar($rol);
        $ambito = match ($rol) {
            'Médico' => ['citas.id_medico' => '31'], 'Paciente' => ['citas.id_paciente' => '25'], default => []
        };
        $campos = match ($rol) {
            'Médico' => ['citas.motivo_breve', 'p.fecha_nacimiento as paciente_fecha_nacimiento'],
            'Paciente' => ['citas.motivo_breve'],
            default => ['citas.motivo_breve', 'p.documento_identidad as paciente_documento_identidad', 'p.telefono as paciente_telefono'],
        };
        $this->datos->shouldReceive('detalle')->once()->with('9223372036854775807', $ambito, $campos, $rol !== 'Médico')->andReturn($this->fila);
        $respuesta = $this->getJson('/api/citas/9223372036854775807')->assertOk()
            ->assertJsonPath('data.motivo_breve', 'Motivo ficticio')->assertJsonPath('data.id_cita', '9223372036854775807')
            ->assertDontSee('NO-EXPONER');
        $paciente = $respuesta->json('data.paciente');
        $this->assertSame(in_array($rol, ['Administrador', 'Recepcionista'], true), array_key_exists('documento_identidad', $paciente));
        $this->assertSame(in_array($rol, ['Administrador', 'Recepcionista'], true), array_key_exists('telefono', $paciente));
        $this->assertSame($rol === 'Médico', array_key_exists('fecha_nacimiento', $paciente));
    }

    #[DataProvider('roles')]
    public function test_missing_or_foreign_detail_is_not_found(string $rol): void
    {
        $this->autenticar($rol);
        $this->datos->shouldReceive('detalle')->once()->andReturnNull();
        $this->getJson('/api/citas/99')->assertNotFound()->assertDontSee('Motivo ficticio');
    }

    #[DataProvider('roles')]
    public function test_agenda_keeps_administrative_selection_and_own_doctor_scope(string $rol): void
    {
        $this->autenticar($rol);
        if ($rol === 'Paciente') {
            $this->getJson('/api/agenda')->assertForbidden();

            return;
        }
        $this->datos->shouldReceive('medico')->once()->with('31')->andReturn(['id_medico' => 31, 'codigo_medico' => 'MED-FICTICIO', 'nombres' => 'Profesional', 'apellidos' => 'Ficticio']);
        $this->datos->shouldReceive('citas')->once()->with('31', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class), Mockery::type('array'))
            ->andReturnUsing(fn ($id, $inicio, $fin, $filtros) => $this->pagina($filtros));
        $this->getJson('/api/agenda'.($rol === 'Médico' ? '' : '?id_medico=31'))->assertOk()
            ->assertJsonPath('meta.filtros.id_medico', '31')->assertJsonMissingPath('data.0.motivo_breve')->assertDontSee('NO-EXPONER');
    }

    public static function denegados(): array
    {
        return [['Administrador', '/api/mis-citas'], ['Recepcionista', '/api/mis-citas'],
            ['Médico', '/api/pacientes/25/citas'], ['Paciente', '/api/pacientes/25/citas']];
    }

    #[DataProvider('denegados')]
    public function test_history_role_boundaries(string $rol, string $ruta): void
    {
        $this->autenticar($rol);
        $this->getJson($ruta)->assertForbidden();
    }

    public static function manipulaciones(): array
    {
        return [['/api/pacientes/25/citas?id_paciente=99'], ['/api/pacientes/25/citas?id_objetivo=99'],
            ['/api/pacientes/25/citas?per_page=101'], ['/api/pacientes/01/citas'],
            ['/api/citas/1?fields=motivo_breve'], ['/api/citas/1?rol=Médico'], ['/api/agenda'],
            ['/api/pacientes/25/citas?periodo=inventado'], ['/api/pacientes/25/citas?estado=Inventado']];
    }

    #[DataProvider('manipulaciones')]
    public function test_administrative_requests_remain_closed(string $ruta): void
    {
        $this->autenticar('Administrador');
        $this->getJson($ruta)->assertUnprocessable();
    }

    public function test_patient_history_is_derived_from_session_even_for_inactive_record(): void
    {
        $this->autenticar('Paciente');
        $this->datos->shouldReceive('paciente')->with('25')->once()->andReturn(['id_paciente' => 25, 'codigo_paciente' => 'PAC-FICTICIO', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio']);
        $this->datos->shouldReceive('historial')->with('25', Mockery::type(CarbonImmutable::class), ['periodo' => 'proximas'])->once()->andReturn($this->pagina([]));
        $this->getJson('/api/mis-citas')->assertOk()->assertJsonPath('meta.filtros.periodo', 'proximas');
    }

    public function test_service_requires_explicit_administrative_patient_without_http(): void
    {
        $this->autenticar('Administrador');
        $this->expectException(ValidationException::class);
        $this->app->make(HistorialCitasService::class)->consultar([]);
    }

    public function test_service_rejects_patient_selection_in_filters_without_http(): void
    {
        $this->autenticar('Administrador');
        $this->expectException(ValidationException::class);
        $this->app->make(HistorialCitasService::class)->consultar(['id_paciente' => '99'], '25');
    }

    public function test_missing_patient_is_not_found(): void
    {
        $this->autenticar('Administrador');
        $this->datos->shouldReceive('paciente')->once()->with('25')->andReturnNull();
        $this->getJson('/api/pacientes/25/citas')->assertNotFound();
    }

    public function test_session_rejection_is_propagated_before_data_access(): void
    {
        $this->autenticar('Administrador');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->andThrow(new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.'));
        $this->app->instance(AutenticacionService::class, $auth);
        $this->getJson('/api/pacientes/25/citas')->assertUnauthorized();
    }

    public function test_anonymous_history_and_detail_require_session(): void
    {
        foreach (['/api/pacientes/25/citas', '/api/citas/1', '/api/agenda'] as $ruta) {
            $this->getJson($ruta)->assertUnauthorized();
        }
    }

    #[DataProvider('administrativos')]
    public function test_administrative_history_defaults_to_all_and_does_not_require_web_account(string $rol): void
    {
        $this->autenticar($rol);
        $this->datos->shouldReceive('paciente')->with('25')->once()->andReturn(['id_paciente' => 25, 'codigo_paciente' => 'PAC-FICTICIO', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio']);
        $this->datos->shouldReceive('historial')->with('25', Mockery::type(CarbonImmutable::class), ['periodo' => 'todas'])->once()->andReturn($this->pagina([]));
        $this->getJson('/api/pacientes/25/citas')->assertOk()->assertJsonPath('meta.filtros.periodo', 'todas');
    }

    public static function vinculos(): array
    {
        return [['Paciente', 'ausente'], ['Paciente', 'ajeno'], ['Paciente', 'doble'],
            ['Médico', 'ausente'], ['Médico', 'ajeno'], ['Médico', 'doble']];
    }

    #[DataProvider('vinculos')]
    public function test_detail_rejects_missing_foreign_or_ambiguous_professional_and_patient_links(string $rol, string $tipo): void
    {
        $this->autenticar($rol);
        $relacion = $rol === 'Paciente' ? 'paciente' : 'medico';
        if ($tipo === 'ausente') {
            $this->actor->setRelation($relacion, null);
        } elseif ($tipo === 'ajeno') {
            $this->actor->$relacion->id_usuario = 99;
        } else {
            $this->actor->setRelation($rol === 'Paciente' ? 'medico' : 'paciente', $rol === 'Paciente' ? new Medico : new Paciente);
        }
        $this->getJson('/api/citas/1')->assertForbidden();
    }

    public function test_patient_cannot_choose_history_target_directly_in_service(): void
    {
        $this->autenticar('Paciente');
        $this->expectException(AppError::class);
        $this->app->make(HistorialCitasService::class)->consultar([], '99');
    }

    public function test_doctor_cannot_override_own_agenda(): void
    {
        $this->autenticar('Médico');
        $this->getJson('/api/agenda?id_medico=99')->assertUnprocessable();
    }

    public function test_history_and_detail_reject_get_bodies(): void
    {
        $this->autenticar('Administrador');
        foreach (['/api/pacientes/25/citas', '/api/citas/1'] as $ruta) {
            $this->json('GET', $ruta, ['id_paciente' => '99'])->assertUnprocessable();
        }
    }
}
