<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\ConfiguracionClinica;
use Clinica\data\models\Consultorio;
use Clinica\data\models\ControlIntentos;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Rol;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogosInicialesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_migrations_create_dictionary_columns_with_cu09_extension_and_named_primary_keys(): void
    {
        $columns = [
            'roles' => ['id_rol', 'nombre'],
            'controles_intentos' => ['id_control', 'clave_ambito_hash', 'inicio_ventana', 'cantidad_intentos', 'bloqueado_hasta', 'actualizado_en'],
            'consultorios' => ['id_consultorio', 'codigo_consultorio', 'nombre', 'activo', 'actualizado_en'],
            // CU-09 incorpora versionado mediante la migración aditiva 000030.
            'especialidades' => ['id_especialidad', 'nombre', 'descripcion', 'activa', 'actualizado_en'],
            'configuracion_clinica' => ['id_configuracion', 'nombre_sede', 'direccion_sede', 'zona_horaria', 'duracion_turno_minutos', 'actualizado_en'],
        ];

        foreach ($columns as $table => $expected) {
            $this->assertSame($expected, Schema::getColumnListing($table));

            $primary = DB::selectOne(<<<'SQL'
                SELECT c.conname FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = 'public' AND t.relname = ? AND c.contype = 'p'
                SQL, [$table]);

            $this->assertSame($table.'_pk', $primary->conname);
        }

        $this->assertSame(count(glob(base_path('src/data/database/migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_database_defaults_are_available_without_eloquent_or_seeders(): void
    {
        DB::table('consultorios')->insert(['codigo_consultorio' => 'TEST-01', 'nombre' => 'Consultorio ficticio']);
        DB::table('especialidades')->insert(['nombre' => 'Especialidad ficticia']);
        DB::table('configuracion_clinica')->insert(['nombre_sede' => 'Sede ficticia']);
        DB::table('controles_intentos')->insert([
            'clave_ambito_hash' => str_repeat('a', 64),
            'inicio_ventana' => '2026-10-19 08:00:00-04:00',
        ]);

        $this->assertTrue(Consultorio::query()->firstOrFail()->activo);
        $this->assertTrue(Especialidad::query()->firstOrFail()->activa);
        $this->assertNull(Especialidad::query()->firstOrFail()->descripcion);
        $this->assertInstanceOf(CarbonImmutable::class, Especialidad::query()->firstOrFail()->actualizado_en);

        $config = ConfiguracionClinica::query()->firstOrFail();
        $this->assertSame(1, $config->getKey());
        $this->assertSame(15, $config->duracion_turno_minutos);
        $this->assertSame('America/La_Paz', $config->zona_horaria);
        $this->assertNull($config->direccion_sede);
        $this->assertInstanceOf(CarbonImmutable::class, $config->actualizado_en);

        $control = ControlIntentos::query()->firstOrFail();
        $this->assertSame(0, $control->cantidad_intentos);
        $this->assertNull($control->bloqueado_hasta);
        $this->assertInstanceOf(CarbonImmutable::class, $control->actualizado_en);
    }

    public function test_root_seeder_loads_only_the_approved_initial_catalogs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['Administrador', 'Recepcionista', 'Médico', 'Paciente'],
            Rol::query()->pluck('nombre')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Pediatría', 'Medicina General', 'Geriatría'],
            Especialidad::query()->pluck('nombre')->all(),
        );
        $this->assertSame('Clínica Vida Salud', ConfiguracionClinica::query()->findOrFail(1)->nombre_sede);
        $this->assertSame(1, ConfiguracionClinica::query()->count());
        $this->assertSame(3, Consultorio::query()->count());
        $this->assertSame(0, ControlIntentos::query()->count());
    }

    public function test_reseeding_preserves_ids_inactive_catalogs_and_changed_configuration(): void
    {
        $this->seed(DatabaseSeeder::class);

        $specialty = Especialidad::query()->where('nombre', 'Pediatría')->firstOrFail();
        $specialty->activa = false;
        $specialty->descripcion = 'Descripción ficticia preservada';
        $specialty->save();

        $config = ConfiguracionClinica::query()->findOrFail(1);
        $config->duracion_turno_minutos = 30;
        $config->nombre_sede = 'Nombre configurado';
        $config->direccion_sede = 'Dirección ficticia';
        $config->save();

        $before = $this->catalogSnapshot();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $this->catalogSnapshot());
    }

    public function test_seeding_reuses_a_normalized_specialty_without_reactivating_it(): void
    {
        $specialty = new Especialidad(['nombre' => '  PEDIATRÍA  ', 'descripcion' => 'Registro previo ficticio']);
        $specialty->activa = false;
        $specialty->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Especialidad::query()->count());
        $specialty->refresh();
        $this->assertFalse($specialty->activa);
        $this->assertSame('  PEDIATRÍA  ', $specialty->nombre);
        $this->assertSame('Registro previo ficticio', $specialty->descripcion);
    }

    public function test_seed_failure_rolls_back_all_initial_catalog_inserts(): void
    {
        DB::statement('ALTER TABLE configuracion_clinica ADD CONSTRAINT seed_failure_ck CHECK (false) NOT VALID');

        $this->assertRejected(
            fn () => $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->run(),
            '23514',
            'seed_failure_ck',
        );

        $this->assertSame(0, Rol::query()->count());
        $this->assertSame(0, Especialidad::query()->count());
        $this->assertSame(0, ConfiguracionClinica::query()->count());
    }

    public function test_models_preserve_instants_and_update_only_the_existing_timestamp(): void
    {
        $instant = CarbonImmutable::parse('2026-10-19T08:00:00.123456-04:00');
        $this->travelTo($instant);

        $control = ControlIntentos::query()->create([
            'clave_ambito_hash' => str_repeat('b', 64),
            'inicio_ventana' => $instant,
        ]);
        $control->refresh();

        $this->assertSame($instant->format('U.u'), $control->inicio_ventana->format('U.u'));
        $this->assertSame($instant->format('U.u'), $control->actualizado_en->format('U.u'));
        $this->assertArrayNotHasKey('clave_ambito_hash', $control->toArray());

        $this->travel(2)->seconds();
        $control->cantidad_intentos = 1;
        $control->bloqueado_hasta = $instant->addMinutes(10);
        $control->save();
        $control->refresh();

        $this->assertSame(1, $control->cantidad_intentos);
        $this->assertSame($instant->addMinutes(10)->format('U.u'), $control->bloqueado_hasta->format('U.u'));
        $this->assertTrue($control->actualizado_en->greaterThan($instant));

        $config = ConfiguracionClinica::query()->create(['nombre_sede' => 'Sede ficticia']);
        $firstUpdate = $config->actualizado_en;
        $this->travel(2)->seconds();
        $config->update(['duracion_turno_minutos' => 30]);

        $this->assertSame(1, $config->getKey());
        $this->assertTrue($config->fresh()->actualizado_en->greaterThan($firstUpdate));
    }

    public function test_mass_assignment_cannot_set_ids_states_or_blocking_fields(): void
    {
        $specialty = new Especialidad(['nombre' => 'Ejemplo', 'id_especialidad' => 900, 'activa' => false]);
        $this->assertNull($specialty->getKey());
        $this->assertTrue($specialty->activa);

        $room = new Consultorio(['id_consultorio' => 900, 'activo' => false]);
        $this->assertNull($room->getKey());
        $this->assertTrue($room->activo);

        $config = new ConfiguracionClinica(['id_configuracion' => 2, 'zona_horaria' => 'UTC']);
        $this->assertSame(1, $config->getKey());
        $this->assertSame('America/La_Paz', $config->zona_horaria);

        $control = new ControlIntentos(['id_control' => 900, 'cantidad_intentos' => 99, 'bloqueado_hasta' => now()]);
        $this->assertNull($control->getKey());
        $this->assertSame(0, $control->cantidad_intentos);
        $this->assertNull($control->bloqueado_hasta);
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_writes_even_without_model_validation(
        string $table,
        array $values,
        string $sqlState,
        ?string $constraint,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $this->assertRejected(function () use ($table, $values): void {
            if ($table === 'configuracion_clinica') {
                DB::table($table)->where('id_configuracion', 1)->update($values);

                return;
            }

            $defaults = match ($table) {
                'consultorios' => ['codigo_consultorio' => 'TEST-01', 'nombre' => 'Consultorio ficticio'],
                'controles_intentos' => ['clave_ambito_hash' => str_repeat('a', 64), 'inicio_ventana' => '2026-10-19T12:00:00Z'],
                default => [],
            };

            DB::table($table)->insert(array_replace($defaults, $values));
        }, $sqlState, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'quinto rol' => ['roles', ['nombre' => 'Supervisor'], '23514', 'roles_nombre_ck'],
            'rol duplicado' => ['roles', ['nombre' => 'Administrador'], '23505', 'roles_nombre_uq'],
            'rol nulo' => ['roles', ['nombre' => null], '23502', null],
            'contador negativo' => ['controles_intentos', ['cantidad_intentos' => -1], '23514', 'controles_intentos_cantidad_ck'],
            'ventana nula' => ['controles_intentos', ['inicio_ventana' => null], '23502', null],
            'codigo vacio' => ['consultorios', ['codigo_consultorio' => '   '], '23514', 'consultorios_codigo_ck'],
            'consultorio sin nombre' => ['consultorios', ['nombre' => '   '], '23514', 'consultorios_nombre_ck'],
            'codigo demasiado largo' => ['consultorios', ['codigo_consultorio' => str_repeat('A', 51)], '22001', null],
            'especialidad duplicada normalizada' => ['especialidades', ['nombre' => '  pEDIATRÍA  '], '23505', 'especialidades_nombre_normalizado_uq'],
            'especialidad vacia' => ['especialidades', ['nombre' => '   '], '23514', 'especialidades_nombre_ck'],
            'especialidad demasiado larga' => ['especialidades', ['nombre' => str_repeat('A', 121)], '22001', null],
            'segunda configuracion' => ['configuracion_clinica', ['id_configuracion' => 2], '23514', 'configuracion_clinica_unica_ck'],
            'otra zona horaria' => ['configuracion_clinica', ['zona_horaria' => 'UTC'], '23514', 'configuracion_clinica_zona_ck'],
            'duracion cero' => ['configuracion_clinica', ['duracion_turno_minutos' => 0], '23514', 'configuracion_clinica_duracion_ck'],
            'duracion negativa' => ['configuracion_clinica', ['duracion_turno_minutos' => -15], '23514', 'configuracion_clinica_duracion_ck'],
            'sede sin nombre' => ['configuracion_clinica', ['nombre_sede' => '  '], '23514', 'configuracion_clinica_nombre_ck'],
        ];
    }

    public function test_unique_keys_do_not_impose_extra_uniqueness_on_room_names(): void
    {
        $room = Consultorio::query()->create(['codigo_consultorio' => 'TEST-01', 'nombre' => 'Nombre ficticio']);
        Consultorio::query()->create(['codigo_consultorio' => 'TEST-02', 'nombre' => $room->nombre]);
        $this->assertSame(2, Consultorio::query()->count());

        $this->assertRejected(
            fn () => DB::table('consultorios')->insert(['codigo_consultorio' => $room->codigo_consultorio, 'nombre' => 'Otro']),
            '23505',
            'consultorios_codigo_uq',
        );

        $row = ['clave_ambito_hash' => str_repeat('c', 64), 'inicio_ventana' => '2026-10-19T12:00:00Z'];
        DB::table('controles_intentos')->insert($row);
        $this->assertRejected(fn () => DB::table('controles_intentos')->insert($row), '23505', 'controles_intentos_clave_ambito_hash_uq');
    }

    public function test_normalization_preserves_accents_and_matches_php_for_catalog_names(): void
    {
        foreach (['  PEDIATRÍA  ', 'Medicina General', ' GERIATRÍA '] as $name) {
            $normalized = DB::selectOne('SELECT lower(btrim(CAST(? AS text))) AS nombre', [$name])->nombre;
            $this->assertSame(mb_strtolower(trim($name, ' '), 'UTF-8'), $normalized);
        }

        Especialidad::query()->create(['nombre' => 'Pediatría']);
        Especialidad::query()->create(['nombre' => 'Pediatria']);
        $this->assertSame(2, Especialidad::query()->count());
    }

    public function test_migrations_can_be_reversed_and_reapplied_only_inside_the_test_transaction(): void
    {
        $this->artisan('migrate:rollback', ['--step' => DB::table('migrations')->count(), '--force' => true])->assertSuccessful();

        foreach (['roles', 'controles_intentos', 'consultorios', 'especialidades', 'configuracion_clinica'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(4, Rol::query()->count());
    }

    private function assertRejected(callable $write, string $sqlState, ?string $constraint = null): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL aceptó una escritura que debía rechazar.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->errorInfo[2]);
            }
        }
    }

    private function catalogSnapshot(): array
    {
        return [
            DB::table('roles')->orderBy('id_rol')->get()->toJson(),
            DB::table('especialidades')->orderBy('id_especialidad')->get()->toJson(),
            DB::table('configuracion_clinica')->orderBy('id_configuracion')->get()->toJson(),
        ];
    }
}
