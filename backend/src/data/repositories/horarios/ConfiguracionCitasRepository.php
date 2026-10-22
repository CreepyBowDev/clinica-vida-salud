<?php

namespace Clinica\data\repositories\horarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\ConfiguracionClinica;
use Clinica\data\models\HorarioAtencion;
use Illuminate\Database\Eloquent\Collection;

class ConfiguracionCitasRepository
{
    public function bloqueada(): ConfiguracionClinica
    {
        return ConfiguracionClinica::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public function horariosActivos(): Collection
    {
        return HorarioAtencion::query()->where('activo', true)->orderBy('id_medico')->orderBy('dia_semana')->orderBy('id_horario')->get();
    }

    public function guardar(ConfiguracionClinica $configuracion, int $duracion): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $configuracion->forceFill(['duracion_turno_minutos' => $duracion,
            'actualizado_en' => $ahora->greaterThan($configuracion->actualizado_en) ? $ahora : $configuracion->actualizado_en->addMicrosecond()])->saveOrFail();
    }
}
