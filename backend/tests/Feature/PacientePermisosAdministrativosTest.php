<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\pacientes\CodigoActivacionService;
use Clinica\business\services\pacientes\PacienteService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ControlIntentosService;
use Clinica\data\models\CodigoActivacionPaciente;
use Clinica\data\models\Paciente;
use Clinica\data\models\RegistroPacientePendiente;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pacientes\CodigoActivacionRepository;
use Clinica\data\repositories\pacientes\PacienteRepository;
use Clinica\data\repositories\usuarios\RegistroPacienteRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\RegistroPacienteContexto;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Kernel/rutas/Requests/Controllers/Services reales; sesión y persistencia simuladas.
// No invoca migraciones ni acredita rollback/concurrencia reales de PostgreSQL.
class PacientePermisosAdministrativosTest extends TestCase
{
    private PacienteRepository $pacientes;

    private RegistroPacienteRepository $registros;

    private CodigoActivacionRepository $codigos;

    private BitacoraRepository $bitacora;

    private Usuario $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8000',
            'cors.allowed_origins' => ['http://localhost:5173'], 'sanctum.stateful' => ['localhost:5173']]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('connection')->never();
        $this->pacientes = Mockery::mock(PacienteRepository::class);
        $this->registros = Mockery::mock(RegistroPacienteRepository::class);
        $this->codigos = Mockery::mock(CodigoActivacionRepository::class);
        $this->bitacora = Mockery::mock(BitacoraRepository::class);
        foreach ([PacienteRepository::class => $this->pacientes, RegistroPacienteRepository::class => $this->registros,
            CodigoActivacionRepository::class => $this->codigos, BitacoraRepository::class => $this->bitacora] as $class => $mock) {
            $this->app->instance($class, $mock);
        }
        $this->registros->shouldReceive('coordinar')->byDefault();
        $limites = Mockery::mock(ControlIntentosService::class);
        $limites->shouldReceive('evaluarRegistroPaciente')->byDefault();
        $this->app->instance(ControlIntentosService::class, $limites);
    }

    private function autenticar(string $rol): void
    {
        $this->actor = (new Usuario)->forceFill(['id_usuario' => 71, 'estado_cuenta' => 'Activa',
            'password_hash' => 'hash-ficticio', 'correo_acceso' => 'personal@example.test']);
        $this->actor->setRelation('rol', (new Rol)->forceFill(['nombre' => $rol]));
        $this->actingAs($this->actor, 'web');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->andReturn($this->actor);
        $auth->shouldReceive('exigirSesionNormal')->andReturn($this->actor);
        $this->app->instance(AutenticacionService::class, $auth);
    }

    private function ficha(array $extra = []): Paciente
    {
        $paciente = Mockery::mock(Paciente::class)->makePartial();
        $paciente->mergeCasts((new Paciente)->getCasts());
        $paciente->forceFill(array_replace(['id_paciente' => 25, 'id_usuario' => null, 'codigo_paciente' => 'PAC-FICTICIO',
            'documento_identidad' => '001-A', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'activo' => true,
            'actualizado_en' => '2026-10-24T12:00:00.000000Z'], $extra));
        $paciente->setRelation('contactoEmergencia', null)->setRelation('preferencia', null)->syncOriginal();
        $paciente->shouldReceive('load')->andReturnSelf();

        return $paciente;
    }

    private function datos(): array
    {
        return ['documento_identidad' => '001-A', 'nombres' => 'Paciente', 'apellidos' => 'Ficticio',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'];
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_shared_http_crud_uses_the_real_operator_and_existing_projections(string $rol): void
    {
        $this->autenticar($rol);
        $ficha = $this->ficha();
        $this->registros->shouldReceive('documentoRegistrado')->once()->with('001-A')->andReturnFalse();
        $this->registros->shouldReceive('reservasPorDocumento')->once()->with('001-A')->andReturn(collect());
        $this->pacientes->shouldReceive('crear')->once()->withArgs(fn ($datos, $emergencia) => $datos['documento_identidad'] === '001-A'
            && ! isset($datos['id_usuario']) && str_starts_with($datos['codigo_paciente'], 'PAC-') && $emergencia === null)->andReturn($ficha);
        $this->bitacora->shouldReceive('pacienteCreado')->once()->with($ficha, $this->actor, Mockery::type('array'));
        $this->postJson('/api/pacientes', $this->datos())->assertCreated()->assertJsonPath('data.cuenta_vinculada', false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.password');
        $this->pacientes->shouldReceive('listar')->once()->with(['nombres' => 'Ficticio', 'per_page' => 1])
            ->andReturn(new LengthAwarePaginator([$ficha], 1, 1));
        $this->getJson('/api/pacientes?nombres=Ficticio&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.telefono')->assertJsonMissingPath('data.0.antecedentes');
        $this->pacientes->shouldReceive('consultar')->with('25')->andReturn($ficha);
        $this->getJson('/api/pacientes/25')->assertOk()->assertJsonPath('data.id_paciente', '25');
        $this->pacientes->shouldReceive('guardar')->twice()->with($ficha);
        $this->bitacora->shouldReceive('pacienteModificado')->once()->with($ficha, $this->actor, Mockery::type('array'), ['telefono'], null);
        $this->patchJson('/api/pacientes/25', ['actualizado_en_original' => '2026-10-24T12:00:00.000000Z', 'telefono' => '71111111'])
            ->assertOk()->assertJsonPath('data.telefono', '71111111');
        $this->bitacora->shouldReceive('pacienteModificado')->once()->with($ficha, $this->actor, Mockery::type('array'), ['activo'], 'Baja administrativa', true);
        $this->patchJson('/api/pacientes/25/estado', ['actualizado_en_original' => '2026-10-24T12:00:00.000000Z',
            'activo' => false, 'motivo' => 'Baja administrativa'])->assertOk()->assertJsonPath('data.activo', false);
        $this->assertNull($ficha->id_usuario);
    }

    #[DataProvider('operadores')]
    public function test_code_keeps_actual_issuer_expiration_hash_and_pending_invalidation(string $rol): void
    {
        $this->autenticar($rol);
        $ficha = $this->ficha(['activo' => false]);
        $this->codigos->shouldReceive('pacientePorId')->once()->with('25')->andReturn($ficha);
        $pendiente = new RegistroPacientePendiente;
        $this->registros->shouldReceive('porPaciente')->once()->with('25')->andReturn(collect([$pendiente]));
        $this->registros->shouldReceive('invalidar')->once()->with($pendiente, Mockery::type(CarbonImmutable::class));
        $this->codigos->shouldReceive('invalidarAnteriores')->once()->with('25', Mockery::type(CarbonImmutable::class));
        $guardados = null;
        $this->codigos->shouldReceive('crear')->once()->andReturnUsing(function ($datos) use (&$guardados) {
            $guardados = $datos;

            return (new CodigoActivacionPaciente)->forceFill(['id_codigo_activacion' => 9, ...$datos]);
        });
        $this->bitacora->shouldReceive('autenticacion')->once()->with('ACTIVACION_PACIENTE_EMITIDA', 'EXITO',
            Mockery::type('array'), $this->actor, null, null, ['id_paciente' => '25', 'id_codigo_activacion' => '9']);
        $r = $this->postJson('/api/pacientes/25/codigo-activacion', ['identidad_verificada' => true])->assertCreated();
        $secreto = $r->json('data.codigo_activacion');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/D', $secreto);
        $this->assertSame(71, $guardados['id_recepcionista']);
        $this->assertSame(RegistroPacienteContexto::recepcion('25', $secreto), $guardados['codigo_hash']);
        $this->assertSame(86400.0, $guardados['emitido_en']->diffInSeconds($guardados['expira_en']));
        $r->assertJsonMissingPath('data.codigo_hash')->assertJsonMissingPath('data.id_recepcionista');
        $this->assertNull($ficha->id_usuario);
    }

    public static function accesosDenegados(): array
    {
        $casos = [];
        foreach (['Médico', 'Paciente'] as $rol) {
            foreach ([['GET', '/api/pacientes'], ['GET', '/api/pacientes/25'], ['POST', '/api/pacientes'],
                ['PATCH', '/api/pacientes/25'], ['PATCH', '/api/pacientes/25/estado'], ['POST', '/api/pacientes/25/codigo-activacion']] as [$metodo, $ruta]) {
                $casos[] = [$rol, $metodo, $ruta];
            }
        }

        return $casos;
    }

    #[DataProvider('accesosDenegados')]
    public function test_direct_http_requests_by_other_roles_remain_forbidden(string $rol, string $metodo, string $ruta): void
    {
        $this->autenticar($rol);
        $this->json($metodo, $ruta)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
    }

    public static function serviciosDenegados(): array
    {
        $casos = [];
        foreach (['Médico', 'Paciente'] as $rol) {
            foreach (['listar', 'consultar', 'crear', 'actualizar', 'cambiarEstado', 'emitir'] as $metodo) {
                $casos[] = [$rol, $metodo];
            }
        }

        return $casos;
    }

    #[DataProvider('serviciosDenegados')]
    public function test_services_also_deny_roles_without_administrative_permission(string $rol, string $metodo): void
    {
        $this->autenticar($rol);
        $this->expectException(AppError::class);
        $service = $this->app->make($metodo === 'emitir' ? CodigoActivacionService::class : PacienteService::class);
        match ($metodo) {
            'listar' => $service->listar([]), 'consultar' => $service->consultar('25'),
            'crear' => $service->crear([], []), 'actualizar' => $service->actualizar('25', [], []),
            'cambiarEstado' => $service->cambiarEstado('25', [], []), 'emitir' => $service->emitir('25', true, []),
        };
    }

    public static function condicionesCodigo(): array
    {
        return [['sin CI', null, null, true, 409], ['cuenta vinculada', '001-A', 42, true, 409],
            ['identidad no comprobada', '001-A', null, false, 422]];
    }

    #[DataProvider('condicionesCodigo')]
    public function test_administrator_cannot_bypass_code_conditions(string $caso, ?string $ci, ?int $cuenta, bool $identidad, int $status): void
    {
        $this->autenticar('Administrador');
        if ($identidad) {
            $this->codigos->shouldReceive('pacientePorId')->once()->with('25')->andReturn($this->ficha(['documento_identidad' => $ci, 'id_usuario' => $cuenta]));
        }
        $this->postJson('/api/pacientes/25/codigo-activacion', ['identidad_verificada' => $identidad])->assertStatus($status);
    }

    public static function camposProhibidos(): array
    {
        return [['id_usuario', 42], ['password', 'Frase ficticia 123!'], ['antecedentes', 'No permitido'],
            ['recordatorios_habilitados', true], ['rol', 'Paciente']];
    }

    #[DataProvider('camposProhibidos')]
    public function test_administrator_cannot_use_cu07_to_set_account_or_clinical_fields(string $campo, mixed $valor): void
    {
        $this->autenticar('Administrador');
        $this->postJson('/api/pacientes', [$campo => $valor, ...$this->datos()])->assertUnprocessable()
            ->assertJsonValidationErrors($campo, 'error.details');
    }

    public function test_administrator_still_needs_identity_reason_and_fresh_version(): void
    {
        $this->autenticar('Administrador');
        $this->pacientes->shouldReceive('consultar')->twice()->with('25')->andReturnUsing(fn () => $this->ficha());
        $this->patchJson('/api/pacientes/25', ['actualizado_en_original' => '2026-10-24T12:00:00.000000Z', 'nombres' => 'Cambio'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'PACIENTE_IDENTIDAD_NO_VERIFICADA');
        $this->patchJson('/api/pacientes/25', ['actualizado_en_original' => '2026-10-23T12:00:00.000000Z', 'telefono' => '71111111'])
            ->assertConflict()->assertJsonPath('error.code', 'PACIENTE_EDICION_OBSOLETA');
    }

    public function test_administrator_duplicate_ci_and_unreviewed_missing_ci_are_rejected(): void
    {
        $this->autenticar('Administrador');
        $this->postJson('/api/pacientes', [...$this->datos(), 'documento_identidad' => null])->assertUnprocessable();
        $this->registros->shouldReceive('documentoRegistrado')->once()->with('001-A')->andReturnTrue();
        $this->postJson('/api/pacientes', $this->datos())->assertConflict()->assertJsonPath('error.code', 'PACIENTE_DOCUMENTO_NO_DISPONIBLE');
    }

    public function test_administrator_is_denied_personal_profile_routes(): void
    {
        $this->autenticar('Administrador');
        foreach ([['GET', '/api/mi-perfil'], ['PATCH', '/api/mi-perfil'], ['POST', '/api/mi-perfil/correo'],
            ['POST', '/api/mi-perfil/correo/reenviar'], ['POST', '/api/mi-perfil/correo/verificar']] as [$metodo, $ruta]) {
            $this->json($metodo, $ruta)->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
        }
    }

    public function test_administrator_cannot_execute_personal_web_registration_with_an_active_session(): void
    {
        $this->autenticar('Administrador');
        $sesiones = Mockery::mock(SesionRepository::class);
        $sesiones->shouldReceive('usuarioId')->andReturn(71);
        $this->app->instance(SesionRepository::class, $sesiones);
        $datos = [...$this->datos(), 'correo_acceso' => 'paciente@example.test',
            'password' => 'Frase ficticia 123!', 'password_confirmation' => 'Frase ficticia 123!'];
        $this->postJson('/api/auth/registro', $datos)->assertConflict()->assertJsonPath('error.code', 'AUTH_SESION_EXISTENTE');
        $this->postJson('/api/auth/registro/vincular', ['documento_identidad' => '001-A', 'codigo_activacion' => 'Abc123Def456',
            'correo_acceso' => $datos['correo_acceso'], 'password' => $datos['password'], 'password_confirmation' => $datos['password_confirmation']])
            ->assertConflict()->assertJsonPath('error.code', 'AUTH_SESION_EXISTENTE');
        $this->postJson('/api/auth/registro/reenviar')->assertConflict()->assertJsonPath('error.code', 'AUTH_SESION_EXISTENTE');
        $this->postJson('/api/auth/registro/verificar', ['codigo' => '123456'])->assertConflict()->assertJsonPath('error.code', 'AUTH_SESION_EXISTENTE');
    }

    public function test_absent_session_does_not_gain_access_from_expanded_roles(): void
    {
        foreach ([['GET', '/api/pacientes'], ['GET', '/api/pacientes/25'], ['POST', '/api/pacientes'],
            ['PATCH', '/api/pacientes/25'], ['PATCH', '/api/pacientes/25/estado'], ['POST', '/api/pacientes/25/codigo-activacion']] as [$metodo, $ruta]) {
            $this->json($metodo, $ruta)->assertUnauthorized();
        }
    }

    public function test_administrator_account_and_normal_session_checks_are_not_bypassed(): void
    {
        $this->autenticar('Administrador');
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->once()->andThrow(new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.'));
        $auth->shouldReceive('exigirSesionNormal')->never();
        $this->app->instance(AutenticacionService::class, $auth);
        $this->getJson('/api/pacientes')->assertUnauthorized();
        $auth = Mockery::mock(AutenticacionService::class);
        $auth->shouldReceive('validarSesion')->once()->andReturn($this->actor);
        $auth->shouldReceive('exigirSesionNormal')->once()->andThrow(new AppError('AUTH_CAMBIO_CLAVE_OBLIGATORIO', 'Completa el cambio de contraseña.'));
        $this->app->instance(AutenticacionService::class, $auth);
        $this->postJson('/api/pacientes', $this->datos())->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public function test_administrator_missing_patient_does_not_create_code(): void
    {
        $this->autenticar('Administrador');
        $this->codigos->shouldReceive('pacientePorId')->once()->with('25')->andReturnNull();
        $this->postJson('/api/pacientes/25/codigo-activacion', ['identidad_verificada' => true])->assertNotFound();
        $this->pacientes->shouldReceive('consultar')->once()->with('25')->andReturnNull();
        $this->getJson('/api/pacientes/25')->assertNotFound();
    }

    public function test_code_rate_limit_is_still_applied_to_the_real_administrator(): void
    {
        $this->autenticar('Administrador');
        $limites = Mockery::mock(ControlIntentosService::class);
        $limites->shouldReceive('evaluarRegistroPaciente')->once()->with('emitir-recepcion', '71', Mockery::type('array'))
            ->andThrow(new AppError('AUTH_LIMITE_SOLICITUDES', 'Espera antes de volver a intentarlo.', 60));
        $this->app->instance(ControlIntentosService::class, $limites);
        $this->postJson('/api/pacientes/25/codigo-activacion', ['identidad_verificada' => true])->assertStatus(429);
    }
}
