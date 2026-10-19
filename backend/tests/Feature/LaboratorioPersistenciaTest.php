<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\DocumentoLaboratorio;
use Clinica\data\models\Medico;
use Clinica\data\models\RevisionResultado;
use Clinica\data\models\SolicitudLaboratorio;
use Clinica\data\models\Usuario;
use Clinica\data\models\VersionResultado;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\LaboratorioFixtures;
use Tests\TestCase;

class LaboratorioPersistenciaTest extends TestCase
{
    use LaboratorioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_dictionary_columns_types_and_ten_restrictive_foreign_keys_are_preserved(): void
    {
        $tables = [
            'solicitudes_laboratorio' => ['id_solicitud', 'id_consulta', 'estudio_solicitado', 'solicitada_en', 'estado_interno', 'id_version_actual', 'id_revision_publicada'],
            'documentos_laboratorio' => ['id_documento', 'cargado_por', 'ruta_privada', 'nombre_original', 'tipo_mime', 'tamano_bytes', 'sha256', 'cargado_en'],
            'versiones_resultado' => ['id_version_resultado', 'id_solicitud', 'id_documento', 'numero_version', 'id_version_origen', 'id_autor', 'registrada_en', 'motivo_cambio'],
            'revisiones_resultado' => ['id_revision', 'id_version_resultado', 'id_medico', 'decision', 'motivo', 'revisada_en'],
        ];
        $count = 0;
        foreach ($tables as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
            $this->assertSame($table.'_pk', collect(Schema::getIndexes($table))->firstWhere('primary', true)['name']);
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $this->assertSame('restrict', $foreign['on_delete']);
                $this->assertSame('restrict', $foreign['on_update']);
                $count++;
            }
        }
        $this->assertSame(10, $count);
        $keys = collect(Schema::getForeignKeys('solicitudes_laboratorio'))->keyBy('name');
        $this->assertSame(['id_solicitud', 'id_version_actual'], $keys['solicitudes_laboratorio_version_fk']['columns']);
        $this->assertSame(['id_solicitud', 'id_version_resultado'], $keys['solicitudes_laboratorio_version_fk']['foreign_columns']);
        $this->assertSame(['id_revision_publicada', 'id_version_actual'], $keys['solicitudes_laboratorio_revision_fk']['columns']);
        $this->assertSame(['id_revision', 'id_version_resultado'], $keys['solicitudes_laboratorio_revision_fk']['foreign_columns']);
        foreach ($tables as $table => $columns) {
            $metadata = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($columns as $column) {
                if (str_starts_with($column, 'id_') || in_array($column, ['tamano_bytes', 'cargado_por'], true)) {
                    $this->assertSame('int8', $metadata[$column]['type_name']);
                }
                if (str_ends_with($column, '_en')) {
                    $this->assertSame('timestamptz', $metadata[$column]['type_name']);
                    $this->assertFalse($metadata[$column]['nullable']);
                }
            }
        }
    }

    public function test_direct_authorization_can_publish_the_exact_version_without_prior_return(): void
    {
        $context = $this->laboratorioContext();
        $pending = SolicitudLaboratorio::query()->findOrFail($context['request']);
        $this->assertSame('Pendiente', $pending->estado_interno);
        $this->assertNull($pending->versionActual);
        $this->assertNull($pending->revisionPublicada);
        $version = $this->resultadoEnRevision($context);
        $review = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $version), 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]);

        $request = $pending->fresh();
        $this->assertSame('Disponible', $request->estado_interno);
        $this->assertSame($version, $request->versionActual->getKey());
        $this->assertSame($review, $request->revisionPublicada->getKey());
        $this->assertSame('Autorizar', $request->revisionPublicada->decision);
        $this->assertSame($request->getKey(), $request->versionActual->solicitud->getKey());
        $this->assertSame($request->getKey(), $request->versionActual->solicitudActual->getKey());
        $this->assertSame($request->getKey(), $request->revisionPublicada->solicitudPublicada->getKey());
        $this->assertSame($version, $request->revisionPublicada->version->getKey());
        $this->assertSame($context['doctors'][0], $request->revisionPublicada->medico->getKey());
        $this->assertSame($context['admin'], $request->versionActual->autor->getKey());
        $this->assertSame($context['document'], $request->versionActual->documento->getKey());
        $this->assertSame($context['admin'], $request->versionActual->documento->cargadoPor->getKey());
        $this->assertSame($version, $request->versionActual->documento->versiones->sole()->getKey());
        $this->assertSame($review, $request->versionActual->revisiones->sole()->getKey());
        $this->assertSame($version, $request->versiones->sole()->getKey());
        $this->assertSame($request->getKey(), ConsultaMedica::query()->findOrFail($context['consultation'])->solicitudesLaboratorio->sole()->getKey());
        $this->assertSame($review, Medico::query()->findOrFail($context['doctors'][0])->revisionesResultados->sole()->getKey());
        $admin = Usuario::query()->findOrFail($context['admin']);
        $this->assertSame($context['document'], $admin->documentosLaboratorioCargados->sole()->getKey());
        $this->assertSame($version, $admin->versionesResultados->sole()->getKey());
        $this->assertSame('Programada', $request->consulta->cita->estado);
        $this->assertSame(0, DB::table('bitacora')->count());
    }

    public function test_return_replacement_and_new_review_preserve_old_document_and_review(): void
    {
        $context = $this->laboratorioContext();
        $old = $this->resultadoEnRevision($context);
        $returned = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $old, ['decision' => 'Devolver', 'motivo' => 'Asociación ficticia incorrecta']), 'id_revision');
        $document = DB::table('documentos_laboratorio')->insertGetId($this->documentoRow($context['admin']), 'id_documento');
        $new = DB::table('versiones_resultado')->insertGetId($this->versionResultadoRow($context, ['numero_version' => 2, 'id_documento' => $document, 'id_version_origen' => $old, 'motivo_cambio' => 'Corrección documental ficticia']), 'id_version_resultado');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_version_actual' => $new]);
        $request = SolicitudLaboratorio::query()->findOrFail($context['request']);
        $this->assertSame('En revisión', $request->estado_interno);
        $this->assertNull($request->revisionPublicada);
        $this->assertSame($old, $request->versionActual->origen->getKey());
        $this->assertSame($new, $request->versionActual->origen->derivadas->sole()->getKey());
        $this->assertSame($context['document'], VersionResultado::query()->findOrFail($old)->id_documento);
        $this->assertSame('Devolver', RevisionResultado::query()->findOrFail($returned)->decision);
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $returned]), '23503', 'solicitudes_laboratorio_revision_fk');
        $review = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $new), 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]);
        $this->assertSame($document, $request->fresh()->versionActual->id_documento);
        $this->assertSame(2, DocumentoLaboratorio::query()->count());
        $this->assertSame(2, RevisionResultado::query()->count());
    }

    public function test_compound_foreign_keys_reject_foreign_versions_and_reviews_even_in_the_same_consultation(): void
    {
        $context = $this->laboratorioContext();
        $first = $this->resultadoEnRevision($context);
        $other = DB::table('solicitudes_laboratorio')->insertGetId($this->solicitudRow($context), 'id_solicitud');
        $second = DB::table('versiones_resultado')->insertGetId($this->versionResultadoRow($context, ['id_solicitud' => $other]), 'id_version_resultado');
        $review = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $second), 'id_revision');
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_version_actual' => $second]), '23503', 'solicitudes_laboratorio_version_fk');
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]), '23503', 'solicitudes_laboratorio_revision_fk');
        $this->assertSame($first, SolicitudLaboratorio::query()->findOrFail($context['request'])->id_version_actual);
    }

    public function test_association_correction_can_trace_another_request_without_mutating_the_original(): void
    {
        $context = $this->laboratorioContext();
        $old = $this->resultadoEnRevision($context);
        DB::table('revisiones_resultado')->insert($this->revisionRow($context, $old, ['decision' => 'Devolver', 'motivo' => 'Corregir solicitud ficticia']));
        $target = DB::table('solicitudes_laboratorio')->insertGetId($this->solicitudRow($context), 'id_solicitud');
        DB::transaction(function () use ($context, $old, $target): void {
            $new = DB::table('versiones_resultado')->insertGetId($this->versionResultadoRow($context, ['id_solicitud' => $target, 'id_version_origen' => $old, 'motivo_cambio' => 'Asociación corregida ficticia']), 'id_version_resultado');
            DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_version_actual' => null]);
            DB::table('solicitudes_laboratorio')->where('id_solicitud', $target)->update(['estado_interno' => 'En revisión', 'id_version_actual' => $new]);
        });
        $this->assertSame($context['request'], VersionResultado::query()->findOrFail($old)->id_solicitud);
        $this->assertNull(SolicitudLaboratorio::query()->findOrFail($context['request'])->id_version_actual);
        $corrected = SolicitudLaboratorio::query()->findOrFail($target);
        $this->assertSame($old, $corrected->versionActual->id_version_origen);
        $this->assertSame($context['document'], $corrected->versionActual->id_documento);
        $this->assertNull($corrected->id_revision_publicada);
    }

    public function test_published_request_rejects_withdrawal_reassociation_replacement_and_any_other_update(): void
    {
        $context = $this->laboratorioContext();
        $version = $this->resultadoEnRevision($context);
        $review = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $version), 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]);
        $before = (array) DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first();
        foreach ([['estado_interno' => 'En revisión', 'id_revision_publicada' => null], ['id_version_actual' => null], ['id_consulta' => 9000], ['estudio_solicitado' => 'Alteración ficticia'], ['id_revision_publicada' => null]] as $values) {
            $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update($values), '23514', 'solicitudes_laboratorio_publicada_ck');
        }
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->delete(), '23514', 'solicitudes_laboratorio_publicada_ck');
        $model = SolicitudLaboratorio::query()->findOrFail($context['request']);
        $model->estudio_solicitado = 'Cambio ficticio';
        $this->assertRejected(fn () => $model->save(), '23514', 'solicitudes_laboratorio_publicada_ck');
        $this->assertSame($before, (array) DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first());
    }

    #[DataProvider('immutableTables')]
    public function test_document_version_and_review_are_immutable_in_sql_and_eloquent(string $table, string $class): void
    {
        $context = $this->laboratorioContext();
        $version = $this->resultadoEnRevision($context);
        DB::table('revisiones_resultado')->insert($this->revisionRow($context, $version));
        $column = match ($table) {
            'documentos_laboratorio' => 'nombre_original',
            'versiones_resultado' => 'motivo_cambio',
            'revisiones_resultado' => 'motivo',
        };
        $this->assertRejected(fn () => DB::table($table)->update([$column => 'Alteración ficticia']), '23514', $table.'_inmutable_ck');
        $this->assertRejected(fn () => DB::table($table)->delete(), '23514', $table.'_inmutable_ck');
        $this->assertRejected(fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'), '23514', $table.'_inmutable_ck');
        $model = $class::query()->sole();
        $model->{$column} = 'Alteración ficticia';
        $this->assertRejected(fn () => $model->save(), '23514', $table.'_inmutable_ck');
        $this->assertSame(1, DB::table($table)->count());
    }

    public static function immutableTables(): array
    {
        return [
            ['documentos_laboratorio', DocumentoLaboratorio::class],
            ['versiones_resultado', VersionResultado::class],
            ['revisiones_resultado', RevisionResultado::class],
        ];
    }

    public function test_failed_correction_rolls_back_new_metadata_version_and_request_pointers(): void
    {
        $context = $this->laboratorioContext();
        $old = $this->resultadoEnRevision($context);
        $before = (array) DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first();
        $this->assertRejected(function () use ($context, $old): void {
            $document = DB::table('documentos_laboratorio')->insertGetId($this->documentoRow($context['admin']), 'id_documento');
            $new = DB::table('versiones_resultado')->insertGetId($this->versionResultadoRow($context, ['numero_version' => 2, 'id_documento' => $document, 'id_version_origen' => $old]), 'id_version_resultado');
            DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_version_actual' => $new]);
            DB::table('revisiones_resultado')->insert($this->revisionRow($context, $new, ['decision' => 'Devolver', 'motivo' => null]));
        }, '23514', 'revisiones_resultado_motivo_ck');
        $this->assertSame($before, (array) DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first());
        $this->assertSame(1, DocumentoLaboratorio::query()->count());
        $this->assertSame(1, VersionResultado::query()->count());
        $this->assertSame(0, RevisionResultado::query()->count());
        $this->assertRejected(function () use ($context, $old): void {
            $review = DB::table('revisiones_resultado')->insertGetId($this->revisionRow($context, $old), 'id_revision');
            DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]);
            DB::table('revisiones_resultado')->insert($this->revisionRow($context, 9000));
        }, '23503', 'revisiones_resultado_version_fk');
        $this->assertSame($before, (array) DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->first());
        $this->assertSame(0, RevisionResultado::query()->count());
    }

    public function test_version_origin_cannot_self_reference_or_form_forward_cycles_in_multirow_insert(): void
    {
        $context = $this->laboratorioContext();
        $this->assertRejected(fn () => DB::table('versiones_resultado')->insert($this->versionResultadoRow($context, ['id_version_resultado' => 8000, 'id_version_origen' => 8000])), '23514', 'versiones_resultado_no_auto_ck');
        $this->assertRejected(fn () => DB::table('versiones_resultado')->insert([
            $this->versionResultadoRow($context, ['id_version_resultado' => 8000, 'id_version_origen' => 8001]),
            $this->versionResultadoRow($context, ['id_version_resultado' => 8001, 'id_version_origen' => 8000, 'numero_version' => 2]),
        ]), '23503', 'versiones_resultado_origen_fk');
        $this->assertSame(0, VersionResultado::query()->count());
    }

    public function test_unique_paths_and_version_numbers_allow_shared_documents_and_multiple_reviews(): void
    {
        $context = $this->laboratorioContext();
        $document = DocumentoLaboratorio::query()->findOrFail($context['document']);
        $this->assertRejected(fn () => DB::table('documentos_laboratorio')->insert($this->documentoRow($context['admin'], ['ruta_privada' => $document->ruta_privada])), '23505', 'documentos_laboratorio_ruta_uq');
        DB::table('documentos_laboratorio')->insert($this->documentoRow($context['admin'], ['sha256' => $document->sha256]));
        $version = $this->resultadoEnRevision($context);
        $this->assertRejected(fn () => DB::table('versiones_resultado')->insert($this->versionResultadoRow($context)), '23505', 'versiones_resultado_numero_uq');
        DB::table('versiones_resultado')->insert($this->versionResultadoRow($context, ['numero_version' => 2, 'id_version_origen' => $version]));
        DB::table('revisiones_resultado')->insert($this->revisionRow($context, $version, ['decision' => 'Devolver', 'motivo' => 'Revisar dato ficticio']));
        DB::table('revisiones_resultado')->insert($this->revisionRow($context, $version, ['decision' => 'Devolver', 'motivo' => 'Reiteración ficticia']));
        $this->assertSame(2, VersionResultado::query()->count());
        $this->assertSame(2, RevisionResultado::query()->count());
        $this->assertSame(2, DocumentoLaboratorio::query()->count());
    }

    public function test_consultation_request_and_account_references_cannot_be_deleted_or_rekeyed(): void
    {
        $context = $this->laboratorioContext();
        $this->resultadoEnRevision($context);
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $context['consultation'])->delete(), '23001', 'solicitudes_laboratorio_consulta_fk');
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $context['consultation'])->update(['id_consulta' => 9000]), '23001', 'solicitudes_laboratorio_consulta_fk');
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->delete(), '23001', 'versiones_resultado_solicitud_fk');
        $this->assertRejected(fn () => DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['id_solicitud' => 9000]), '23001');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $context['admin'])->delete(), '23001');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $context['admin'])->update(['id_usuario' => 9000]), '23001');
    }

    public function test_models_preserve_microseconds_hide_sensitive_metadata_and_guard_control_fields(): void
    {
        $context = $this->laboratorioContext();
        $date = CarbonImmutable::parse('2026-11-01T08:15:06.123456-04:00');
        $request = new SolicitudLaboratorio;
        $request->forceFill($this->solicitudRow($context, ['solicitada_en' => $date]));
        $request->save();
        $document = new DocumentoLaboratorio;
        $document->forceFill($this->documentoRow($context['admin'], ['cargado_en' => $date, 'tamano_bytes' => 3000000000]));
        $document->save();
        $version = new VersionResultado;
        $version->forceFill($this->versionResultadoRow($context, ['registrada_en' => $date, 'id_documento' => $document->getKey()]));
        $version->save();
        $review = new RevisionResultado;
        $review->forceFill($this->revisionRow($context, $version->getKey(), ['revisada_en' => $date]));
        $review->save();
        foreach ([[$request, 'solicitada_en'], [$document, 'cargado_en'], [$version, 'registrada_en'], [$review, 'revisada_en']] as [$model, $field]) {
            $fresh = $model->fresh();
            $this->assertInstanceOf(CarbonImmutable::class, $fresh->{$field});
            $this->assertSame($date->format('U.u'), $fresh->{$field}->format('U.u'));
            $this->assertFalse($model->usesTimestamps());
            $this->assertSame([], $model->getFillable());
            foreach (array_keys($model->getAttributes()) as $attribute) {
                $this->assertTrue($model->isGuarded($attribute));
            }
            foreach ($model->getHidden() as $attribute) {
                $this->assertArrayNotHasKey($attribute, $model->toArray());
            }
        }
        $this->assertSame(3000000000, $document->fresh()->tamano_bytes);
        $this->assertSame(1, $version->fresh()->numero_version);
        $this->assertNull($version->fresh()->id_version_origen);
        $this->assertNull($version->fresh()->motivo_cambio);
        $this->assertNull($review->fresh()->motivo);
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_metadata_and_references(string $table, array $values, string $state, ?string $constraint): void
    {
        $context = $this->laboratorioContext();
        $version = $this->resultadoEnRevision($context);
        $row = match ($table) {
            'solicitudes_laboratorio' => $this->solicitudRow($context),
            'documentos_laboratorio' => $this->documentoRow($context['admin']),
            'versiones_resultado' => $this->versionResultadoRow($context, ['numero_version' => 2]),
            'revisiones_resultado' => $this->revisionRow($context, $version),
        };
        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($row, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            ['solicitudes_laboratorio', ['id_consulta' => 9000], '23503', 'solicitudes_laboratorio_consulta_fk'],
            ['solicitudes_laboratorio', ['estudio_solicitado' => ' '], '23514', 'solicitudes_laboratorio_estudio_ck'],
            ['solicitudes_laboratorio', ['solicitada_en' => null], '23502', null],
            ['solicitudes_laboratorio', ['estado_interno' => 'Devuelta'], '23514', 'solicitudes_laboratorio_estado_ck'],
            ['solicitudes_laboratorio', ['estado_interno' => 'Disponible'], '23514', 'solicitudes_laboratorio_punteros_ck'],
            ['solicitudes_laboratorio', ['id_revision_publicada' => 9000], '23514', 'solicitudes_laboratorio_punteros_ck'],
            ['solicitudes_laboratorio', ['estado_interno' => 'En revisión', 'id_version_actual' => 9000], '23503', 'solicitudes_laboratorio_version_fk'],
            ['documentos_laboratorio', ['cargado_por' => 9000], '23503', 'documentos_laboratorio_cargador_fk'],
            ['documentos_laboratorio', ['ruta_privada' => ' '], '23514', 'documentos_laboratorio_ruta_ck'],
            ['documentos_laboratorio', ['nombre_original' => ' '], '23514', 'documentos_laboratorio_nombre_ck'],
            ['documentos_laboratorio', ['tipo_mime' => ' '], '23514', 'documentos_laboratorio_mime_ck'],
            ['documentos_laboratorio', ['tamano_bytes' => 0], '23514', 'documentos_laboratorio_tamano_ck'],
            ['documentos_laboratorio', ['tamano_bytes' => -1], '23514', 'documentos_laboratorio_tamano_ck'],
            ['documentos_laboratorio', ['sha256' => str_repeat('z', 64)], '23514', 'documentos_laboratorio_sha256_ck'],
            ['documentos_laboratorio', ['sha256' => 'abc'], '23514', 'documentos_laboratorio_sha256_ck'],
            ['documentos_laboratorio', ['cargado_en' => null], '23502', null],
            ['versiones_resultado', ['id_solicitud' => 9000], '23503', 'versiones_resultado_solicitud_fk'],
            ['versiones_resultado', ['id_documento' => 9000], '23503', 'versiones_resultado_documento_fk'],
            ['versiones_resultado', ['id_autor' => 9000], '23503', 'versiones_resultado_autor_fk'],
            ['versiones_resultado', ['id_version_origen' => 9000], '23503', 'versiones_resultado_origen_fk'],
            ['versiones_resultado', ['numero_version' => 0], '23514', 'versiones_resultado_numero_ck'],
            ['versiones_resultado', ['numero_version' => null], '23502', null],
            ['versiones_resultado', ['registrada_en' => null], '23502', null],
            ['revisiones_resultado', ['id_version_resultado' => 9000], '23503', 'revisiones_resultado_version_fk'],
            ['revisiones_resultado', ['id_medico' => 9000], '23503', 'revisiones_resultado_medico_fk'],
            ['revisiones_resultado', ['decision' => 'Publicar'], '23514', 'revisiones_resultado_decision_ck'],
            ['revisiones_resultado', ['decision' => 'Devolver', 'motivo' => null], '23514', 'revisiones_resultado_motivo_ck'],
            ['revisiones_resultado', ['decision' => 'Devolver', 'motivo' => ' '], '23514', 'revisiones_resultado_motivo_ck'],
            ['revisiones_resultado', ['revisada_en' => null], '23502', null],
        ];
    }

    private function assertRejected(callable $write, string $state, ?string $constraint = null): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL aceptó una escritura que debía rechazar.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->errorInfo[2]);
            }
        }
    }
}
