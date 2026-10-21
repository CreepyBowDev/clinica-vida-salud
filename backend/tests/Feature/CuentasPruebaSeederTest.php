<?php

namespace Tests\Feature;

use Clinica\data\database\seeders\AdministradorInicialSeeder;
use Clinica\data\database\seeders\CuentasPruebaSeeder;
use Clinica\data\database\seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class CuentasPruebaSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
        config([
            'seeders.cuentas_prueba_habilitadas' => true,
            'seeders.demo_password' => 'Clave ficticia de pruebas 123',
            'seeders.administrador' => [
                'correo' => 'instalacion@clinica.invalid', 'nombres' => 'Administrador',
                'apellidos' => 'Ficticio', 'password' => 'Clave privada diferente 456',
            ],
        ]);
        Mail::fake();
    }

    public function test_covers_all_active_specialties_and_preserves_the_only_admin_without_sending_mail(): void
    {
        $this->seed(AdministradorInicialSeeder::class);
        $admin = DB::table('usuarios')->sole();
        $extra = DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad ficticia adicional'], 'id_especialidad');
        DB::table('especialidades')->insert(['nombre' => 'Especialidad ficticia inactiva', 'activa' => false]);

        $this->seed(CuentasPruebaSeeder::class);

        $this->assertEquals($admin, DB::table('usuarios')->where('id_usuario', $admin->id_usuario)->sole());
        $counts = DB::table('usuarios')->join('roles', 'usuarios.id_rol', '=', 'roles.id_rol')
            ->selectRaw('roles.nombre, count(*) as total')->groupBy('roles.nombre')->pluck('total', 'nombre')->all();
        $this->assertEquals(['Administrador' => 1, 'Médico' => 4, 'Recepcionista' => 2, 'Paciente' => 10], $counts);
        $this->assertSame(4, DB::table('medicos')->count());
        $this->assertSame(10, DB::table('pacientes')->whereNotNull('id_usuario')->count());
        $this->assertSame(4, DB::table('medico_especialidad')->count());
        $this->assertDatabaseHas('usuarios', ['correo_acceso' => 'prueba.medico.e'.$extra.'@example.test']);
        $hashes = [];
        foreach (DB::table('usuarios')->where('id_usuario', '<>', $admin->id_usuario)->get() as $user) {
            $this->assertSame('Activa', $user->estado_cuenta);
            $this->assertNotNull($user->correo_verificado_en);
            $this->assertFalse($user->cambio_clave_obligatorio);
            $this->assertTrue(Hash::check(config('seeders.demo_password'), $user->password_hash));
            $hashes[] = $user->password_hash;
        }
        $this->assertCount(16, array_unique($hashes));
        $events = DB::table('bitacora')->where('tipo_evento', 'SEED_CUENTA_PRUEBA')->get();
        $this->assertCount(16, $events);
        $this->assertStringNotContainsString(config('seeders.demo_password'), $events->toJson());
        foreach (['citas', 'tokens_acceso', 'horarios_atencion', 'consultas_medicas'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_reexecution_preserves_edited_accounts_passwords_profiles_and_adds_new_specialties(): void
    {
        $this->seed(CuentasPruebaSeeder::class);
        DB::table('usuarios')->where('correo_acceso', 'prueba.paciente01@example.test')->update([
            'correo_acceso' => 'correo.cambiado@example.test', 'estado_cuenta' => 'Desactivada',
            'password_hash' => Hash::make('Otra clave de prueba 456'), 'intentos_fallidos' => 2,
        ]);
        DB::table('pacientes')->where('codigo_paciente', 'PRUEBA-P01')->update(['telefono' => '71111111', 'activo' => false]);
        $before = $this->snapshot();
        config(['seeders.demo_password' => 'Nueva clave no debe sobrescribir 789']);
        $this->seed(CuentasPruebaSeeder::class);
        $this->assertSame($before, $this->snapshot());

        $extra = DB::table('especialidades')->insertGetId(['nombre' => 'Nueva especialidad ficticia'], 'id_especialidad');
        $this->seed(CuentasPruebaSeeder::class);
        $this->assertSame(4, DB::table('medicos')->count());
        $user = DB::table('usuarios')->where('correo_acceso', 'prueba.medico.e'.$extra.'@example.test')->sole();
        $this->assertTrue(Hash::check(config('seeders.demo_password'), $user->password_hash));
        $this->assertSame(10, DB::table('pacientes')->count());
    }

    public function test_disabled_or_missing_password_causes_no_writes(): void
    {
        $before = $this->snapshot();
        foreach ([[false, 'Clave ficticia valida 123'], [true, null], [true, 'corta']] as [$enabled, $password]) {
            config(['seeders.cuentas_prueba_habilitadas' => $enabled, 'seeders.demo_password' => $password]);
            try {
                $this->seed(CuentasPruebaSeeder::class);
                $this->fail('Se aceptó una configuración incompleta.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('CLINICA_', $error->getMessage());
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_foreign_email_collision_rolls_back_all_new_accounts_and_keeps_original(): void
    {
        DB::table('usuarios')->insert([
            'id_rol' => DB::table('roles')->where('nombre', 'Paciente')->value('id_rol'),
            'correo_acceso' => ' PRUEBA.PACIENTE10@EXAMPLE.TEST ',
        ]);
        $before = $this->snapshot();
        try {
            $this->seed(CuentasPruebaSeeder::class);
            $this->fail('Se adoptó una cuenta ajena.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cuenta ajena', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_default_database_seeder_does_not_create_test_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('medicos')->count());
        $this->assertSame(0, DB::table('pacientes')->count());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['usuarios' => 'id_usuario', 'medicos' => 'id_medico', 'pacientes' => 'id_paciente', 'bitacora' => 'id_evento'] as $table => $key) {
            $result[$table] = DB::table($table)->orderBy($key)->get()->toJson();
        }

        return $result;
    }
}
