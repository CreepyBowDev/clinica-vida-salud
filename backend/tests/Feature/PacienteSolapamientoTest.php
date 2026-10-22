<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class PacienteSolapamientoTest extends TestCase
{
    use CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    private function migration(): object
    {
        return require base_path('src/data/database/migrations/2026_10_22_000035_prevent_patient_appointment_overlap.php');
    }

    private function otraCita(array $r, array $extra = []): array
    {
        return $this->citaRow($r, ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1], ...$extra]);
    }

    private function rechazar(callable $operacion): void
    {
        try {
            DB::transaction($operacion);
            $this->fail('Se esperaba la exclusión de Paciente.');
        } catch (QueryException $error) {
            $this->assertSame('23P01', $error->getCode());
            $this->assertStringContainsString('citas_paciente_no_solapado_excl', $error->errorInfo[2]);
        }
    }

    public static function estados(): array
    {
        $casos = [];
        foreach (['Programada', 'Confirmada'] as $primero) {
            foreach (['Programada', 'Confirmada', 'Atendida', 'Inasistencia', 'Cancelada'] as $segundo) {
                $casos[] = [$primero, $segundo, in_array($segundo, ['Programada', 'Confirmada'], true)];
            }
        }

        return $casos;
    }

    #[DataProvider('estados')]
    public function test_database_enforces_only_patient_occupying_states_across_distinct_resources(string $primero, string $segundo, bool $rechazado): void
    {
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r, ['estado' => $primero]));
        $fila = $this->otraCita($r, ['estado' => $segundo, 'inicio_at' => '2026-11-01T12:05:00Z', 'fin_at' => '2026-11-01T12:20:00Z']);
        if ($rechazado) {
            $this->rechazar(fn () => DB::table('citas')->insert($fila));
        } else {
            DB::table('citas')->insert($fila);
            $this->assertSame(2, DB::table('citas')->count());
        }
    }

    public function test_contiguous_intervals_other_patients_and_cancelled_slots_remain_valid(): void
    {
        $r = $this->citaResources();
        $id = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        DB::table('citas')->insert($this->otraCita($r, ['inicio_at' => '2026-11-01T12:15:00Z', 'fin_at' => '2026-11-01T12:30:00Z']));
        DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Cancelada']);
        DB::table('citas')->insert($this->citaRow($r));
        $otro = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'DIFERENTE', 'nombres' => 'Ficticio', 'apellidos' => 'Otro',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        DB::table('citas')->insert($this->otraCita($r, ['id_paciente' => $otro]));
        $this->assertSame(4, DB::table('citas')->count());
    }

    public function test_updates_and_state_reentry_cannot_bypass_exclusion_or_destroy_original_row(): void
    {
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        $id = DB::table('citas')->insertGetId($this->otraCita($r, ['estado' => 'Cancelada']), 'id_cita');
        $antes = DB::table('citas')->where('id_cita', $id)->first();
        $this->rechazar(fn () => DB::table('citas')->where('id_cita', $id)->update(['estado' => 'Confirmada']));
        $this->assertEquals($antes, DB::table('citas')->where('id_cita', $id)->first());
        DB::table('citas')->where('id_cita', $id)->update(['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z', 'estado' => 'Programada']);
        $antes = DB::table('citas')->where('id_cita', $id)->first();
        $this->rechazar(fn () => DB::table('citas')->where('id_cita', $id)->update(['inicio_at' => '2026-11-01T12:05:00Z', 'fin_at' => '2026-11-01T12:20:00Z']));
        $this->assertEquals($antes, DB::table('citas')->where('id_cita', $id)->first());
    }

    public function test_migration_preserves_compatible_rows_and_removes_only_its_constraint_when_reverted(): void
    {
        $m = $this->migration();
        $m->down();
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        DB::table('citas')->insert($this->otraCita($r, ['estado' => 'Atendida']));
        $antes = DB::table('citas')->orderBy('id_cita')->get()->toJson();
        $m->up();
        $this->assertSame($antes, DB::table('citas')->orderBy('id_cita')->get()->toJson());
        $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid='citas'::regclass AND conname='citas_paciente_no_solapado_excl'")->def;
        $this->assertStringNotContainsString('now()', $def);
        $this->assertStringContainsString('Confirmada', $def);
        $m->down();
        $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid='citas'::regclass AND contype='x'")->n);
        $this->assertSame($antes, DB::table('citas')->orderBy('id_cita')->get()->toJson());
    }

    public function test_migration_detects_preexisting_conflicts_without_deleting_fixing_or_disclosing_them(): void
    {
        $m = $this->migration();
        $m->down();
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        DB::table('citas')->insert($this->otraCita($r, ['estado' => 'Confirmada']));
        $antes = DB::table('citas')->orderBy('id_cita')->get()->toJson();
        try {
            $m->up();
            $this->fail('No debe instalarse omitiendo conflictos.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('CITAS_PACIENTE_CONFLICTO_PREVIO', $error->getMessage());
            $this->assertStringNotContainsString('PRUEBA-', $error->getMessage());
        }
        $this->assertSame($antes, DB::table('citas')->orderBy('id_cita')->get()->toJson());
        $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid='citas'::regclass AND contype='x'")->n);
    }
}
