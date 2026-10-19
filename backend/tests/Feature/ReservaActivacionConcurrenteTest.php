<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservaActivacionConcurrenteTest extends TestCase
{
    public function test_independent_postgresql_connections_serialize_open_reservations_on_all_four_keys(): void
    {
        // Esquema desechable únicamente en la base/rol de pruebas protegidos por TestCase.
        $schema = 'prueba_reserva_'.strtolower(Str::random(16));
        $primary = DB::connection();
        $previousPath = $primary->selectOne('SHOW search_path')->search_path;
        $secondary = null;
        $primary->statement('CREATE SCHEMA "'.$schema.'"');

        try {
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $schema.', pg_temp']);
            foreach (['000001_create_roles', '000006_create_usuarios', '000010_create_pacientes', '000013_create_codigos_activacion_paciente', '000014_create_registros_paciente_pendientes'] as $name) {
                $migration = require base_path('src/data/database/migrations/2026_10_19_'.$name.'_table.php');
                $migration->up();
            }

            config(['database.connections.reserva_concurrente' => array_replace(config('database.connections.pgsql'), ['search_path' => $schema.', pg_temp'])]);
            $secondary = DB::connection('reserva_concurrente');
            $secondary->statement("SET lock_timeout = '150ms'");
            $this->assertNotSame($primary->selectOne('SELECT pg_backend_pid() AS pid')->pid, $secondary->selectOne('SELECT pg_backend_pid() AS pid')->pid);

            $role = $primary->table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol');
            $issuer = $primary->table('usuarios')->insertGetId(['id_rol' => $role, 'correo_acceso' => 'emisor@example.test'], 'id_usuario');
            $patient = $primary->table('pacientes')->insertGetId(['codigo_paciente' => 'PRUEBA-1', 'nombres' => 'Paciente ficticio', 'apellidos' => 'De prueba', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
            $otherPatient = $primary->table('pacientes')->insertGetId(['codigo_paciente' => 'PRUEBA-2', 'nombres' => 'Otro paciente ficticio', 'apellidos' => 'De prueba', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
            $codes = [];
            for ($i = 0; $i < 2; $i++) {
                $codes[] = $primary->table('codigos_activacion_paciente')->insertGetId(['id_paciente' => $patient, 'id_recepcionista' => $issuer, 'codigo_hash' => hash('sha256', 'codigo ficticio '.$i), 'emitido_en' => '2026-10-19T12:00:00Z', 'expira_en' => '2026-10-20T12:00:00Z'], 'id_codigo_activacion');
            }

            foreach (['correo', 'documento', 'paciente', 'codigo'] as $key) {
                $first = ['tipo_registro' => 'Nuevo', 'correo_propuesto' => $key.'@example.test', 'password_hash_temporal' => 'resumen ficticio', 'datos_registro' => '{}', 'iniciado_en' => '2026-10-19T12:00:00Z', 'expira_en' => '2026-10-20T12:00:00Z'];
                if (in_array($key, ['paciente', 'codigo'], true)) {
                    $first = array_replace($first, ['tipo_registro' => 'Vinculacion', 'id_paciente' => $patient, 'id_codigo_activacion' => $codes[0]]);
                }
                if ($key === 'documento') {
                    $first['documento_identidad'] = ' 00123-A ';
                }
                $second = array_replace($first, ['correo_propuesto' => 'otro-'.$key.'@example.test']);
                $second = array_replace($second, match ($key) {
                    'correo' => ['correo_propuesto' => ' CORREO@EXAMPLE.TEST '],
                    'documento' => ['documento_identidad' => '00123-A'],
                    'paciente' => ['id_codigo_activacion' => $codes[1]],
                    'codigo' => ['id_paciente' => $otherPatient],
                });

                $primary->beginTransaction();
                $id = $primary->table('registros_paciente_pendientes')->insertGetId($first, 'id_registro_pendiente');
                try {
                    $secondary->table('registros_paciente_pendientes')->insert($second);
                    $this->fail('La reserva concurrente no esperó la primera transacción: '.$key);
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0], $key);
                }
                $primary->commit();
                try {
                    $secondary->table('registros_paciente_pendientes')->insert($second);
                    $this->fail('La segunda reserva no fue rechazada: '.$key);
                } catch (QueryException $exception) {
                    $this->assertSame('23505', $exception->errorInfo[0], $key);
                    $this->assertStringContainsString('registros_pendientes_'.$key.'_abierto_uq', $exception->errorInfo[2]);
                }
                $primary->table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['invalidado_en' => now()]);

                $primary->beginTransaction();
                $primary->table('registros_paciente_pendientes')->insert($first);
                try {
                    $secondary->table('registros_paciente_pendientes')->insert($second);
                    $this->fail('La reserva concurrente no esperó el rollback: '.$key);
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0], $key);
                }
                $primary->rollBack();
                $this->assertTrue($secondary->table('registros_paciente_pendientes')->insert($second));
                $secondary->table('registros_paciente_pendientes')->whereNull('invalidado_en')->update(['invalidado_en' => now()]);
            }
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('reserva_concurrente');
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $previousPath]);
            $primary->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
