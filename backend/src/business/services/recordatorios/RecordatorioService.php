<?php

namespace Clinica\business\services\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;

class RecordatorioService
{
    public function __construct(private RecordatorioRepository $recordatorios) {}

    public function invalidarPorCita(string $id, CarbonImmutable $ahora): void
    {
        // Se ejecuta dentro de la transacción de gestión; no genera avisos de CU-28.
        $this->recordatorios->invalidarPorCita($id, $ahora);
    }
}
