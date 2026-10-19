<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\CambioCita;
use Clinica\data\models\Cita;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Recordatorio;
use Clinica\data\models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class CitasRecordatoriosTest extends TestCase
{
    use CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_preserves_dictionary_columns_primary_keys_and_composite_reference(): void
    {
        $tables = [
            'citas' => ['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'creada_por', 'inicio_at', 'fin_at', 'duracion_minutos', 'estado', 'motivo_breve', 'modalidad', 'sede_atencion', 'consultorio_atencion', 'version_programacion', 'creada_en', 'actualizada_en'],
            'cambios_cita' => ['id_cambio_cita', 'id_cita', 'id_actor', 'operacion', 'realizado_en', 'estado_anterior', 'estado_nuevo', 'datos_anteriores', 'datos_nuevos', 'motivo'],
            'recordatorios' => ['id_recordatorio', 'id_cita', 'version_programacion', 'generado_en', 'leido_en', 'invalidado_en'],
        ];
        foreach ($tables as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame($table.'_pk', $primary['name']);
        }
        $foreign = collect(Schema::getForeignKeys('citas'))->firstWhere('name', 'citas_medico_especialidad_fk');
        $this->assertSame(['id_medico', 'id_especialidad'], $foreign['columns']);
        $this->assertSame('medico_especialidad', $foreign['foreign_table']);
        $this->assertSame(['id_medico', 'id_especialidad'], $foreign['foreign_columns']);
        $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS total FROM pg_constraint WHERE conrelid = 'citas'::regclass AND contype = 'x'")->total);
        $this->assertSame('public', DB::selectOne("SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'btree_gist'")->nspname);
    }

    public function test_models_preserve_public_relationships_defaults_timezone_and_hidden_information(): void
    {
        $resources = $this->citaResources();
        $date = CarbonImmutable::parse('2026-11-01T08:00:00.123456-04:00');
        $cita = new Cita;
        $cita->forceFill($this->citaRow($resources, ['inicio_at' => $date, 'fin_at' => $date->addMinutes(15), 'motivo_breve' => 'Motivo ficticio protegido']));
        $cita->save();
        $cita->refresh();
        $change = new CambioCita;
        $change->forceFill(['id_cita' => $cita->getKey(), 'id_actor' => $resources['actor'], 'operacion' => 'Programar', 'realizado_en' => $date, 'estado_nuevo' => 'Programada', 'datos_nuevos' => (object) ['version_programacion' => 1]]);
        $change->save();
        $notice = new Recordatorio;
        $notice->forceFill($this->noticeRow($cita->getKey()));
        $notice->save();

        $this->assertSame('Programada', $cita->estado);
        $this->assertSame('Presencial', $cita->modalidad);
        $this->assertSame(1, $cita->version_programacion);
        $this->assertSame(15, $cita->duracion_minutos);
        $this->assertSame($date->format('U.u'), $cita->inicio_at->format('U.u'));
        $this->assertInstanceOf(CarbonImmutable::class, $cita->creada_en);
        $this->assertArrayNotHasKey('motivo_breve', $cita->toArray());
        foreach (['paciente' => $resources['patient'], 'medico' => $resources['doctors'][0], 'especialidad' => $resources['specialty'], 'consultorio' => $resources['rooms'][0], 'creadaPor' => $resources['actor']] as $relation => $id) {
            $this->assertSame($id, $cita->{$relation}->getKey());
        }
        foreach ([Paciente::query()->findOrFail($resources['patient']), Medico::query()->findOrFail($resources['doctors'][0]), Especialidad::query()->findOrFail($resources['specialty']), Consultorio::query()->findOrFail($resources['rooms'][0])] as $parent) {
            $this->assertSame($cita->getKey(), $parent->citas->sole()->getKey());
        }
        $actor = Usuario::query()->findOrFail($resources['actor']);
        $this->assertSame($cita->getKey(), $actor->citasCreadas->sole()->getKey());
        $this->assertSame($change->getKey(), $actor->cambiosCita->sole()->getKey());
        $this->assertSame($change->getKey(), $cita->cambios->sole()->getKey());
        $this->assertSame($notice->getKey(), $cita->recordatorios->sole()->getKey());
        $this->assertSame($cita->getKey(), $change->cita->getKey());
        $this->assertSame($resources['actor'], $change->actor->getKey());
        $this->assertSame($cita->getKey(), $notice->cita->getKey());
        $this->assertSame(1, $change->fresh()->datos_nuevos->version_programacion);
        $this->assertArrayNotHasKey('datos_nuevos', $change->toArray());
        $this->assertNull($change->estado_anterior);
        $this->assertNull($notice->leido_en);
        $this->assertNull($notice->invalidado_en);
        $this->assertInstanceOf(CarbonImmutable::class, $notice->fresh()->generado_en);
        $this->assertNull($cita->paciente->id_usuario);
        $this->assertSame('Programada', $cita->fresh()->estado);
    }

    #[DataProvider('occupyingStates')]
    public function test_every_non_cancelled_state_blocks_overlap_of_doctor_and_room(string $state): void
    {
        $resources = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($resources, ['estado' => $state]));
        $overlap = ['inicio_at' => '2026-11-01T12:05:00Z', 'fin_at' => '2026-11-01T12:20:00Z'];
        $this->assertRejected(fn () => DB::table('citas')->insert($this->citaRow($resources, array_replace($overlap, ['id_consultorio' => $resources['rooms'][1]]))), '23P01', 'citas_medico_no_solapado_excl');
        $this->assertRejected(fn () => DB::table('citas')->insert($this->citaRow($resources, array_replace($overlap, ['id_medico' => $resources['doctors'][1]]))), '23P01', 'citas_consultorio_no_solapado_excl');
    }

    public static function occupyingStates(): array
    {
        return array_map(fn ($state) => [$state], ['Programada', 'Confirmada', 'Atendida', 'Inasistencia']);
    }

    public function test_half_open_ranges_allow_adjacent_slots_distinct_resources_and_cancellation_releases_the_slot(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        DB::table('citas')->insert($this->citaRow($resources, ['inicio_at' => '2026-11-01T11:45:00Z', 'fin_at' => '2026-11-01T12:00:00Z']));
        DB::table('citas')->insert($this->citaRow($resources, ['inicio_at' => '2026-11-01T12:15:00Z', 'fin_at' => '2026-11-01T12:30:00Z']));
        DB::table('citas')->insert($this->citaRow($resources, ['id_medico' => $resources['doctors'][1], 'id_consultorio' => $resources['rooms'][1]]));
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Cancelada']);
        DB::table('citas')->insert($this->citaRow($resources));
        $this->assertSame(5, Cita::query()->count());
        $this->assertSame('Cancelada', Cita::query()->findOrFail($id)->estado);
    }

    public function test_containing_interval_is_rejected_and_failed_rescheduling_keeps_the_original_slot(): void
    {
        $resources = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($resources));
        $id = DB::table('citas')->insertGetId($this->citaRow($resources, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        $this->assertRejected(fn () => DB::table('citas')->insert($this->citaRow($resources, ['inicio_at' => '2026-11-01T11:50:00Z', 'fin_at' => '2026-11-01T12:20:00Z', 'duracion_minutos' => 30])), '23P01');
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $id)->update(['inicio_at' => '2026-11-01T12:05:00Z', 'fin_at' => '2026-11-01T12:20:00Z', 'version_programacion' => 2]), '23P01');
        $cita = Cita::query()->findOrFail($id);
        $this->assertSame('13:00', $cita->inicio_at->utc()->format('H:i'));
        $this->assertSame(1, $cita->version_programacion);
        $this->assertRejected(fn () => DB::table('citas')->insert($this->citaRow($resources, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z'])), '23P01');
    }

    public function test_historical_duration_and_location_do_not_change_with_configuration_or_catalog_updates(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        DB::table('configuracion_clinica')->where('id_configuracion', 1)->update(['duracion_turno_minutos' => 30, 'nombre_sede' => 'Nombre posterior ficticio']);
        DB::table('consultorios')->where('id_consultorio', $resources['rooms'][0])->update(['nombre' => 'Nombre posterior ficticio']);
        $cita = Cita::query()->findOrFail($id);
        $this->assertSame(15, $cita->duracion_minutos);
        $this->assertSame('Sede ficticia de prueba', $cita->sede_atencion);
        $this->assertSame('PRUEBA — Consultorio ficticio', $cita->consultorio_atencion);
    }

    public function test_change_history_rejects_update_delete_and_truncate_in_sql_and_eloquent(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        $changeId = DB::table('cambios_cita')->insertGetId($this->changeRow($id, $resources['actor']), 'id_cambio_cita');
        $this->assertRejected(fn () => DB::table('cambios_cita')->where('id_cambio_cita', $changeId)->update(['operacion' => 'Modificar']), '23514', 'cambios_cita_inmutable_ck');
        $this->assertRejected(fn () => DB::table('cambios_cita')->where('id_cambio_cita', $changeId)->delete(), '23514', 'cambios_cita_inmutable_ck');
        $this->assertRejected(fn () => DB::statement('TRUNCATE TABLE cambios_cita'), '23514', 'cambios_cita_inmutable_ck');
        $change = CambioCita::query()->findOrFail($changeId);
        $change->motivo = 'Cambio ficticio';
        $this->assertRejected(fn () => $change->save(), '23514', 'cambios_cita_inmutable_ck');
    }

    public function test_notice_uniqueness_is_per_programming_version_and_old_versions_remain_referenced(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        DB::table('recordatorios')->insert($this->noticeRow($id));
        $this->assertRejected(fn () => DB::table('recordatorios')->insert($this->noticeRow($id)), '23505', 'recordatorios_cita_version_uq');
        DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2, 'inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']);
        DB::table('recordatorios')->where('id_cita', $id)->update(['invalidado_en' => now()]);
        DB::table('recordatorios')->insert(array_replace($this->noticeRow($id), ['version_programacion' => 2]));
        $this->assertSame(2, Cita::query()->findOrFail($id)->recordatorios->count());
        $this->assertNotNull(Recordatorio::query()->where('version_programacion', 1)->sole()->invalidado_en);
    }

    public function test_foreign_keys_preserve_the_pair_and_all_referenced_records(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        foreach ([['pacientes', 'id_paciente', $resources['patient'], 'citas_paciente_fk'], ['consultorios', 'id_consultorio', $resources['rooms'][0], null], ['usuarios', 'id_usuario', $resources['actor'], 'citas_creador_fk']] as [$table, $key, $value, $constraint]) {
            $this->assertRejected(fn () => DB::table($table)->where($key, $value)->delete(), '23001', $constraint);
            $this->assertRejected(fn () => DB::table($table)->where($key, $value)->update([$key => 9000]), '23001', $constraint);
        }
        $this->assertRejected(fn () => DB::table('medico_especialidad')->where('id_medico', $resources['doctors'][0])->where('id_especialidad', $resources['specialty'])->delete(), '23001', 'citas_medico_especialidad_fk');
        $otherSpecialty = DB::table('especialidades')->insertGetId(['nombre' => 'Otra especialidad ficticia'], 'id_especialidad');
        $this->assertRejected(fn () => DB::table('medico_especialidad')->where('id_medico', $resources['doctors'][0])->update(['id_especialidad' => $otherSpecialty]), '23001', 'citas_medico_especialidad_fk');
        DB::table('cambios_cita')->insert($this->changeRow($id, $resources['actor']));
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $id)->delete(), '23001', 'cambios_cita_cita_fk');
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $id)->update(['id_cita' => 9000]), '23001', 'cambios_cita_cita_fk');
        $other = DB::table('citas')->insertGetId($this->citaRow($resources, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        DB::table('recordatorios')->insert($this->noticeRow($other));
        $this->assertRejected(fn () => DB::table('citas')->where('id_cita', $other)->delete(), '23001', 'recordatorios_cita_fk');
    }

    public function test_failure_rolls_back_schedule_history_and_notice_changes_together(): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        DB::table('recordatorios')->insert($this->noticeRow($id));
        $this->assertRejected(function () use ($resources, $id): void {
            DB::table('citas')->where('id_cita', $id)->update(['version_programacion' => 2, 'inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']);
            DB::table('recordatorios')->where('id_cita', $id)->update(['invalidado_en' => now()]);
            DB::table('cambios_cita')->insert($this->changeRow($id, $resources['actor']));
            DB::table('recordatorios')->insert(array_replace($this->noticeRow($id), ['version_programacion' => 0]));
        }, '23514', 'recordatorios_version_ck');
        $this->assertSame(1, Cita::query()->findOrFail($id)->version_programacion);
        $this->assertSame('12:00', Cita::query()->findOrFail($id)->inicio_at->utc()->format('H:i'));
        $this->assertNull(Recordatorio::query()->sole()->invalidado_en);
        $this->assertSame(0, CambioCita::query()->count());
    }

    public function test_code_is_unique_and_mass_assignment_cannot_write_business_or_control_fields(): void
    {
        $resources = $this->citaResources();
        $row = $this->citaRow($resources);
        DB::table('citas')->insert($row);
        $this->assertRejected(fn () => DB::table('citas')->insert(array_replace($row, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z'])), '23505', 'citas_codigo_uq');
        foreach ([new Cita, new CambioCita, new Recordatorio] as $model) {
            $this->assertSame([], $model->getFillable());
            $this->assertTrue($model->isGuarded('id_cita'));
        }
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_rows(string $table, array $values, string $state, ?string $constraint): void
    {
        $resources = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($resources, ['estado' => 'Cancelada']), 'id_cita');
        $defaults = match ($table) {
            'citas' => $this->citaRow($resources),
            'cambios_cita' => $this->changeRow($id, $resources['actor']),
            'recordatorios' => $this->noticeRow($id),
        };
        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($defaults, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'cita paciente inexistente' => ['citas', ['id_paciente' => 9000], '23503', 'citas_paciente_fk'],
            'cita par inexistente' => ['citas', ['id_especialidad' => 9000], '23503', 'citas_medico_especialidad_fk'],
            'cita consultorio inexistente' => ['citas', ['id_consultorio' => 9000], '23503', 'citas_consultorio_fk'],
            'cita creador inexistente' => ['citas', ['creada_por' => 9000], '23503', 'citas_creador_fk'],
            'cita estado ajeno' => ['citas', ['estado' => 'Reprogramada'], '23514', 'citas_estado_ck'],
            'cita modalidad ajena' => ['citas', ['modalidad' => 'Virtual'], '23514', 'citas_modalidad_ck'],
            'cita duracion negativa' => ['citas', ['duracion_minutos' => -1], '23514', null],
            'cita fin inconsistente' => ['citas', ['duracion_minutos' => 30], '23514', 'citas_fin_duracion_ck'],
            'cita intervalo vacio' => ['citas', ['fin_at' => '2026-11-01T12:00:00Z'], '23514', null],
            'cita version cero' => ['citas', ['version_programacion' => 0], '23514', 'citas_version_ck'],
            'cita sede vacia' => ['citas', ['sede_atencion' => ' '], '23514', 'citas_sede_ck'],
            'cita consultorio texto vacio' => ['citas', ['consultorio_atencion' => ' '], '23514', 'citas_consultorio_texto_ck'],
            'cambio cita inexistente' => ['cambios_cita', ['id_cita' => 9000], '23503', 'cambios_cita_cita_fk'],
            'cambio actor inexistente' => ['cambios_cita', ['id_actor' => 9000], '23503', 'cambios_cita_actor_fk'],
            'cambio operacion vacia' => ['cambios_cita', ['operacion' => ' '], '23514', 'cambios_cita_operacion_ck'],
            'cambio estado anterior ajeno' => ['cambios_cita', ['estado_anterior' => 'Reprogramada'], '23514', 'cambios_cita_estado_anterior_ck'],
            'cambio estado nuevo ajeno' => ['cambios_cita', ['estado_nuevo' => 'Reprogramada'], '23514', 'cambios_cita_estado_nuevo_ck'],
            'cambio array anterior' => ['cambios_cita', ['datos_anteriores' => '[]'], '23514', 'cambios_cita_anterior_objeto_ck'],
            'cambio array nuevo' => ['cambios_cita', ['datos_nuevos' => '[]'], '23514', 'cambios_cita_nuevo_objeto_ck'],
            'cambio sin snapshot' => ['cambios_cita', ['datos_nuevos' => null], '23502', null],
            'aviso cita inexistente' => ['recordatorios', ['id_cita' => 9000], '23503', 'recordatorios_cita_fk'],
            'aviso version cero' => ['recordatorios', ['version_programacion' => 0], '23514', 'recordatorios_version_ck'],
        ];
    }

    private function changeRow(int $id, int $actor): array
    {
        return ['id_cita' => $id, 'id_actor' => $actor, 'operacion' => 'Programar', 'realizado_en' => '2026-10-19T12:00:00Z', 'estado_nuevo' => 'Programada', 'datos_nuevos' => '{"version_programacion":1}'];
    }

    private function noticeRow(int $id): array
    {
        return ['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => '2026-10-31T12:00:00Z'];
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
