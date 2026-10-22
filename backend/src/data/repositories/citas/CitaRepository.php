<?php

namespace Clinica\data\repositories\citas;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;

class CitaRepository
{
    public function porPropuesta(string $uuid, bool $bloquear = false): ?Cita
    {
        return Cita::query()->where('id_propuesta_reserva', $uuid)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function pacienteSolapado(string $paciente, CarbonImmutable $inicio, CarbonImmutable $fin): bool
    {
        return Cita::query()->where('id_paciente', $paciente)->whereIn('estado', ['Programada', 'Confirmada'])
            ->where('inicio_at', '<', $fin->format('Y-m-d H:i:s.uP'))
            ->where('fin_at', '>', $inicio->format('Y-m-d H:i:s.uP'))->exists();
    }

    public function crear(array $datos): Cita
    {
        $cita = new Cita;
        $cita->forceFill($datos)->saveOrFail();

        return $cita;
    }
}
