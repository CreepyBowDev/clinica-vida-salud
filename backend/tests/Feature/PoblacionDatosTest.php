<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\AdministradorInicialSeeder;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\database\seeders\DemostracionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PoblacionDatosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Storage::fake('local');
        config([
            'seeders.demo_password' => 'Contraseña ficticia para prueba 123!',
            'seeders.administrador' => ['correo' => 'instalacion@clinica.invalid', 'nombres' => 'Persona ficticia', 'apellidos' => 'De prueba', 'password' => 'Contraseña ficticia de instalación 123!'],
        ]);
    }

    public function test_initial_seed_preserves_modified_rooms_and_never_creates_operational_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('consultorios')->where('codigo_consultorio', 'CONS-01')->update(['nombre' => 'Nombre local conservado', 'activo' => false]);
        $before = $this->snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(3, DB::table('consultorios')->count());
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('citas')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_installation_admin_is_active_with_hashed_password_and_explicit_installation_verification(): void
    {
        config(['seeders.administrador.correo' => ' INSTALACION@CLINICA.INVALID ']);
        $this->seed(AdministradorInicialSeeder::class);
        $admin = DB::table('usuarios')->sole();
        $this->assertSame('instalacion@clinica.invalid', $admin->correo_acceso);
        $this->assertSame('Activa', $admin->estado_cuenta);
        $this->assertTrue(Hash::check(config('seeders.administrador.password'), $admin->password_hash));
        $this->assertNotNull($admin->correo_verificado_en);
        $this->assertFalse($admin->cambio_clave_obligatorio);
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $event = DB::table('bitacora')->sole();
        $this->assertSame('SISTEMA', $event->tipo_actor);
        $this->assertSame($admin->id_usuario, $event->id_usuario_objetivo);
        $this->assertSame('INSTALACION_ADMIN_INICIAL', $event->tipo_evento);
        $this->assertEquals(['verificacion_correo' => 'AUTORIZACION_INSTALACION', 'metodo' => 'SEEDER'], json_decode($event->metadatos_seguros, true));
        $this->assertStringNotContainsString(config('seeders.administrador.password'), json_encode($event));
        $this->assertStringNotContainsString($admin->password_hash, json_encode($event));
        $this->assertStringNotContainsString($admin->correo_acceso, json_encode($event));
        DB::table('usuarios')->where('id_usuario', $admin->id_usuario)->update(['estado_cuenta' => 'Desactivada', 'password_hash' => Hash::make('Contraseña ficticia anterior')]);
        $before = $this->snapshot();
        config(['seeders.administrador.password' => 'Otra contraseña ficticia distinta 123!']);
        $this->seed(AdministradorInicialSeeder::class);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_installation_identity_is_rejected_without_creating_a_default_account(): void
    {
        config(['seeders.administrador.correo' => null]);
        try {
            $this->seed(AdministradorInicialSeeder::class);
            $this->fail('Se aceptó una instalación sin identidad configurada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('CLINICA_ADMIN_CORREO', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('usuarios')->count());
    }

    public function test_installation_seeder_does_not_promote_an_existing_patient(): void
    {
        $this->seed(DatabaseSeeder::class);
        $role = DB::table('roles')->where('nombre', 'Paciente')->value('id_rol');
        DB::table('usuarios')->insert(['correo_acceso' => 'instalacion@clinica.invalid', 'id_rol' => $role]);
        $before = $this->snapshot();
        try {
            $this->seed(AdministradorInicialSeeder::class);
            $this->fail('El seeder promovió una cuenta ajena.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('otra cuenta', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_demo_is_repeatable_preserves_changes_and_keeps_private_files_intact(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T10:00:00-04:00'));
        try {
            $this->seed(DemostracionSeeder::class);
            DB::table('pacientes')->where('codigo_paciente', 'DEMO-VS-P01')->update(['telefono' => '11111111', 'activo' => false]);
            DB::table('usuarios')->where('correo_acceso', 'demo.recepcion@example.test')->update(['estado_cuenta' => 'Desactivada']);
            $before = $this->snapshot();
            $files = collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($path) => [$path => hash('sha256', Storage::disk('local')->get($path))])->all();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20T10:00:00-04:00'));
            $this->seed(DemostracionSeeder::class);
            $this->assertSame($before, $this->snapshot());
            foreach ($files as $path => $hash) {
                $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
            }
            $this->assertSame(6, DB::table('usuarios')->count());
            $this->assertSame(5, DB::table('pacientes')->count());
            $this->assertSame(3, DB::table('medicos')->count());
            $this->assertSame(21, DB::table('horarios_atencion')->count());
            $this->assertSame(5, DB::table('citas')->count());
            $this->assertSame(9, DB::table('cambios_cita')->count());
            $this->assertSame(1, DB::table('consultas_medicas')->count());
            $this->assertSame(2, DB::table('versiones_consulta')->count());
            $this->assertSame(2, DB::table('versiones_antecedente')->count());
            $this->assertSame(3, DB::table('solicitudes_laboratorio')->count());
            $this->assertSame(3, DB::table('documentos_laboratorio')->count());
            $this->assertSame(3, DB::table('versiones_resultado')->count());
            $this->assertSame(2, DB::table('revisiones_resultado')->count());
            $this->assertSame(1, DB::table('bitacora')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_demo_roles_appointments_authors_and_publication_are_consistent(): void
    {
        $this->seed(DemostracionSeeder::class);
        foreach (DB::table('usuarios')->get() as $user) {
            $this->assertTrue(Hash::check(config('seeders.demo_password'), $user->password_hash));
            $this->assertStringEndsWith('@example.test', $user->correo_acceso);
        }
        $this->assertEqualsCanonicalizing(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'], DB::table('citas')->pluck('estado')->all());
        $this->assertSame('09:00', CarbonImmutable::parse(DB::table('citas')->where('codigo_cita', 'DEMO-VS-CITA-01')->value('inicio_at'))->setTimezone('America/La_Paz')->format('H:i'));
        foreach (DB::table('cambios_cita')->get() as $change) {
            $role = DB::table('usuarios')->join('roles', 'usuarios.id_rol', '=', 'roles.id_rol')->where('id_usuario', $change->id_actor)->value('roles.nombre');
            $this->assertSame($change->estado_nuevo === 'Atendida' ? 'Médico' : 'Recepcionista', $role);
        }
        $published = DB::table('solicitudes_laboratorio')->where('estado_interno', 'Disponible')->sole();
        $review = DB::table('revisiones_resultado')->where('id_revision', $published->id_revision_publicada)->sole();
        $this->assertSame($published->id_version_actual, $review->id_version_resultado);
        $this->assertSame('Autorizar', $review->decision);
        $returned = DB::table('revisiones_resultado')->where('decision', 'Devolver')->sole();
        $current = DB::table('solicitudes_laboratorio')->where('estado_interno', 'En revisión')->sole();
        $replacement = DB::table('versiones_resultado')->where('id_version_resultado', $current->id_version_actual)->sole();
        $this->assertSame($returned->id_version_resultado, $replacement->id_version_origen);
        $this->assertTrue(CarbonImmutable::parse($replacement->registrada_en)->greaterThan(CarbonImmutable::parse($returned->revisada_en)));
        $this->assertNull($current->id_revision_publicada);
        foreach (DB::table('documentos_laboratorio')->get() as $document) {
            $bytes = Storage::disk('local')->get($document->ruta_privada);
            $this->assertSame($document->tamano_bytes, strlen($bytes));
            $this->assertSame($document->sha256, hash('sha256', $bytes));
            $this->assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
            $this->assertStringContainsString('DOCUMENTO FICTICIO', $bytes);
        }
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(0, DB::table('tokens_acceso')->count());
        $this->assertSame(0, DB::table('recordatorios')->count());
        $this->seed(AdministradorInicialSeeder::class);
        $this->assertSame('Activa', DB::table('usuarios')->where('correo_acceso', 'instalacion@clinica.invalid')->value('estado_cuenta'));
    }

    #[DataProvider('invalidInstallationPasswords')]
    public function test_installation_requires_a_valid_private_password_before_writing_accounts(?string $password): void
    {
        config(['seeders.administrador.password' => $password]);
        $this->expectException(RuntimeException::class);
        try {
            $this->seed(AdministradorInicialSeeder::class);
        } finally {
            $this->assertSame(0, DB::table('usuarios')->count());
            $this->assertSame(0, DB::table('bitacora')->count());
        }
    }

    public static function invalidInstallationPasswords(): array
    {
        return [[null], [''], ['corta']];
    }

    public function test_installation_rolls_back_account_creation_if_audit_cannot_be_recorded(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prueba_admin_auditoria_fallar() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'fallo de prueba' USING ERRCODE = '23514'; END; $$;
            CREATE TRIGGER prueba_admin_auditoria_trg BEFORE INSERT ON bitacora
                FOR EACH ROW EXECUTE FUNCTION prueba_admin_auditoria_fallar();
            SQL);
        try {
            $this->seed(AdministradorInicialSeeder::class);
            $this->fail('Se confirmó la cuenta sin su evento de instalación.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('bitacora')->count());
        $this->assertSame(0, DB::table('roles')->count());
    }

    public function test_existing_file_with_different_bytes_is_preserved_and_database_load_is_rolled_back(): void
    {
        $path = 'demo-seeders/resultado-2-v1.pdf';
        Storage::disk('local')->put($path, 'Archivo previo ficticio diferente');
        try {
            $this->seed(DemostracionSeeder::class);
            $this->fail('Se sobrescribió un documento previo.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('otro contenido', $exception->getMessage());
        }
        $this->assertSame('Archivo previo ficticio diferente', Storage::disk('local')->get($path));
        $this->assertSame(0, DB::table('usuarios')->count());
    }

    public function test_installation_does_not_create_a_second_unrelated_administrator(): void
    {
        $this->seed(AdministradorInicialSeeder::class);
        $before = $this->snapshot();
        config(['seeders.administrador.correo' => 'otra.persona@clinica.invalid']);
        try {
            $this->seed(AdministradorInicialSeeder::class);
            $this->fail('Se creó otro Administrador de instalación.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ya existe', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_short_demo_password_is_rejected_before_creating_accounts_or_files(): void
    {
        config(['seeders.demo_password' => 'corta']);
        $this->expectException(RuntimeException::class);
        try {
            $this->seed(DemostracionSeeder::class);
        } finally {
            $this->assertSame(0, DB::table('usuarios')->count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_demo_is_refused_in_production_before_writing_anything(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        try {
            (new DemostracionSeeder)->run();
        } finally {
            $this->assertSame(0, DB::table('usuarios')->count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_late_failure_rolls_back_the_dataset_and_compensates_only_new_files(): void
    {
        Storage::disk('local')->put('preservar.txt', 'Archivo previo ficticio');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prueba_seed_fallar() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'fallo de prueba' USING ERRCODE = '23514'; END; $$;
            CREATE TRIGGER prueba_seed_trg BEFORE INSERT ON versiones_resultado
                FOR EACH ROW EXECUTE FUNCTION prueba_seed_fallar();
            SQL);
        try {
            $this->seed(DemostracionSeeder::class);
            $this->fail('No se provocó el fallo previsto.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame(0, DB::table('documentos_laboratorio')->count());
        $this->assertSame(['preservar.txt'], Storage::disk('local')->allFiles());
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name") as $row) {
            $snapshot[$row->table_name] = DB::table($row->table_name)->get()->map(fn ($record) => json_encode($record))->sort()->values()->all();
        }

        return $snapshot;
    }
}
