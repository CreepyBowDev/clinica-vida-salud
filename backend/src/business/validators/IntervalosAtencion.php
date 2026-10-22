<?php

namespace Clinica\business\validators;

use Carbon\CarbonImmutable;

class IntervalosAtencion
{
    public function segundos(string $hora): int
    {
        [$horas, $minutos, $segundos] = array_map('intval', explode(':', $hora));

        return $horas * 3600 + $minutos * 60 + $segundos;
    }

    public function turnos(object $horario, int $duracion): int
    {
        return intdiv($this->segundos($horario->hora_fin) - $this->segundos($horario->hora_inicio), $duracion * 60);
    }

    public function superpuestos(string $inicio, string $fin, string $otroInicio, string $otroFin): bool
    {
        return $inicio < $otroFin && $otroInicio < $fin;
    }

    public function candidatos(CarbonImmutable $dia, object $horario, int $duracion): array
    {
        $inicio = $dia->setTimeFromTimeString($horario->hora_inicio);
        $cantidad = $this->turnos($horario, $duracion);
        $turnos = [];
        for ($k = 0; $k < $cantidad; $k++) {
            $desde = $inicio->addMinutes($k * $duracion);
            $turnos[] = ['inicio' => $desde, 'fin' => $desde->addMinutes($duracion)];
        }

        return $turnos;
    }

    public function cubierto(CarbonImmutable $inicio, CarbonImmutable $fin, iterable $horarios): bool
    {
        $inicio = $inicio->setTimezone('America/La_Paz');
        $fin = $fin->setTimezone('America/La_Paz');
        if ($inicio->toDateString() !== $fin->toDateString()) {
            return false;
        }
        foreach ($horarios as $horario) {
            if ($horario->dia_semana === $inicio->isoWeekday()
                && $inicio->format('H:i:s.u') >= $horario->hora_inicio.'.000000'
                && $fin->format('H:i:s.u') <= $horario->hora_fin.'.000000') {
                return true;
            }
        }

        return false;
    }

    public function ocupaFranja(CarbonImmutable $inicio, CarbonImmutable $fin, object $horario): bool
    {
        $inicio = $inicio->setTimezone('America/La_Paz');
        $fin = $fin->setTimezone('America/La_Paz');
        // Ocho fechas cubren una primera fecha parcial y una semana recurrente completa.
        for ($dia = $inicio->startOfDay(), $i = 0; $i < 8 && $dia->lessThan($fin); $dia = $dia->addDay(), $i++) {
            if ($dia->isoWeekday() !== $horario->dia_semana) {
                continue;
            }
            $desde = $dia->setTimeFromTimeString($horario->hora_inicio);
            $hasta = $dia->setTimeFromTimeString($horario->hora_fin);
            if ($inicio->lessThan($hasta) && $desde->lessThan($fin)) {
                return true;
            }
        }

        return false;
    }
}
