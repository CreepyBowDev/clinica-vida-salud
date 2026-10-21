<?php

// Proceso auxiliar exclusivo de pruebas; nunca se registra como ruta o comando de la aplicación.
use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\CorreoSeguridadService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\PausarCambioClave;
use Tests\Fixtures\PausarConsultaBitacora;
use Tests\Fixtures\PausarLecturaBitacoraRepository;
use Tests\Fixtures\PausarRespuestaAutenticacion;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $identity = DB::selectOne('SELECT current_database() AS db, current_user AS username');
    if (! $app->environment('testing') || $identity->db !== 'clinica_vida_salud_test' || $identity->username !== 'clinica_vida_salud_test') {
        throw new RuntimeException('El proceso requiere la base y el rol aislados de pruebas.');
    }
    Http::preventStrayRequests();
    $data = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/^prueba_auth_[a-z0-9]{16}$/D', $data['schema'])) {
        throw new RuntimeException('Esquema de prueba no permitido.');
    }
    DB::select('SELECT set_config(?, ?, false)', ['search_path', $data['schema'].', public, pg_temp']);
    if (isset($data['ahora'])) {
        Carbon\Carbon::setTestNow($data['ahora']);
        CarbonImmutable::setTestNow($data['ahora']);
    }
    config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'hashing.bcrypt.rounds' => 4]);
    if (str_starts_with($data['mode'], 'personal') || str_starts_with($data['mode'], 'recuperacion-') || str_starts_with($data['mode'], 'registro-') || str_starts_with($data['mode'], 'paciente-correo') || $data['mode'] === 'invitacion-aceptar') {
        config(['seguridad.correo.mailer' => 'array']);
        Mail::fake();
    }
    if ($data['mode'] === 'paciente-correo-pausa') {
        $app->instance(CorreoSeguridadService::class, new class extends CorreoSeguridadService
        {
            public function enviarCambioCorreo(string $correo, #[SensitiveParameter] string $codigo): string
            {
                if (DB::transactionLevel() !== 0) {
                    throw new RuntimeException('El transporte no debe mantener una transacción.');
                }
                echo "TRANSPORTE\n";
                fflush(STDOUT);
                if (trim(fgets(STDIN)) !== 'CONTINUAR') {
                    throw new RuntimeException('Falta barrera de transporte.');
                }

                return 'simulado';
            }
        });
    }
    if (in_array($data['mode'], ['login-pause', 'password-pause'], true)) {
        $app['router']->pushMiddlewareToGroup('api', PausarRespuestaAutenticacion::class);
    }
    if (in_array($data['mode'], ['password-before', 'temporal-completar-before'], true)) {
        $app['events']->listen(RouteMatched::class, function (RouteMatched $event) use ($data): void {
            if ($event->route->uri() === ($data['mode'] === 'password-before' ? 'api/auth/password' : 'api/auth/password/temporal')) {
                $event->route->middleware(PausarCambioClave::class);
            }
        });
    }
    if ($data['mode'] === 'bitacora-antes') {
        $app['events']->listen(RouteMatched::class, function (RouteMatched $event): void {
            if (str_starts_with($event->route->uri(), 'api/bitacora')) {
                $event->route->middleware(PausarConsultaBitacora::class);
            }
        });
    }
    if ($data['mode'] === 'bitacora-lectura') {
        $app->instance(BitacoraRepository::class, new PausarLecturaBitacoraRepository);
    }
    echo json_encode(['ready' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]), "\n";
    fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'INICIAR') {
        throw new RuntimeException('Falta barrera de inicio.');
    }
    $cookies = $data['cookies'] ?? [];
    if (str_starts_with($data['mode'], 'password') || str_starts_with($data['mode'], 'personal') || str_starts_with($data['mode'], 'cuenta-') || str_starts_with($data['mode'], 'recuperacion-') || str_starts_with($data['mode'], 'temporal-') || str_starts_with($data['mode'], 'registro-') || str_starts_with($data['mode'], 'paciente-') || str_starts_with($data['mode'], 'especialidad-') || str_starts_with($data['mode'], 'medico-') || $data['mode'] === 'invitacion-aceptar') {
        // Ya se validó la identidad PostgreSQL aislada; ejercitar ahora CSRF real.
        $app['env'] = 'local';
    }
    $send = function (string $method, string $path, array $payload = []) use ($app, $data, &$cookies) {
        $app['auth']->forgetGuards();
        $app->forgetInstance('auth.driver');
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $request = Request::create('http://localhost:8000'.$path, $method, [], $cookies, [], [
            'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $data['ip'],
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        ], json_encode($payload));
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    };
    // Simula únicamente el protocolo de escritura que deberán compartir CU-14/CU-15.
    // No registra una ruta ni implementa una reserva funcional de la aplicación.
    $reservaMedico = function () use ($data) {
        return DB::transaction(function () use ($data) {
            $fila = $data['fila_cita'];
            $medico = DB::table('medicos')->where('id_medico', $fila['id_medico'])->lockForUpdate()->first();
            $especialidad = DB::table('especialidades')->where('id_especialidad', $fila['id_especialidad'])->lockForUpdate()->first();
            $par = DB::table('medico_especialidad')->where('id_medico', $fila['id_medico'])->where('id_especialidad', $fila['id_especialidad'])->lockForUpdate()->first();
            if (! $medico?->activo || ! $especialidad?->activa || ! $par?->habilitada) {
                return new JsonResponse([], 409);
            }
            DB::table('citas')->insert($fila);

            return new JsonResponse([], 201);
        });
    };
    $response = match (true) {
        str_starts_with($data['mode'], 'bitacora-') => $send('GET', $data['ruta']),
        $data['mode'] === 'medico-reserva-protocolo' => $reservaMedico(),
        $data['mode'] === 'medico-crear' => $send('POST', '/api/medicos', $data['payload']),
        $data['mode'] === 'medico-editar' => $send('PATCH', '/api/medicos/'.$data['id_objetivo'], $data['payload']),
        $data['mode'] === 'medico-estado' => $send('PATCH', '/api/medicos/'.$data['id_objetivo'].'/estado', $data['payload']),
        $data['mode'] === 'medico-especialidades' => $send('PUT', '/api/medicos/'.$data['id_objetivo'].'/especialidades', $data['payload']),
        $data['mode'] === 'especialidad-crear' => $send('POST', '/api/especialidades', $data['payload']),
        $data['mode'] === 'especialidad-editar' => $send('PATCH', '/api/especialidades/'.$data['id_objetivo'], $data['payload']),
        $data['mode'] === 'especialidad-estado' => $send('PATCH', '/api/especialidades/'.$data['id_objetivo'].'/estado', $data['payload']),
        in_array($data['mode'], ['paciente-correo', 'paciente-correo-pausa'], true) => $send('POST', '/api/mi-perfil/correo', $data['payload']),
        $data['mode'] === 'paciente-correo-verificar' => $send('POST', '/api/mi-perfil/correo/verificar', ['codigo' => $data['codigo']]),
        $data['mode'] === 'paciente-crear' => $send('POST', '/api/pacientes', $data['payload']),
        $data['mode'] === 'paciente-editar' => $send('PATCH', '/api/pacientes/'.$data['id_objetivo'], $data['payload']),
        $data['mode'] === 'paciente-estado' => $send('PATCH', '/api/pacientes/'.$data['id_objetivo'].'/estado', $data['payload']),
        $data['mode'] === 'paciente-perfil' => $send('PATCH', '/api/mi-perfil', $data['payload']),
        $data['mode'] === 'registro-vincular' => $send('POST', '/api/auth/registro/vincular', $data['payload']),
        $data['mode'] === 'registro-emitir' => $send('POST', '/api/pacientes/'.$data['id_objetivo'].'/codigo-activacion', ['identidad_verificada' => true]),
        $data['mode'] === 'registro-iniciar' => $send('POST', '/api/auth/registro', $data['payload']),
        $data['mode'] === 'registro-reenviar' => $send('POST', '/api/auth/registro/reenviar'),
        $data['mode'] === 'registro-verificar' => $send('POST', '/api/auth/registro/verificar', ['codigo' => $data['codigo']]),
        $data['mode'] === 'temporal-emitir' => $send('POST', '/api/usuarios/'.$data['id_objetivo'].'/credencial-temporal', ['identidad_verificada' => true]),
        str_starts_with($data['mode'], 'temporal-completar') => $send('PUT', '/api/auth/password/temporal', ['password' => $data['password'], 'password_confirmation' => $data['password']]),
        str_starts_with($data['mode'], 'password') => $send('PUT', '/api/auth/password', ['current_password' => $data['password'], 'password' => $data['nueva'], 'password_confirmation' => $data['nueva']]),
        $data['mode'] === 'personal-crear' => $send('POST', '/api/personal', $data['payload']),
        $data['mode'] === 'personal-reenviar' => $send('POST', '/api/personal/'.$data['id_objetivo'].'/invitacion'),
        $data['mode'] === 'invitacion-aceptar' => $send('POST', '/api/auth/invitaciones/aceptar', ['token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']]),
        $data['mode'] === 'recuperacion-reset' => $send('POST', '/api/auth/password/reset', ['token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']]),
        $data['mode'] === 'recuperacion-solicitar' => $send('POST', '/api/auth/password/forgot', ['correo_acceso' => $data['correo']]),
        str_starts_with($data['mode'], 'cuenta-') => $send('PATCH', '/api/usuarios/'.$data['id_objetivo'].'/'.substr($data['mode'], 7), $data['payload']),
        default => $send('POST', '/api/auth/login', ['correo_acceso' => $data['correo'], 'password' => $data['password']]),
    };
    $result = ['status' => $response->getStatusCode()];
    if (str_starts_with($data['mode'], 'paciente-correo')) {
        $result['envio'] = json_decode($response->getContent(), true)['data']['envio'] ?? null;
    }
    if (in_array($data['mode'], ['login-pause', 'password-pause'], true)) {
        $result['me_status'] = $send('GET', '/api/auth/me')->getStatusCode();
    }
    echo json_encode($result), "\n";
} catch (Throwable $error) {
    // Sin SQL, credenciales, cookies ni trazas en los mensajes del proceso.
    fwrite(STDERR, 'Fallo del proceso de prueba: '.$error::class."\n");
    exit(1);
}
