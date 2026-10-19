<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class LaboratorioConcurrenteTest extends TestCase
{
    use LaboratorioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_laboratorio_'.strtolower(Str::random(16));
        $primary = DB::connection();
        $previousPath = $primary->selectOne('SHOW search_path')->search_path;
        $hadExtension = (int) $primary->selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'btree_gist'")->total > 0;
        $primary->statement('CREATE SCHEMA "'.$schema.'"');
        $this->beforeApplicationDestroyed(function () use ($primary, $previousPath, $schema, $hadExtension): void {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('laboratorio_concurrente');
            $primary->select('SELECT set_config(?, ?, false)', ['search_path', $previousPath]);
            $primary->statement('DROP SCHEMA "'.$schema.'" CASCADE');
            if (! $hadExtension) {
                $primary->statement('DROP EXTENSION IF EXISTS btree_gist');
            }
        });
        $path = $schema.', public, pg_temp';
        $primary->select('SELECT set_config(?, ?, false)', ['search_path', $path]);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            $migration = require $file;
            $migration->up();
        }
        config(['database.connections.laboratorio_concurrente' => array_replace(config('database.connections.pgsql'), ['search_path' => $path])]);
        $secondary = DB::connection('laboratorio_concurrente');
        $secondary->statement("SET lock_timeout = '150ms'");
        $this->assertSame($schema, $secondary->selectOne('SELECT current_schema() AS name')->name);
        $this->assertNotSame($primary->selectOne('SELECT pg_backend_pid() AS pid')->pid, $secondary->selectOne('SELECT pg_backend_pid() AS pid')->pid);
    }

    #[DataProvider('uniqueScopes')]
    public function test_competing_version_numbers_and_document_paths_wait_then_obey_commit_or_rollback(string $table, string $constraint): void
    {
        $context = $this->laboratorioContext();
        $primary = DB::connection();
        $secondary = DB::connection('laboratorio_concurrente');
        $first = $table === 'versiones_resultado' ? $this->versionResultadoRow($context) : $this->documentoRow($context['admin']);
        $second = $first;
        if ($table === 'versiones_resultado') {
            $second['motivo_cambio'] = 'Versión competidora ficticia';
        } else {
            $second['nombre_original'] = 'documento-competidor-ficticio.pdf';
        }
        $primary->beginTransaction();
        $primary->table($table)->insert($first);
        $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '55P03');
        $primary->commit();
        $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '23505', $constraint);
        if ($table === 'versiones_resultado') {
            $this->assertNull($secondary->table($table)->where('numero_version', 1)->value('motivo_cambio'));
            $first['numero_version'] = $second['numero_version'] = 2;
        } else {
            $this->assertSame($first['nombre_original'], $secondary->table($table)->where('ruta_privada', $first['ruta_privada'])->value('nombre_original'));
            $first['ruta_privada'] = $second['ruta_privada'] = 'laboratorio/prueba-'.Str::uuid().'.pdf';
        }
        $primary->beginTransaction();
        $primary->table($table)->insert($first);
        $this->assertSqlState(fn () => $secondary->table($table)->insert($second), '55P03');
        $primary->rollBack();
        $this->assertTrue($secondary->table($table)->insert($second));
        $this->assertSame($table === 'versiones_resultado' ? 2 : 3, $secondary->table($table)->count());
    }

    public static function uniqueScopes(): array
    {
        return [
            ['versiones_resultado', 'versiones_resultado_numero_uq'],
            ['documentos_laboratorio', 'documentos_laboratorio_ruta_uq'],
        ];
    }

    #[DataProvider('publicationRaces')]
    public function test_conditional_writes_revalidate_the_reviewed_version_and_publication_state(bool $correctionFirst, bool $commit): void
    {
        $context = $this->laboratorioContext();
        $old = $this->resultadoEnRevision($context);
        $primary = DB::connection();
        $secondary = DB::connection('laboratorio_concurrente');
        $document = $primary->table('documentos_laboratorio')->insertGetId($this->documentoRow($context['admin']), 'id_documento');
        $new = $primary->table('versiones_resultado')->insertGetId($this->versionResultadoRow($context, ['numero_version' => 2, 'id_documento' => $document, 'id_version_origen' => $old]), 'id_version_resultado');
        $review = $primary->table('revisiones_resultado')->insertGetId($this->revisionRow($context, $old), 'id_revision');
        $publication = ['estado_interno' => 'Disponible', 'id_revision_publicada' => $review];
        $correction = ['id_version_actual' => $new];
        // Protocolo SQL de persistencia: no representa un Service ni autorización terminados.
        $write = fn ($connection, array $values) => $connection->table('solicitudes_laboratorio')
            ->where('id_solicitud', $context['request'])
            ->where('estado_interno', 'En revisión')
            ->where('id_version_actual', $old)
            ->whereNull('id_revision_publicada')
            ->update($values);

        $primary->beginTransaction();
        $this->assertSame(1, $write($primary, $correctionFirst ? $correction : $publication));
        $competing = fn () => $write($secondary, $correctionFirst ? $publication : $correction);
        $this->assertSqlState($competing, '55P03');
        if ($commit) {
            $primary->commit();
        } else {
            $primary->rollBack();
        }
        $this->assertSame($commit ? 0 : 1, $competing());

        $published = $correctionFirst !== $commit;
        $request = $secondary->table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first();
        $this->assertSame($published ? 'Disponible' : 'En revisión', $request->estado_interno);
        $this->assertSame($published ? $old : $new, $request->id_version_actual);
        $this->assertSame($published ? $review : null, $request->id_revision_publicada);
        $this->assertSame($old, $secondary->table('revisiones_resultado')->where('id_revision', $review)->value('id_version_resultado'));
        $this->assertSame($context['document'], $secondary->table('versiones_resultado')->where('id_version_resultado', $old)->value('id_documento'));
        $this->assertSame($old, $secondary->table('versiones_resultado')->where('id_version_resultado', $new)->value('id_version_origen'));
        if ($published) {
            $this->assertSqlState(fn () => $secondary->table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_version_actual' => $new]), '23514', 'solicitudes_laboratorio_publicada_ck');
        } else {
            $this->assertSqlState(fn () => $secondary->table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update($publication), '23503', 'solicitudes_laboratorio_revision_fk');
        }
    }

    public static function publicationRaces(): array
    {
        return [
            'correccion confirmada' => [true, true],
            'publicacion confirmada' => [false, true],
            'correccion revertida' => [true, false],
            'publicacion revertida' => [false, false],
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
