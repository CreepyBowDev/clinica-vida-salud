<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class CitasConcurrentesTest extends TestCase
{
    use CitasFixtures;

    #[DataProvider('resources')]
    public function test_independent_connections_protect_reservations_rescheduling_and_notice_idempotency(string $resource): void
    {
        $schema = 'prueba_citas_'.strtolower(Str::random(16));
        $primary = DB::connection();
        $previousPath = $primary->selectOne('SHOW search_path')->search_path;
        $hadExtension = (int) $primary->selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'btree_gist'")->total > 0;
        $primary->statement('CREATE SCHEMA "'.$schema.'"');

        try {
            $path = $schema.', public, pg_temp';
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $path]);
            foreach (['000001_create_roles', '000003_create_consultorios', '000004_create_especialidades', '000006_create_usuarios', '000010_create_pacientes', '000016_create_medicos', '000017_create_medico_especialidad', '000019_create_citas', '000020_create_cambios_cita', '000021_create_recordatorios'] as $name) {
                $migration = require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php');
                $migration->up();
            }
            config(['database.connections.citas_concurrentes' => array_replace(config('database.connections.pgsql'), ['search_path' => $path])]);
            $secondary = DB::connection('citas_concurrentes');
            $secondary->statement("SET lock_timeout = '150ms'");
            $this->assertNotSame($primary->selectOne('SELECT pg_backend_pid() AS pid')->pid, $secondary->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $resources = $this->citaResources();
            $otherResource = $resource === 'medico' ? ['id_consultorio' => $resources['rooms'][1]] : ['id_medico' => $resources['doctors'][1]];
            $first = $this->citaRow($resources);
            $second = $this->citaRow($resources, array_replace($otherResource, ['inicio_at' => '2026-11-01T12:05:00Z', 'fin_at' => '2026-11-01T12:20:00Z']));

            $primary->beginTransaction();
            $id = $primary->table('citas')->insertGetId($first, 'id_cita');
            $this->assertSqlState(fn () => $secondary->table('citas')->insert($second), '55P03');
            $primary->commit();
            $this->assertSqlState(fn () => $secondary->table('citas')->insert($second), '23P01', 'citas_'.$resource.'_no_solapado_excl');
            $primary->table('citas')->where('id_cita', $id)->update(['estado' => 'Cancelada']);

            $first['codigo_cita'] = 'PRUEBA-'.Str::random(16);
            $primary->beginTransaction();
            $primary->table('citas')->insert($first);
            $this->assertSqlState(fn () => $secondary->table('citas')->insert($second), '55P03');
            $primary->rollBack();
            $this->assertTrue($secondary->table('citas')->insert($second));
            $secondary->table('citas')->where('codigo_cita', $second['codigo_cita'])->update(['estado' => 'Cancelada']);

            $firstId = $primary->table('citas')->insertGetId($this->citaRow($resources, ['inicio_at' => '2026-11-01T09:00:00Z', 'fin_at' => '2026-11-01T09:15:00Z']), 'id_cita');
            $secondId = $primary->table('citas')->insertGetId($this->citaRow($resources, array_replace($otherResource, ['inicio_at' => '2026-11-01T10:00:00Z', 'fin_at' => '2026-11-01T10:15:00Z'])), 'id_cita');
            $before = $secondary->table('citas')->where('id_cita', $secondId)->first();
            $newInterval = ['inicio_at' => '2026-11-01T14:05:00Z', 'fin_at' => '2026-11-01T14:20:00Z', 'version_programacion' => 2];
            $primary->beginTransaction();
            $primary->table('citas')->where('id_cita', $firstId)->update(['inicio_at' => '2026-11-01T14:00:00Z', 'fin_at' => '2026-11-01T14:15:00Z', 'version_programacion' => 2]);
            $this->assertSqlState(fn () => $secondary->table('citas')->where('id_cita', $secondId)->update($newInterval), '55P03');
            $primary->commit();
            $this->assertSqlState(fn () => $secondary->table('citas')->where('id_cita', $secondId)->update($newInterval), '23P01', 'citas_'.$resource.'_no_solapado_excl');
            $this->assertEquals($before, $secondary->table('citas')->where('id_cita', $secondId)->first());

            $notice = ['id_cita' => $firstId, 'version_programacion' => 2, 'generado_en' => '2026-10-31T14:00:00Z'];
            $primary->beginTransaction();
            $primary->table('recordatorios')->insert($notice);
            $this->assertSqlState(fn () => $secondary->table('recordatorios')->insert($notice), '55P03');
            $primary->commit();
            $this->assertSqlState(fn () => $secondary->table('recordatorios')->insert($notice), '23505', 'recordatorios_cita_version_uq');
            $this->assertSame(1, $secondary->table('recordatorios')->where('id_cita', $firstId)->count());
            $this->assertSame($schema, $secondary->selectOne('SELECT current_schema() AS name')->name);
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('citas_concurrentes');
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $previousPath]);
            $primary->statement('DROP SCHEMA "'.$schema.'" CASCADE');
            // Solo retira la extensión si fue creada por esta prueba, sin CASCADE.
            if (! $hadExtension) {
                $primary->statement('DROP EXTENSION IF EXISTS btree_gist');
            }
        }
    }

    public static function resources(): array
    {
        return [['medico'], ['consultorio']];
    }

    private function assertSqlState(callable $write, string $state, ?string $constraint = null): void
    {
        try {
            $write();
            $this->fail('PostgreSQL aceptó una escritura que debía bloquear/rechazar.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->errorInfo[2]);
            }
        }
    }
}
