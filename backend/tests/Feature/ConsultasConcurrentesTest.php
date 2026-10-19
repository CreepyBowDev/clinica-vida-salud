<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ConsultasFixtures;
use Tests\TestCase;

class ConsultasConcurrentesTest extends TestCase
{
    use ConsultasFixtures;

    #[DataProvider('uniqueScopes')]
    public function test_independent_connections_reject_competing_consultations_and_version_numbers(string $table, string $constraint): void
    {
        $schema = 'prueba_clinica_'.strtolower(Str::random(16));
        $primary = DB::connection();
        $previousPath = $primary->selectOne('SHOW search_path')->search_path;
        $hadExtension = (int) $primary->selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'btree_gist'")->total > 0;
        $primary->statement('CREATE SCHEMA "'.$schema.'"');

        try {
            $path = $schema.', public, pg_temp';
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $path]);
            foreach (['000001_create_roles', '000003_create_consultorios', '000004_create_especialidades', '000006_create_usuarios', '000010_create_pacientes', '000016_create_medicos', '000017_create_medico_especialidad', '000019_create_citas', '000022_create_consultas_medicas', '000023_create_versiones_consulta', '000024_create_versiones_antecedente'] as $name) {
                $migration = require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php');
                $migration->up();
            }
            config(['database.connections.consultas_concurrentes' => array_replace(config('database.connections.pgsql'), ['search_path' => $path])]);
            $secondary = DB::connection('consultas_concurrentes');
            $secondary->statement("SET lock_timeout = '150ms'");
            $this->assertNotSame($primary->selectOne('SELECT pg_backend_pid() AS pid')->pid, $secondary->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $context = $this->consultaContext();
            $otherAppointment = $primary->table('citas')->insertGetId($this->citaRow($context, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
            $first = match ($table) {
                'consultas_medicas' => $this->consultaRow($otherAppointment),
                'versiones_consulta' => $this->versionConsultaRow($context, ['sintomas' => 'Contenido ficticio primero']),
                'versiones_antecedente' => $this->versionAntecedenteRow($context, ['alergias' => 'Contenido ficticio primero']),
            };
            $second = match ($table) {
                'consultas_medicas' => $this->consultaRow($otherAppointment),
                'versiones_consulta' => array_replace($first, ['sintomas' => 'Contenido ficticio segundo']),
                'versiones_antecedente' => array_replace($first, ['alergias' => 'Contenido ficticio segundo', 'id_consulta_origen' => $primary->table('consultas_medicas')->insertGetId($this->consultaRow($otherAppointment), 'id_consulta')]),
            };

            $primary->beginTransaction();
            $primary->table($table)->insert($first);
            $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '55P03');
            $primary->commit();
            $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '23505', $constraint);
            if ($table === 'consultas_medicas') {
                $this->assertSame(1, $secondary->table($table)->where('id_cita', $otherAppointment)->count());
                $nextAppointment = $primary->table('citas')->insertGetId($this->citaRow($context, ['inicio_at' => '2026-11-01T14:00:00Z', 'fin_at' => '2026-11-01T14:15:00Z']), 'id_cita');
                $first = $this->consultaRow($nextAppointment);
                $second = $this->consultaRow($nextAppointment);
            } else {
                $field = $table === 'versiones_consulta' ? 'sintomas' : 'alergias';
                $this->assertSame('Contenido ficticio primero', $secondary->table($table)->where('numero_version', 1)->value($field));
                $first['numero_version'] = 2;
                $second['numero_version'] = 2;
            }

            $primary->beginTransaction();
            $primary->table($table)->insert($first);
            $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '55P03');
            $primary->rollBack();
            $this->assertTrue($secondary->table($table)->insert($second));
            if ($table !== 'consultas_medicas') {
                $this->assertSame(2, $secondary->table($table)->count());
                $this->assertSame('Contenido ficticio segundo', $secondary->table($table)->where('numero_version', 2)->value($field));
            }
            $this->assertSame('Programada', $secondary->table('citas')->where('id_cita', $context['appointment'])->value('estado'));
            $this->assertNull($secondary->table('consultas_medicas')->where('id_consulta', $context['consultation'])->value('iniciada_en'));
            $this->assertSame($schema, $secondary->selectOne('SELECT current_schema() AS name')->name);
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('consultas_concurrentes');
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $previousPath]);
            $primary->statement('DROP SCHEMA "'.$schema.'" CASCADE');
            if (! $hadExtension) {
                $primary->statement('DROP EXTENSION IF EXISTS btree_gist');
            }
        }
    }

    public static function uniqueScopes(): array
    {
        return [
            ['consultas_medicas', 'consultas_medicas_cita_uq'],
            ['versiones_consulta', 'versiones_consulta_numero_uq'],
            ['versiones_antecedente', 'versiones_antecedente_numero_uq'],
        ];
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
