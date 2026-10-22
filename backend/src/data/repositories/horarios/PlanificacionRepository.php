<?php

namespace Clinica\data\repositories\horarios;

use Illuminate\Support\Facades\DB;

class PlanificacionRepository
{
    public function coordinar(): void
    {
        // Dentro de una transacción, después del actor y antes de bloquear recursos.
        // Compartido por futuros escritores de horarios, asignaciones y citas.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [736201, 2]);
    }
}
