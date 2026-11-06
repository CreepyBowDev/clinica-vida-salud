<?php

namespace Clinica\data\repositories\pagos;

use Clinica\data\models\OperacionPago;
use Illuminate\Support\Facades\Schema;

class SimulacionPagoRepository
{
    public function disponible(): bool
    {
        return Schema::hasColumns('intentos_pago', ['origen', 'modalidad', 'escenario', 'aprobada_en'])
            && Schema::hasTable('recibos_academicos_pago');
    }

    public function solicitud(string $intento, string $actor, string $clave): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'solicitada_por' => $actor,
            'clave_solicitud' => $clave])->first();
    }
}
