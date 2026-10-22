<?php

namespace Clinica\data\repositories\horarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\HorarioAtencion;
use Illuminate\Database\Eloquent\Collection;

class HorarioRepository
{
    public function propios(int|string $medico): Collection
    {
        return HorarioAtencion::query()->where('id_medico', $medico)->orderBy('dia_semana')->orderBy('hora_inicio')->orderBy('id_horario')->lockForUpdate()->get();
    }

    public function compartidos(int|string $medico, int|string $consultorio): Collection
    {
        return HorarioAtencion::query()->where('activo', true)->where('id_medico', '<>', $medico)
            ->whereHas('medico', fn ($q) => $q->where('id_consultorio', $consultorio))
            ->orderBy('id_medico')->orderBy('dia_semana')->orderBy('id_horario')->get();
    }

    public function ocupacion(int|string $medico, int|string|null $consultorio, CarbonImmutable $ahora): Collection
    {
        return Cita::query()->select(['id_cita', 'id_medico', 'id_consultorio', 'estado', 'inicio_at', 'fin_at'])
            ->where('estado', '<>', 'Cancelada')->where('fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'))
            ->where(fn ($q) => $q->where('id_medico', $medico)->when($consultorio !== null, fn ($q) => $q->orWhere('id_consultorio', $consultorio)))
            ->orderBy('id_cita')->lockForUpdate()->get();
    }

    public function guardar(HorarioAtencion $horario): void
    {
        $horario->saveOrFail();
    }
}
