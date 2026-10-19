<?php

namespace Clinica\data\repositories;

use Clinica\data\models\Medico;
use Illuminate\Support\Facades\DB;

class MedicoRepository
{
    public function porIdBloqueado(int|string $id): ?Medico
    {
        return Medico::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function vincular(Medico $medico, int|string $idUsuario): void
    {
        $medico->id_usuario = $idUsuario;
        $medico->saveOrFail();
    }

    public function tieneAtencionesSinCierreComprobable(int|string $id): bool
    {
        // Atendida no acredita cierre documental. Cancelada/Inasistencia sin consulta no generan atención.
        return DB::table('citas')->where('id_medico', $id)
            ->where(function ($q): void {
                $q->whereNotIn('estado', ['Cancelada', 'Inasistencia'])
                    ->orWhereExists(fn ($consulta) => $consulta->selectRaw('1')->from('consultas_medicas')
                        ->whereColumn('consultas_medicas.id_cita', 'citas.id_cita'));
            })->exists();
    }
}
