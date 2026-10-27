<?php

namespace Tests\Feature;

use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\validators\CredencialTemporalValidator;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Rol;
use Clinica\data\models\TokenAcceso;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\TokenAccesoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Matriz de rutas/gates y rechazos HTTP con AutenticacionService real.
// Repositorios de identidad/sesión simulados; no prueba los CU positivos ni revocación SQL.
class MatrizPermisosBackendTest extends TestCase
{
    private Usuario $actor;

    private bool $coincide = true;

    private bool $vigente = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'sanctum.stateful' => ['localhost:5173']]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        DB::shouldReceive('connection')->never();
        $usuarios = Mockery::mock(UsuarioRepository::class);
        $usuarios->shouldReceive('porId')->with(71, Mockery::type('bool'))->andReturnUsing(fn () => $this->actor);
        $this->app->instance(UsuarioRepository::class, $usuarios);
        $sesiones = Mockery::mock(SesionRepository::class);
        $sesiones->shouldReceive('usuarioId')->andReturn(71);
        $sesiones->shouldReceive('coincide')->andReturnUsing(fn () => $this->coincide);
        $sesiones->shouldReceive('vigente')->andReturnUsing(fn () => $this->vigente);
        $sesiones->shouldReceive('actualizarActor');
        $sesiones->shouldReceive('cerrar');
        $sesiones->shouldReceive('credencialTemporalId')->andReturn(9);
        $this->app->instance(SesionRepository::class, $sesiones);
        $tokens = Mockery::mock(TokenAccesoRepository::class);
        $tokens->shouldReceive('temporalPorId')->andReturn(new TokenAcceso);
        $this->app->instance(TokenAccesoRepository::class, $tokens);
        $temporales = Mockery::mock(CredencialTemporalValidator::class);
        $temporales->shouldReceive('vigente')->andReturnTrue();
        $this->app->instance(CredencialTemporalValidator::class, $temporales);
    }

    private function autenticar(string $rol = 'Administrador'): void
    {
        $this->actor = (new Usuario)->forceFill(['id_usuario' => 71, 'estado_cuenta' => 'Activa',
            'correo_verificado_en' => '2026-10-24T12:00:00Z', 'password_hash' => 'hash-ficticio',
            'nombres_personal' => 'Persona', 'apellidos_personal' => 'Ficticia']);
        $this->actor->setRelation('rol', (new Rol)->forceFill(['nombre' => $rol]));
        $this->actor->setRelation('paciente', $rol === 'Paciente' ? (new Paciente)->forceFill(['id_paciente' => 25, 'id_usuario' => 71]) : null);
        $this->actor->setRelation('medico', $rol === 'Médico' ? (new Medico)->forceFill(['id_medico' => 31, 'id_usuario' => 71]) : null);
        $this->actingAs($this->actor, 'web');
    }

    public static function rutas(): array
    {
        $rutas = [];
        $agregar = static function (string $roles, array $operaciones) use (&$rutas): void {
            foreach ($operaciones as $operacion) {
                [$metodo, $uri] = explode(' ', $operacion, 2);
                $rutas[$operacion] = [$metodo, $uri, explode(',', $roles)];
            }
        };
        $agregar('Administrador', [
            'GET usuarios', 'GET usuarios/{id}', 'PATCH usuarios/{id}/estado', 'PATCH usuarios/{id}/rol',
            'POST usuarios/{id}/credencial-temporal', 'POST personal', 'POST personal/{id}/invitacion',
            'GET medicos', 'POST medicos', 'GET medicos/{id}', 'PATCH medicos/{id}', 'PATCH medicos/{id}/estado', 'PUT medicos/{id}/especialidades',
            'GET especialidades', 'POST especialidades', 'GET especialidades/{id}', 'PATCH especialidades/{id}', 'PATCH especialidades/{id}/estado',
            'GET consultorios', 'POST consultorios', 'GET consultorios/{id}', 'PATCH consultorios/{id}', 'PATCH consultorios/{id}/estado',
            'GET medicos/{id}/consultorio', 'PUT medicos/{id}/consultorio', 'POST medicos/{id}/consultorio/impacto',
            'GET medicos/{id}/horarios', 'POST medicos/{id}/horarios', 'POST medicos/{id}/horarios/impacto',
            'PATCH medicos/{id}/horarios/{horario}', 'PATCH medicos/{id}/horarios/{horario}/estado',
            'GET configuracion-citas', 'PATCH configuracion-citas', 'POST configuracion-citas/impacto', 'GET bitacora', 'GET bitacora/{id}',
        ]);
        $agregar('Administrador,Recepcionista', ['GET pacientes', 'POST pacientes', 'GET pacientes/{id}', 'PATCH pacientes/{id}',
            'PATCH pacientes/{id}/estado', 'POST pacientes/{id}/codigo-activacion', 'GET pacientes/{id}/citas', 'GET agenda/medicos',
            'GET citas/{id}/disponibilidad-reprogramacion', 'GET citas/{id}/gestion', 'PATCH citas/{id}/reprogramacion',
            'PATCH citas/{id}/cancelacion']);
        $agregar('Administrador,Recepcionista,Paciente', ['GET reservas/especialidades', 'GET reservas/medicos', 'GET reservas/disponibilidad',
            'POST reservas/propuesta', 'POST reservas/resultado', 'POST citas']);
        $agregar('Administrador,Recepcionista,Médico', ['GET agenda']);
        $agregar('Administrador,Recepcionista,Médico,Paciente', ['GET citas/{id}']);
        $agregar('Paciente', ['GET mi-perfil', 'PATCH mi-perfil', 'POST mi-perfil/correo', 'POST mi-perfil/correo/reenviar',
            'POST mi-perfil/correo/verificar', 'GET mis-citas']);

        return $rutas;
    }

    private function url(string $uri): string
    {
        return '/api/'.str_replace(['{id}', '{horario}'], ['25', '31'], $uri);
    }

    #[DataProvider('rutas')]
    public function test_registered_role_matrix_and_direct_gate_for_all_four_roles(string $metodo, string $uri, array $roles): void
    {
        $request = Request::create($this->url($uri), $metodo);
        $route = $this->app['router']->getRoutes()->match($request);
        $this->assertSame('api/'.$uri, $route->uri());
        $middlewares = $route->gatherMiddleware();
        foreach (['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class, ExigirRol::class.':'.implode(',', $roles)] as $middleware) {
            $this->assertContains($middleware, $middlewares);
        }
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $this->autenticar($rol);
            $request->setUserResolver(fn () => $this->actor);
            try {
                $response = (new ExigirRol)->handle($request, fn () => response()->noContent(), ...$roles);
                $this->assertContains($rol, $roles);
                $this->assertSame(204, $response->getStatusCode());
            } catch (AppError $error) {
                $this->assertNotContains($rol, $roles);
                $this->assertSame('ACCESO_DENEGADO', $error->codigo);
            }
        }
    }

    public static function denegados(): array
    {
        $casos = [];
        foreach (self::rutas() as $nombre => [$metodo, $uri, $roles]) {
            foreach (array_diff(['Administrador', 'Recepcionista', 'Médico', 'Paciente'], $roles) as $rol) {
                $casos[$rol.' '.$nombre] = [$metodo, $uri, $rol];
            }
        }

        return $casos;
    }

    #[DataProvider('denegados')]
    public function test_forbidden_http_roles_do_not_reach_business_persistence(string $metodo, string $uri, string $rol): void
    {
        $this->autenticar($rol);
        $this->json($metodo, $this->url($uri))->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
    }

    #[DataProvider('rutas')]
    public function test_all_role_protected_routes_reject_anonymous_http(string $metodo, string $uri, array $roles): void
    {
        $this->json($metodo, $this->url($uri))->assertUnauthorized();
    }

    public static function estados(): array
    {
        $casos = [];
        foreach (self::rutas() as $nombre => [$metodo, $uri, $roles]) {
            if (! in_array('Administrador', $roles, true)) {
                continue;
            }
            foreach (['desactivada', 'no_verificada', 'huella_cambiada', 'restringida'] as $estado) {
                $casos[$estado.' '.$nombre] = [$metodo, $uri, $estado];
            }
        }

        return $casos;
    }

    #[DataProvider('estados')]
    public function test_real_authentication_rejects_invalid_administrative_access(string $metodo, string $uri, string $estado): void
    {
        $this->autenticar();
        match ($estado) {
            'desactivada' => $this->actor->estado_cuenta = 'Desactivada',
            'no_verificada' => $this->actor->correo_verificado_en = null,
            'huella_cambiada' => $this->coincide = false,
            'revocada' => $this->vigente = false,
            'restringida' => $this->actor->cambio_clave_obligatorio = true,
        };
        $this->json($metodo, $this->url($uri))->assertStatus($estado === 'restringida' ? 403 : 401)
            ->assertJsonPath('error.code', $estado === 'restringida' ? 'AUTH_CAMBIO_CLAVE_OBLIGATORIO' : 'AUTH_NO_AUTENTICADO');
    }

    public function test_matrix_covers_every_registered_role_gate_without_duplicates(): void
    {
        $actual = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, ExigirRol::class.':')) === []) {
                continue;
            }
            $key = $route->methods()[0].' '.substr($route->uri(), 4);
            $this->assertNotContains($key, $actual);
            $actual[] = $key;
        }
        $this->assertEqualsCanonicalizing(array_keys(self::rutas()), $actual);
    }

    public function test_service_revalidation_rejects_revoked_session_after_lock(): void
    {
        $this->autenticar();
        $this->vigente = false;
        $this->expectException(AppError::class);
        $this->app->make(AutenticacionService::class)->exigirSesionNormal(bloquear: true);
    }

    public function test_no_unreviewed_api_or_cycle_two_endpoints_are_registered(): void
    {
        $esperadas = array_map(fn ($key) => explode(' ', $key, 2)[0].' api/'.explode(' ', $key, 2)[1], array_keys(self::rutas()));
        $comunes = ['POST api/auth/invitaciones/aceptar', 'POST api/auth/login', 'POST api/auth/logout',
            'GET api/auth/me', 'PUT api/auth/password', 'POST api/auth/password/forgot', 'POST api/auth/password/reset',
            'PUT api/auth/password/temporal', 'POST api/auth/registro', 'POST api/auth/registro/vincular',
            'POST api/auth/registro/reenviar', 'POST api/auth/registro/verificar'];
        $actuales = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $this->assertStringStartsWith('Clinica\\presentation\\controllers\\', $route->getActionName());
            foreach (array_diff($route->methods(), ['HEAD']) as $metodo) {
                $actuales[] = $metodo.' '.$route->uri();
            }
        }
        $this->assertEqualsCanonicalizing([...$esperadas, ...$comunes], $actuales);
    }
}
