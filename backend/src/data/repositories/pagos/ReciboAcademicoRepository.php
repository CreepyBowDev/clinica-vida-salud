<?php

namespace Clinica\data\repositories\pagos;

use Clinica\data\models\Paciente;
use Clinica\data\models\ReciboAcademicoPago;
use Illuminate\Support\Facades\Schema;

class ReciboAcademicoRepository
{
    public function titular(string $paciente): Paciente
    {
        return Paciente::query()->select(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'])
            ->whereKey($paciente)->sharedLock()->firstOrFail();
    }

    public function crear(array $datos): void
    {
        (new ReciboAcademicoPago)->forceFill($datos)->saveOrFail();
    }

    public function porObligacion(string $obligacion): ?ReciboAcademicoPago
    {
        return Schema::hasTable('recibos_academicos_pago')
            ? ReciboAcademicoPago::query()->where('id_obligacion', $obligacion)->first() : null;
    }
}
