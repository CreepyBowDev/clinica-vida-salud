<?php

namespace Tests\Feature;

use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Especialidad;
use Clinica\data\models\HorarioAtencion;
use Clinica\data\models\Medico;
use Clinica\data\models\MedicoEspecialidad;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MedicosHorariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_has_exact_dictionary_columns_composite_key_and_no_initial_data(): void
    {
        $tables = [
            'medicos' => ['id_medico', 'id_usuario', 'id_consultorio', 'codigo_medico', 'registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto', 'activo'],
            'medico_especialidad' => ['id_medico', 'id_especialidad'],
            'horarios_atencion' => ['id_horario', 'id_medico', 'dia_semana', 'hora_inicio', 'hora_fin', 'activo'],
        ];

        foreach ($tables as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame($table.'_pk', $primary['name']);
            $this->assertSame($table === 'medico_especialidad' ? ['id_medico', 'id_especialidad'] : [$columns[0]], $primary['columns']);
        }

        $times = DB::select("SELECT data_type, datetime_precision FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'horarios_atencion' AND column_name IN ('hora_inicio', 'hora_fin')");
        $this->assertCount(2, $times);
        foreach ($times as $time) {
            $this->assertSame('time without time zone', $time->data_type);
            $this->assertSame(0, $time->datetime_precision);
        }
    }

    public function test_multiple_professionals_can_start_without_user_or_room_and_only_account_and_code_are_unique(): void
    {
        $first = $this->doctor();
        $second = $this->doctor();
        $this->assertNull($first->usuario);
        $this->assertNull($second->consultorio);
        $this->assertTrue($first->activo);
        $this->assertSame($first->registro_profesional, $second->registro_profesional);
        $this->assertSame($first->correo_contacto, $second->correo_contacto);

        $user = $this->user();
        $first->usuario()->associate($user);
        $first->save();
        $this->assertSame($user->getKey(), $first->usuario->getKey());
        $this->assertSame($first->getKey(), $user->medico->getKey());
        $this->assertRejected(fn () => DB::table('medicos')->where('id_medico', $second->getKey())->update(['id_usuario' => $user->getKey()]), '23505', 'medicos_usuario_uq');
        $this->assertRejected(fn () => DB::table('medicos')->where('id_medico', $second->getKey())->update(['codigo_medico' => $first->codigo_medico]), '23505', 'medicos_codigo_uq');
    }

    public function test_room_is_shared_and_professional_inactivation_does_not_change_user_or_relations(): void
    {
        $room = Consultorio::query()->create(['codigo_consultorio' => 'PRUEBA-C01', 'nombre' => 'Consultorio ficticio']);
        $user = $this->user();
        $doctor = $this->doctor(['id_consultorio' => $room->getKey(), 'id_usuario' => $user->getKey()]);
        $other = $this->doctor(['id_consultorio' => $room->getKey()]);
        $specialty = Especialidad::query()->firstOrFail();
        $doctor->especialidades()->attach($specialty->getKey());
        $morning = $doctor->horarios()->create($this->scheduleRow());
        $other->horarios()->create($this->scheduleRow(['hora_inicio' => '10:00:00', 'hora_fin' => '11:00:00']));
        $doctor->activo = false;
        $doctor->save();

        $this->assertSame($room->getKey(), $doctor->consultorio->getKey());
        $this->assertSame(2, $room->medicos->count());
        $this->assertTrue($room->medicos->contains($other));
        $this->assertFalse($doctor->fresh()->activo);
        $this->assertSame('Activa', $user->fresh()->estado_cuenta);
        $this->assertSame($specialty->getKey(), $doctor->especialidades->sole()->getKey());
        $this->assertSame($morning->getKey(), $doctor->horarios->sole()->getKey());
        $this->assertTrue($morning->fresh()->activo);
    }

    public function test_many_to_many_relation_uses_the_real_pivot_in_both_directions(): void
    {
        $first = $this->doctor();
        $second = $this->doctor();
        $specialties = Especialidad::query()->orderBy('id_especialidad')->get();
        $one = $specialties[0];
        $two = $specialties[1];
        $first->especialidades()->attach([$one->getKey(), $two->getKey()]);
        $second->especialidades()->attach($one->getKey());

        $this->assertSame(2, $first->especialidades->count());
        $this->assertSame(2, $one->medicos->count());
        $pivot = $first->especialidades->firstWhere('id_especialidad', $one->getKey())->pivot;
        $this->assertInstanceOf(MedicoEspecialidad::class, $pivot);
        $this->assertFalse($pivot->getIncrementing());
        $this->assertNull($pivot->getKeyName());
        $this->assertSame('id_medico', $pivot->getForeignKey());
        $this->assertSame('id_especialidad', $pivot->getRelatedKey());
        $this->assertSame($first->getKey(), $pivot->medico->getKey());
        $this->assertSame($one->getKey(), $pivot->especialidad->getKey());
        $this->assertSame(['id_medico', 'id_especialidad'], array_keys($pivot->getAttributes()));
        $this->assertRejected(fn () => $first->especialidades()->attach($one->getKey()), '23505', 'medico_especialidad_pk');

        // Solo prueba claves del adaptador con datos sin referencias históricas; no define retiro funcional.
        $pivot->delete();
        $this->assertSame(2, DB::table('medico_especialidad')->count());
        $this->assertSame($two->getKey(), $first->especialidades()->sole()->getKey());
        $this->assertSame($one->getKey(), $second->especialidades()->sole()->getKey());
    }

    public function test_inverse_pivot_writes_target_both_keys_without_affecting_other_pairs(): void
    {
        $first = $this->doctor();
        $second = $this->doctor();
        $specialties = Especialidad::query()->orderBy('id_especialidad')->get();
        $one = $specialties[0];
        $two = $specialties[1];
        $one->medicos()->attach([$first->getKey(), $second->getKey()]);
        $two->medicos()->attach($first->getKey());
        $pivot = $one->medicos()->where('medicos.id_medico', $first->getKey())->sole()->pivot;

        $this->assertSame('id_especialidad', $pivot->getForeignKey());
        $this->assertSame('id_medico', $pivot->getRelatedKey());
        $pivot->delete();
        $this->assertSame($second->getKey(), $one->medicos()->sole()->getKey());
        $this->assertSame($first->getKey(), $two->medicos()->sole()->getKey());
    }

    public function test_schedule_supports_multiple_intervals_and_days_as_local_times_without_timestamps(): void
    {
        $room = Consultorio::query()->create(['codigo_consultorio' => 'PRUEBA-C02', 'nombre' => 'Consultorio ficticio']);
        $doctor = $this->doctor(['id_consultorio' => $room->getKey()]);
        $first = $doctor->horarios()->create($this->scheduleRow());
        $doctor->horarios()->create($this->scheduleRow(['hora_inicio' => '10:00:00', 'hora_fin' => '10:15:00']));
        $last = $doctor->horarios()->create($this->scheduleRow(['dia_semana' => 7, 'hora_inicio' => '23:45:00', 'hora_fin' => '24:00:00']));
        $last->activo = false;
        $last->save();

        $this->assertSame($doctor->getKey(), $first->medico->getKey());
        $this->assertSame(3, $doctor->horarios->count());
        $this->assertSame(1, $first->fresh()->dia_semana);
        $this->assertSame('09:00:00', $first->fresh()->hora_inicio);
        $this->assertSame('10:00:00', $first->fresh()->hora_fin);
        $this->assertTrue($first->fresh()->activo);
        $this->assertSame(7, $last->fresh()->dia_semana);
        $this->assertSame('24:00:00', $last->fresh()->hora_fin);
        $this->assertFalse($last->fresh()->activo);
        $this->assertArrayNotHasKey('created_at', $first->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $doctor->getAttributes());
    }

    public function test_foreign_keys_restrict_deleting_and_rekeying_all_five_referenced_entities(): void
    {
        $user = $this->user();
        $room = Consultorio::query()->create(['codigo_consultorio' => 'PRUEBA-C03', 'nombre' => 'Consultorio ficticio']);
        $doctor = $this->doctor(['id_usuario' => $user->getKey(), 'id_consultorio' => $room->getKey()]);
        $specialty = Especialidad::query()->firstOrFail();
        $doctor->especialidades()->attach($specialty->getKey());
        $this->assertRejected(fn () => $user->delete(), '23001', 'medicos_usuario_fk');
        $this->assertRejected(fn () => $room->delete(), '23001', 'medicos_consultorio_fk');
        $this->assertRejected(fn () => $doctor->delete(), '23001', 'medico_especialidad_medico_fk');
        $this->assertRejected(fn () => $specialty->delete(), '23001', 'medico_especialidad_especialidad_fk');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $user->getKey())->update(['id_usuario' => 9000]), '23001', 'medicos_usuario_fk');
        $this->assertRejected(fn () => DB::table('consultorios')->where('id_consultorio', $room->getKey())->update(['id_consultorio' => 9000]), '23001', 'medicos_consultorio_fk');
        $this->assertRejected(fn () => DB::table('medicos')->where('id_medico', $doctor->getKey())->update(['id_medico' => 9000]), '23001', 'medico_especialidad_medico_fk');
        $this->assertRejected(fn () => DB::table('especialidades')->where('id_especialidad', $specialty->getKey())->update(['id_especialidad' => 9000]), '23001', 'medico_especialidad_especialidad_fk');

        $other = $this->doctor();
        $other->horarios()->create($this->scheduleRow());
        $this->assertRejected(fn () => $other->delete(), '23001', 'horarios_atencion_medico_fk');
        $this->assertRejected(fn () => DB::table('medicos')->where('id_medico', $other->getKey())->update(['id_medico' => 9000]), '23001', 'horarios_atencion_medico_fk');
    }

    public function test_mass_assignment_cannot_change_links_identifiers_or_activity(): void
    {
        $doctor = new Medico(['id_medico' => 50, 'id_usuario' => 50, 'id_consultorio' => 50, 'codigo_medico' => 'forzado', 'activo' => false, 'nombres' => 'Médico ficticio']);
        $this->assertNull($doctor->getKey());
        $this->assertNull($doctor->id_usuario);
        $this->assertNull($doctor->id_consultorio);
        $this->assertNull($doctor->codigo_medico);
        $this->assertTrue($doctor->activo);
        $this->assertSame('Médico ficticio', $doctor->nombres);
        $schedule = new HorarioAtencion(['id_horario' => 50, 'id_medico' => 50, 'activo' => false, 'dia_semana' => 2]);
        $this->assertNull($schedule->getKey());
        $this->assertNull($schedule->id_medico);
        $this->assertTrue($schedule->activo);
        $this->assertSame(2, $schedule->dia_semana);
        $pivot = new MedicoEspecialidad;
        $this->assertTrue($pivot->isGuarded('id_medico'));
        $this->assertTrue($pivot->isGuarded('id_especialidad'));
    }

    public function test_failed_transaction_does_not_leave_partial_professional_specialties_or_schedules(): void
    {
        $specialty = Especialidad::query()->firstOrFail();
        try {
            DB::transaction(function () use ($specialty): void {
                $doctor = $this->doctor();
                $doctor->especialidades()->attach($specialty->getKey());
                $doctor->horarios()->create($this->scheduleRow());
                throw new RuntimeException('Fallo simulado de persistencia.');
            });
            $this->fail('La operación debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado de persistencia.', $exception->getMessage());
        }
        $this->assertSame(0, Medico::query()->count());
        $this->assertSame(0, DB::table('medico_especialidad')->count());
        $this->assertSame(0, HorarioAtencion::query()->count());
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_professional_pivot_and_schedule_writes(string $table, array $values, string $state, ?string $constraint): void
    {
        $doctor = $this->doctor();
        $specialty = Especialidad::query()->firstOrFail();
        $row = match ($table) {
            'medicos' => $this->doctorRow(),
            'medico_especialidad' => ['id_medico' => $doctor->getKey(), 'id_especialidad' => $specialty->getKey()],
            'horarios_atencion' => array_replace($this->scheduleRow(), ['id_medico' => $doctor->getKey()]),
        };
        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($row, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'cuenta inexistente' => ['medicos', ['id_usuario' => 9000], '23503', 'medicos_usuario_fk'],
            'consultorio inexistente' => ['medicos', ['id_consultorio' => 9000], '23503', 'medicos_consultorio_fk'],
            'codigo vacio' => ['medicos', ['codigo_medico' => ' '], '23514', 'medicos_codigo_ck'],
            'registro vacio' => ['medicos', ['registro_profesional' => ' '], '23514', 'medicos_registro_ck'],
            'nombres vacios' => ['medicos', ['nombres' => ' '], '23514', 'medicos_nombres_ck'],
            'apellidos vacios' => ['medicos', ['apellidos' => ' '], '23514', 'medicos_apellidos_ck'],
            'telefono vacio' => ['medicos', ['telefono' => ' '], '23514', 'medicos_telefono_ck'],
            'correo vacio' => ['medicos', ['correo_contacto' => ' '], '23514', 'medicos_correo_ck'],
            'correo ausente' => ['medicos', ['correo_contacto' => null], '23502', null],
            'pivote medico inexistente' => ['medico_especialidad', ['id_medico' => 9000], '23503', 'medico_especialidad_medico_fk'],
            'pivote especialidad inexistente' => ['medico_especialidad', ['id_especialidad' => 9000], '23503', 'medico_especialidad_especialidad_fk'],
            'pivote sin medico' => ['medico_especialidad', ['id_medico' => null], '23502', null],
            'horario medico inexistente' => ['horarios_atencion', ['id_medico' => 9000], '23503', 'horarios_atencion_medico_fk'],
            'dia cero' => ['horarios_atencion', ['dia_semana' => 0], '23514', 'horarios_atencion_dia_ck'],
            'dia ocho' => ['horarios_atencion', ['dia_semana' => 8], '23514', 'horarios_atencion_dia_ck'],
            'intervalo vacio' => ['horarios_atencion', ['hora_fin' => '09:00:00'], '23514', 'horarios_atencion_intervalo_ck'],
            'intervalo invertido' => ['horarios_atencion', ['hora_fin' => '08:59:59'], '23514', 'horarios_atencion_intervalo_ck'],
            'cruza medianoche' => ['horarios_atencion', ['hora_inicio' => '23:00:00', 'hora_fin' => '01:00:00'], '23514', 'horarios_atencion_intervalo_ck'],
            'hora invalida' => ['horarios_atencion', ['hora_inicio' => '25:00:00'], '22008', null],
            'hora ausente' => ['horarios_atencion', ['hora_inicio' => null], '23502', null],
        ];
    }

    private function doctorRow(array $values = []): array
    {
        return array_replace(['codigo_medico' => 'PRUEBA-'.Str::random(16), 'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Médico ficticio', 'apellidos' => 'De prueba', 'telefono' => '70000000', 'correo_contacto' => 'contacto@example.test'], $values);
    }

    private function doctor(array $values = []): Medico
    {
        $doctor = new Medico;
        $doctor->forceFill($this->doctorRow($values));
        $doctor->save();

        return $doctor->refresh();
    }

    private function user(): Usuario
    {
        $user = new Usuario;
        $user->forceFill(['id_rol' => Rol::query()->where('nombre', 'Médico')->value('id_rol'), 'correo_acceso' => Str::random(16).'@example.test', 'estado_cuenta' => 'Activa', 'correo_verificado_en' => now(), 'password_hash' => Hash::make('Frase ficticia de prueba 123')]);
        $user->save();

        return $user;
    }

    private function scheduleRow(array $values = []): array
    {
        return array_replace(['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '10:00:00'], $values);
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
