<?php

// Proceso auxiliar exclusivo de pruebas; nunca se registra como ruta o comando de la aplicación.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\PausarCambioClave;
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
    config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'hashing.bcrypt.rounds' => 4]);
    if (str_starts_with($data['mode'], 'personal') || str_starts_with($data['mode'], 'recuperacion-') || $data['mode'] === 'invitacion-aceptar') {
        config(['seguridad.correo.mailer' => 'array']);
        Mail::fake();
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
    echo json_encode(['ready' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]), "\n";
    fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'INICIAR') {
        throw new RuntimeException('Falta barrera de inicio.');
    }
    $cookies = $data['cookies'] ?? [];
    if (str_starts_with($data['mode'], 'password') || str_starts_with($data['mode'], 'personal') || str_starts_with($data['mode'], 'cuenta-') || str_starts_with($data['mode'], 'recuperacion-') || str_starts_with($data['mode'], 'temporal-') || $data['mode'] === 'invitacion-aceptar') {
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
    $response = match (true) {
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
    if (in_array($data['mode'], ['login-pause', 'password-pause'], true)) {
        $result['me_status'] = $send('GET', '/api/auth/me')->getStatusCode();
    }
    echo json_encode($result), "\n";
} catch (Throwable $error) {
    // Sin SQL, credenciales, cookies ni trazas en los mensajes del proceso.
    fwrite(STDERR, 'Fallo del proceso de prueba: '.$error::class."\n");
    exit(1);
}
