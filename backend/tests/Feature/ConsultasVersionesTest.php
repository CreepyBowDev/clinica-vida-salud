<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\Cita;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\models\VersionAntecedente;
use Clinica\data\models\VersionConsulta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ConsultasFixtures;
use Tests\TestCase;

class ConsultasVersionesTest extends TestCase
{
    use ConsultasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_has_exact_dictionary_columns_no_clinical_seeds_and_restrictive_foreign_keys(): void
    {
        $tables = [
            'consultas_medicas' => ['id_consulta', 'codigo_consulta', 'id_cita', 'iniciada_en', 'creada_en'],
            'versiones_consulta' => ['id_version_consulta', 'id_consulta', 'numero_version', 'id_autor', 'registrada_en', 'motivo_cambio', 'tipo_consulta', 'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento'],
            'versiones_antecedente' => ['id_version_antecedente', 'id_paciente', 'numero_version', 'id_autor', 'registrada_en', 'motivo_cambio', 'tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes', 'id_consulta_origen'],
        ];
        $foreignCount = 0;
        foreach ($tables as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame($table.'_pk', $primary['name']);
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $this->assertSame('restrict', $foreign['on_delete']);
                $this->assertSame('restrict', $foreign['on_update']);
                $foreignCount++;
            }
        }
        $this->assertSame(6, $foreignCount);
    }

    public function test_consultation_is_unique_per_appointment_and_code_and_start_time_remains_unknown(): void
    {
        $context = $this->consultaContext();
        $consultation = ConsultaMedica::query()->findOrFail($context['consultation']);
        $this->assertNull($consultation->iniciada_en);
        $this->assertInstanceOf(CarbonImmutable::class, $consultation->creada_en);
        $this->assertSame($context['appointment'], $consultation->cita->getKey());
        $this->assertSame($consultation->getKey(), Cita::query()->findOrFail($context['appointment'])->consulta->getKey());
        $this->assertSame($context['patient'], $consultation->cita->paciente->getKey());
        $this->assertSame($context['author'], $consultation->cita->medico->usuario->getKey());
        $this->assertRejected(fn () => DB::table('consultas_medicas')->insert($this->consultaRow($context['appointment'])), '23505', 'consultas_medicas_cita_uq');
        $otherAppointment = DB::table('citas')->insertGetId($this->citaRow($context, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        $this->assertRejected(fn () => DB::table('consultas_medicas')->insert($this->consultaRow($otherAppointment, ['codigo_consulta' => $consultation->codigo_consulta])), '23505', 'consultas_medicas_codigo_uq');
    }

    public function test_creating_versions_does_not_mark_the_appointment_attended_or_invent_the_start(): void
    {
        $context = $this->consultaContext();
        $date = CarbonImmutable::parse('2026-11-01T08:15:00.123456-04:00');
        $version = new VersionConsulta;
        $version->forceFill($this->versionConsultaRow($context, ['registrada_en' => $date, 'diagnostico' => 'Diagnóstico ficticio protegido']));
        $version->save();
        $antecedent = new VersionAntecedente;
        $antecedent->forceFill($this->versionAntecedenteRow($context, ['registrada_en' => $date, 'alergias' => 'Dato ficticio protegido']));
        $antecedent->save();

        $this->assertSame($context['consultation'], $version->consulta->getKey());
        $this->assertSame($context['author'], $version->autor->getKey());
        $this->assertSame($context['patient'], $antecedent->paciente->getKey());
        $this->assertSame($context['author'], $antecedent->autor->getKey());
        $this->assertSame($context['consultation'], $antecedent->consultaOrigen->getKey());
        $this->assertSame($version->getKey(), $version->consulta->versiones->sole()->getKey());
        $this->assertSame($antecedent->getKey(), $antecedent->consultaOrigen->versionesAntecedentesOrigen->sole()->getKey());
        $this->assertSame($antecedent->getKey(), Paciente::query()->findOrFail($context['patient'])->versionesAntecedentes->sole()->getKey());
        $author = Usuario::query()->findOrFail($context['author']);
        $this->assertSame($version->getKey(), $author->versionesConsultas->sole()->getKey());
        $this->assertSame($antecedent->getKey(), $author->versionesAntecedentes->sole()->getKey());
        $this->assertSame($date->format('U.u'), $version->fresh()->registrada_en->format('U.u'));
        $this->assertSame($date->format('U.u'), $antecedent->fresh()->registrada_en->format('U.u'));
        $this->assertNull($version->consulta->fresh()->iniciada_en);
        $this->assertSame('Programada', Cita::query()->findOrFail($context['appointment'])->estado);
        $this->assertSame(0, DB::table('bitacora')->count());
        $this->assertSame(1, $version->fresh()->numero_version);
        foreach ($version->getHidden() as $field) {
            $this->assertArrayNotHasKey($field, $version->toArray());
        }
        foreach ($antecedent->getHidden() as $field) {
            $this->assertArrayNotHasKey($field, $antecedent->toArray());
        }
    }

    public function test_new_versions_preserve_complete_previous_snapshots_and_use_the_highest_number(): void
    {
        $context = $this->consultaContext();
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($context, ['sintomas' => 'Síntoma ficticio anterior']));
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($context, ['numero_version' => 2, 'motivo_cambio' => 'Corrección ficticia', 'sintomas' => 'Síntoma ficticio corregido', 'tipo_consulta' => 'Enfermedad']));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context, ['alergias' => 'Dato ficticio anterior']));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context, ['numero_version' => 2, 'motivo_cambio' => 'Corrección ficticia', 'alergias' => 'Dato ficticio corregido']));

        $consultation = ConsultaMedica::query()->findOrFail($context['consultation']);
        $this->assertSame('Síntoma ficticio anterior', $consultation->versiones()->where('numero_version', 1)->sole()->sintomas);
        $this->assertSame(2, $consultation->versiones()->orderByDesc('numero_version')->firstOrFail()->numero_version);
        $this->assertSame('Enfermedad', $consultation->versiones()->orderByDesc('numero_version')->firstOrFail()->tipo_consulta);
        $this->assertSame('Dato ficticio anterior', VersionAntecedente::query()->where('numero_version', 1)->sole()->alergias);
        $this->assertSame('Dato ficticio corregido', VersionAntecedente::query()->orderByDesc('numero_version')->firstOrFail()->alergias);
        $this->assertRejected(fn () => DB::table('versiones_consulta')->insert($this->versionConsultaRow($context)), '23505', 'versiones_consulta_numero_uq');
        $this->assertRejected(fn () => DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context)), '23505', 'versiones_antecedente_numero_uq');
    }

    public function test_optional_clinical_data_can_remain_unknown_and_creation_does_not_add_update_timestamps(): void
    {
        $context = $this->consultaContext();
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($context));
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context));
        $version = VersionConsulta::query()->sole();
        $antecedent = VersionAntecedente::query()->sole();
        foreach (['sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento', 'motivo_cambio'] as $field) {
            $this->assertNull($version->{$field});
        }
        foreach (['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes', 'motivo_cambio'] as $field) {
            $this->assertNull($antecedent->{$field});
        }
        $this->assertFalse($version->usesTimestamps());
        $this->assertFalse($antecedent->usesTimestamps());
        $consultation = ConsultaMedica::query()->findOrFail($context['consultation']);
        $knownStart = CarbonImmutable::parse('2026-11-01T08:05:06.654321-04:00');
        $consultation->iniciada_en = $knownStart;
        $consultation->save();
        $this->assertSame($knownStart->format('U.u'), $consultation->fresh()->iniciada_en->format('U.u'));
        $this->assertArrayNotHasKey('updated_at', $consultation->getAttributes());
    }

    #[DataProvider('immutableTables')]
    public function test_versions_reject_updates_deletes_and_truncation_even_with_direct_sql(string $table): void
    {
        $context = $this->consultaContext();
        $row = $table === 'versiones_consulta' ? $this->versionConsultaRow($context) : $this->versionAntecedenteRow($context);
        DB::table($table)->insert($row);
        $constraint = $table.'_inmutable_ck';
        $this->assertRejected(fn () => DB::table($table)->update(['motivo_cambio' => 'Cambio ficticio no permitido']), '23514', $constraint);
        $this->assertRejected(fn () => DB::table($table)->delete(), '23514', $constraint);
        $this->assertRejected(fn () => DB::statement('TRUNCATE TABLE '.$table), '23514', $constraint);
        $model = $table === 'versiones_consulta' ? VersionConsulta::query()->sole() : VersionAntecedente::query()->sole();
        $model->numero_version = 2;
        $this->assertRejected(fn () => $model->save(), '23514', $constraint);
        $this->assertSame(1, $model->fresh()->numero_version);
    }

    public static function immutableTables(): array
    {
        return [['versiones_consulta'], ['versiones_antecedente']];
    }

    public function test_references_cannot_be_deleted_or_rekeyed_and_versions_protect_their_origin(): void
    {
        $context = $this->consultaContext();
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $context['appointment'])->delete(), '23001', 'consultas_medicas_cita_fk');
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $context['appointment'])->update(['id_cita' => 9000]), '23001', 'consultas_medicas_cita_fk');
        DB::table('versiones_consulta')->insert($this->versionConsultaRow($context));
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $context['consultation'])->delete(), '23001', 'versiones_consulta_consulta_fk');
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $context['consultation'])->update(['id_consulta' => 9000]), '23001', 'versiones_consulta_consulta_fk');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $context['author'])->delete(), '23001');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $context['author'])->update(['id_usuario' => 9000]), '23001');
        // Otra consulta sin VersionConsulta permite aislar la FK de origen de antecedentes.
        $otherAppointment = DB::table('citas')->insertGetId($this->citaRow($context, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        $otherConsultation = DB::table('consultas_medicas')->insertGetId($this->consultaRow($otherAppointment), 'id_consulta');
        DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context, ['id_consulta_origen' => $otherConsultation]));
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $otherConsultation)->delete(), '23001', 'versiones_antecedente_consulta_fk');
        $this->assertRejected(fn () => DB::table('consultas_medicas')->where('id_consulta', $otherConsultation)->update(['id_consulta' => 9000]), '23001', 'versiones_antecedente_consulta_fk');
        $this->assertRejected(fn () => DB::table('pacientes')->where('id_paciente', $context['patient'])->delete(), '23001');
    }

    public function test_failure_rolls_back_consultation_and_both_kinds_of_versions_together(): void
    {
        $context = $this->consultaContext();
        $otherAppointment = DB::table('citas')->insertGetId($this->citaRow($context, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        $this->assertRejected(function () use ($context, $otherAppointment): void {
            $consultation = DB::table('consultas_medicas')->insertGetId($this->consultaRow($otherAppointment), 'id_consulta');
            DB::table('versiones_consulta')->insert($this->versionConsultaRow($context, ['id_consulta' => $consultation]));
            DB::table('versiones_antecedente')->insert($this->versionAntecedenteRow($context, ['id_consulta_origen' => 9000]));
        }, '23503', 'versiones_antecedente_consulta_fk');
        $this->assertSame(1, ConsultaMedica::query()->count());
        $this->assertSame(0, VersionConsulta::query()->count());
        $this->assertSame(0, VersionAntecedente::query()->count());
        $this->assertNull(Cita::query()->findOrFail($otherAppointment)->consulta);
    }

    public function test_mass_assignment_cannot_inject_clinical_content_links_or_control_fields(): void
    {
        foreach ([new ConsultaMedica, new VersionConsulta, new VersionAntecedente] as $model) {
            $this->assertSame([], $model->getFillable());
            foreach (['id_cita', 'id_autor', 'numero_version', 'diagnostico', 'alergias'] as $field) {
                $this->assertTrue($model->isGuarded($field));
            }
        }
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_clinical_writes(string $table, array $values, string $state, ?string $constraint): void
    {
        $context = $this->consultaContext();
        $defaults = match ($table) {
            'consultas_medicas' => $this->consultaRow($context['appointment']),
            'versiones_consulta' => $this->versionConsultaRow($context),
            'versiones_antecedente' => $this->versionAntecedenteRow($context),
        };
        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($defaults, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'consulta cita inexistente' => ['consultas_medicas', ['id_cita' => 9000], '23503', 'consultas_medicas_cita_fk'],
            'consulta codigo vacio' => ['consultas_medicas', ['codigo_consulta' => ' '], '23514', 'consultas_medicas_codigo_ck'],
            'consulta sin cita' => ['consultas_medicas', ['id_cita' => null], '23502', null],
            'consulta sin creacion' => ['consultas_medicas', ['creada_en' => null], '23502', null],
            'version consulta inexistente' => ['versiones_consulta', ['id_consulta' => 9000], '23503', 'versiones_consulta_consulta_fk'],
            'version autor inexistente' => ['versiones_consulta', ['id_autor' => 9000], '23503', 'versiones_consulta_autor_fk'],
            'version cero' => ['versiones_consulta', ['numero_version' => 0], '23514', 'versiones_consulta_numero_ck'],
            'version negativa' => ['versiones_consulta', ['numero_version' => -1], '23514', 'versiones_consulta_numero_ck'],
            'version sin numero' => ['versiones_consulta', ['numero_version' => null], '23502', null],
            'version tipo ajeno' => ['versiones_consulta', ['tipo_consulta' => 'Urgencia'], '23514', 'versiones_consulta_tipo_ck'],
            'version motivo vacio' => ['versiones_consulta', ['motivo_consulta' => ' '], '23514', 'versiones_consulta_motivo_ck'],
            'version sin motivo' => ['versiones_consulta', ['motivo_consulta' => null], '23502', null],
            'version sin fecha' => ['versiones_consulta', ['registrada_en' => null], '23502', null],
            'antecedente paciente inexistente' => ['versiones_antecedente', ['id_paciente' => 9000], '23503', 'versiones_antecedente_paciente_fk'],
            'antecedente autor inexistente' => ['versiones_antecedente', ['id_autor' => 9000], '23503', 'versiones_antecedente_autor_fk'],
            'antecedente origen inexistente' => ['versiones_antecedente', ['id_consulta_origen' => 9000], '23503', 'versiones_antecedente_consulta_fk'],
            'antecedente sin origen' => ['versiones_antecedente', ['id_consulta_origen' => null], '23502', null],
            'antecedente version cero' => ['versiones_antecedente', ['numero_version' => 0], '23514', 'versiones_antecedente_numero_ck'],
            'antecedente sin numero' => ['versiones_antecedente', ['numero_version' => null], '23502', null],
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
