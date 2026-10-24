<?php

namespace Tests\Feature;

use Clinica\business\validators\PoliticaClave;
use Clinica\data\database\seeders\AdministradorInicialSeeder;
use Clinica\data\database\seeders\CuentasPruebaSeeder;
use Clinica\data\database\seeders\DemostracionSeeder;
use Clinica\presentation\dtos\usuarios\AceptarInvitacionRequest;
use Clinica\presentation\dtos\usuarios\CambiarClaveRequest;
use Clinica\presentation\dtos\usuarios\CompletarClaveTemporalRequest;
use Clinica\presentation\dtos\usuarios\LoginRequest;
use Clinica\presentation\dtos\usuarios\RegistrarPacienteRequest;
use Clinica\presentation\dtos\usuarios\RestablecerClaveRequest;
use Clinica\presentation\dtos\usuarios\VincularPacienteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class PoliticaClaveTest extends TestCase
{
    use AutenticacionFixtures;

    public static function solicitudes(): array
    {
        return array_map(fn ($class) => [$class], [RegistrarPacienteRequest::class, VincularPacienteRequest::class,
            AceptarInvitacionRequest::class, CambiarClaveRequest::class, RestablecerClaveRequest::class, CompletarClaveTemporalRequest::class]);
    }

    public static function clavesInvalidas(): array
    {
        return [
            'sin mayuscula' => ['frase ficticia 123!'],
            'sin minuscula' => ['FRASE FICTICIA 123!'],
            'sin numero' => ['Frase ficticia larga!'],
            'sin especial' => ['Frase ficticia 123'],
            'espacios no son simbolos' => ['  Frase ficticia 123  '],
            'espacio unicode no es simbolo' => ["FraseFicticia123\u{00A0}"],
            'longitud corta' => ['Ab1!cdefghi'],
            'numero no decimal' => ['Frase ficticia Ⅳ!'],
            'bcrypt mas de 72 bytes' => ['Aa1!'.str_repeat('á', 35)],
            'bcrypt nulo' => ["Frase ficticia 123!\0"],
        ];
    }

    public static function solicitudesInvalidas(): array
    {
        $casos = [];
        foreach (self::solicitudes() as [$class]) {
            foreach (self::clavesInvalidas() as $nombre => [$clave]) {
                $casos[class_basename($class).' '.$nombre] = [$class, $clave];
            }
        }

        return $casos;
    }

    #[DataProvider('solicitudesInvalidas')]
    public function test_all_new_password_flows_reject_invalid_composition_or_hash_limits(string $class, string $clave): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $request = new $class;
        $validator = Validator::make(['password' => $clave, 'password_confirmation' => $clave],
            array_intersect_key($request->rules(), array_flip(['password', 'password_confirmation'])), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
        $this->assertStringNotContainsString($clave, implode(' ', $validator->errors()->all()));
    }

    #[DataProvider('solicitudes')]
    public function test_all_new_password_flows_accept_unicode_symbols_and_preserve_confirmation(string $class): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $request = new $class;
        $rules = array_intersect_key($request->rules(), array_flip(['password', 'password_confirmation']));
        foreach (['Aa1!'.str_repeat('a', 8), 'Aa1!'.str_repeat('a', 68), 'Áa1!'.str_repeat('á', 33).'a', '  Frase ficticia 123€  ', 'Frase ficticia 123🙂'] as $clave) {
            $this->assertTrue(Validator::make(['password' => $clave, 'password_confirmation' => $clave], $rules)->passes());
        }
        $this->assertTrue(Validator::make(['password' => 'Frase ficticia 123!', 'password_confirmation' => 'Distinta ficticia 123!'], $rules)->fails());
    }

    public function test_non_bcrypt_driver_preserves_the_existing_character_limit(): void
    {
        config(['hashing.driver' => 'argon2id']);
        $this->assertTrue(Validator::make(['password' => 'Aa1!'.str_repeat('á', 1020)], ['password' => PoliticaClave::reglas()])->passes());
        $this->assertTrue(Validator::make(['password' => 'Aa1!'.str_repeat('á', 1021)], ['password' => PoliticaClave::reglas()])->fails());
    }

    public function test_login_and_current_password_rules_keep_accepting_legacy_composition(): void
    {
        $legacy = 'Frase ficticia anterior 123';
        $this->assertTrue(Validator::make(['correo_acceso' => 'persona@example.test', 'password' => $legacy], (new LoginRequest)->rules())->passes());
        $this->assertTrue(Validator::make(['current_password' => $legacy],
            ['current_password' => (new CambiarClaveRequest)->rules()['current_password']])->passes());
    }

    public function test_old_password_still_logs_in_but_new_weak_password_does_not_revoke_sessions(): void
    {
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        $anterior = 'Frase ficticia anterior 123';
        $usuario = $this->usuarioAuth(datos: ['password_hash' => Hash::make($anterior)]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $usuario, $anterior)->assertOk();
        $sesiones = DB::table('sessions')->where('user_id', $usuario->getKey())->pluck('id')->all();
        $this->navegador($cookies, 'PUT', '/api/auth/password', ['current_password' => $anterior,
            'password' => $anterior, 'password_confirmation' => $anterior])->assertStatus(422)
            ->assertJsonValidationErrors('password', 'error.details');
        $this->assertSame($usuario->password_hash, $usuario->fresh()->password_hash);
        $this->assertSame($sesiones, DB::table('sessions')->where('user_id', $usuario->getKey())->pluck('id')->all());
        $this->navegador($cookies, 'GET', '/api/auth/me')->assertOk();
        $this->navegador($cookies, 'PUT', '/api/auth/password', ['current_password' => $anterior,
            'password' => 'Frase ficticia nueva 123!', 'password_confirmation' => 'Frase ficticia nueva 123!'])->assertNoContent();
        $this->assertTrue(Hash::check('Frase ficticia nueva 123!', $usuario->fresh()->password_hash));
    }

    public static function seedersInvalidos(): array
    {
        $casos = [];
        foreach ([AdministradorInicialSeeder::class, CuentasPruebaSeeder::class, DemostracionSeeder::class] as $class) {
            foreach (self::clavesInvalidas() as $nombre => [$clave]) {
                $casos[class_basename($class).' '.$nombre] = [$class, $clave];
            }
        }

        return $casos;
    }

    #[DataProvider('seedersInvalidos')]
    public function test_seeders_reject_the_same_policy_without_writing_accounts(string $class, string $clave): void
    {
        config(['hashing.driver' => 'bcrypt', 'seeders.cuentas_prueba_habilitadas' => true,
            'seeders.demo_password' => $clave, 'seeders.administrador' => ['correo' => 'instalacion@clinica.invalid',
                'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'password' => $clave]]);
        DB::shouldReceive('transaction')->never();
        if ($class === DemostracionSeeder::class) {
            DB::shouldReceive('connection->getDriverName')->once()->andReturn('pgsql');
            DB::shouldReceive('selectOne')->once()->with('SELECT current_database() AS database, current_user AS username')
                ->andReturn((object) ['database' => 'clinica_vida_salud_test', 'username' => 'clinica_vida_salud_test']);
        }
        try {
            (new $class)->run();
            $this->fail('El seeder aceptó una contraseña inválida.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('PASSWORD', $error->getMessage());
            $this->assertStringNotContainsString($clave, $error->getMessage());
        }
    }
}
