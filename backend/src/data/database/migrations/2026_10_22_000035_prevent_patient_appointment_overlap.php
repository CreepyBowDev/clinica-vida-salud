<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // El mismo lock que requiere ALTER evita insertar un conflicto entre revisión e instalación.
            DB::statement('LOCK TABLE citas IN ACCESS EXCLUSIVE MODE');
            $conflicto = DB::selectOne(<<<'SQL'
                SELECT 1 AS existe FROM citas a JOIN citas b
                  ON a.id_paciente = b.id_paciente AND a.id_cita < b.id_cita
                 AND tstzrange(a.inicio_at, a.fin_at, '[)') && tstzrange(b.inicio_at, b.fin_at, '[)')
                WHERE a.estado IN ('Programada', 'Confirmada') AND b.estado IN ('Programada', 'Confirmada')
                LIMIT 1
                SQL);
            if ($conflicto !== null) {
                // No volcar IDs, identidad ni contenido de pacientes a logs de despliegue.
                throw new RuntimeException('CITAS_PACIENTE_CONFLICTO_PREVIO: existen citas Programada/Confirmada superpuestas del mismo Paciente. Requiere revisión administrativa autorizada antes de aplicar esta migración; no se modificó ninguna cita.');
            }
            DB::statement(<<<'SQL'
                ALTER TABLE citas ADD CONSTRAINT citas_paciente_no_solapado_excl EXCLUDE USING gist (
                    id_paciente public.gist_int8_ops WITH =,
                    tstzrange(inicio_at, fin_at, '[)') WITH &&
                ) WHERE (estado IN ('Programada', 'Confirmada'))
                SQL);
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE citas DROP CONSTRAINT citas_paciente_no_solapado_excl');
    }
};
