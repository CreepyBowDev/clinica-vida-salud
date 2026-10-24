<?php

namespace Tests\Fixtures;

use Clinica\data\models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait AutenticacionFixtures
{
    private const PASSWORD = '  Frase Ficticia Segura 123!  ';

    private function usuarioAuth(string $rol = 'Administrador', array $datos = [], bool $vincular = true): Usuario
    {
        $idRol = DB::table('roles')->where('nombre', $rol)->value('id_rol');
        $idRol ??= DB::table('roles')->insertGetId(['nombre' => $rol], 'id_rol');
        $id = DB::table('usuarios')->insertGetId(array_replace([
            'id_rol' => $idRol,
            'correo_acceso' => Str::lower(Str::random(16)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'estado_cuenta' => 'Activa',
            'correo_verificado_en' => now(),
            'nombres_personal' => 'Persona',
            'apellidos_personal' => 'Ficticia',
        ], $datos), 'id_usuario');
        if ($vincular && $rol === 'Paciente') {
            DB::table('pacientes')->insert([
                'id_usuario' => $id, 'codigo_paciente' => Str::random(16), 'nombres' => 'Paciente',
                'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'activo' => false,
            ]);
        }
        if ($vincular && $rol === 'Médico') {
            DB::table('medicos')->insert([
                'id_usuario' => $id, 'codigo_medico' => Str::random(16), 'registro_profesional' => 'REG-FICTICIO',
                'nombres' => 'Medico', 'apellidos' => 'Ficticio', 'telefono' => '70000000',
                'correo_contacto' => 'profesional@example.test', 'activo' => false,
            ]);
        }

        return Usuario::query()->findOrFail($id);
    }

    private function navegador(array &$cookies, string $metodo, string $uri, array $datos = [], array $headers = [], bool $csrf = true): TestResponse
    {
        // Cada petición usa guard y Store nuevos: la autenticación se restaura SOLO desde cookies/DB.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetScopedInstances();
        $headers = array_replace([
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
            'Origin' => 'http://localhost:5173',
        ], $headers);
        if ($csrf && isset($cookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $cookies['XSRF-TOKEN'];
        }
        $server = $this->transformHeadersToServerVars(array_filter($headers, fn ($value) => $value !== null));
        $server['REMOTE_ADDR'] = $headers['X-Test-IP'] ?? '192.0.2.10';
        $response = $this->call($metodo, $uri, [], $cookies, [], $server, json_encode($datos));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= now()->timestamp) {
                unset($cookies[$cookie->getName()]);
            } else {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    private function csrfAuth(): array
    {
        $cookies = [];
        $this->navegador($cookies, 'GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $cookies;
    }

    private function loginAuth(array &$cookies, Usuario $usuario, ?string $password = null, array $headers = []): TestResponse
    {
        return $this->navegador($cookies, 'POST', '/api/auth/login', [
            'correo_acceso' => $usuario->correo_acceso, 'password' => $password ?? self::PASSWORD,
        ], $headers);
    }
}
