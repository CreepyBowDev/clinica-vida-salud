<?php

namespace Clinica\data\repositories\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Recordatorio;

class RecordatorioRepository
{
    public function invalidarPorCita(string $id, CarbonImmutable $ahora): void
    {
        Recordatorio::query()->where('id_cita', $id)->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }
}
