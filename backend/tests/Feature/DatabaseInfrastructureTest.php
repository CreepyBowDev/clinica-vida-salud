<?php

namespace Tests\Feature;

use Clinica\data\database\seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseInfrastructureTest extends TestCase
{
    public function test_postgresql_connection_uses_an_isolated_unprivileged_owner(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_database() AS database, current_user AS username,
                   r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls,
                   pg_get_userbyid(d.datdba) AS owner,
                   current_setting('TimeZone') AS timezone
            FROM pg_roles r
            JOIN pg_database d ON d.datname = current_database()
            WHERE r.rolname = current_user
            SQL);

        $this->assertSame('clinica_vida_salud_test', $identity->database);
        $this->assertSame('clinica_vida_salud_test', $identity->username);
        $this->assertSame($identity->username, $identity->owner);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolcreatedb);
        $this->assertFalse($identity->rolcreaterole);
        $this->assertFalse($identity->rolreplication);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertSame('UTC', $identity->timezone);
    }

    public function test_artisan_migration_and_seeder_work_with_transactional_postgresql_ddl(): void
    {
        $migrator = $this->app->make('migrator');

        $this->assertContains(base_path('src/data/database/migrations'), $migrator->paths());

        $tablesBefore = Schema::getTableListing();
        $migrator->path(base_path('tests/Fixtures/database/migrations'));

        DB::beginTransaction();

        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();

            $this->assertTrue(Schema::hasTable('infrastructure_probe'));
            $this->assertTrue(DB::table('migrations')
                ->where('migration', '2026_10_19_000000_create_infrastructure_probe_table')
                ->exists());

            DB::table('infrastructure_probe')->insert([
                'valor' => 'fase_0',
                'registrado_en' => '2026-10-19 08:00:00.123456-04:00',
            ]);

            $instant = DB::table('infrastructure_probe')->value('registrado_en');
            $this->assertSame('2026-10-19 12:00:00.123456+00', $instant);

            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
                ->assertSuccessful();
        } finally {
            DB::rollBack();
        }

        $this->assertSame($tablesBefore, Schema::getTableListing());
    }
}
