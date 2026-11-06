<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\CobroPago;
use Clinica\data\models\DevolucionPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Illuminate\Support\Str;

class DevolucionCancelacionService
{
    public function __construct(private StripePagoRepository $repo, private BitacoraRepository $bitacora) {}

    /** Preparación local compartida bajo Cita/obligación bloqueadas; nunca invoca proveedores. */
    public function preparar(ObligacionPago $o, CobroPago $cobro): DevolucionPago
    {
        if ($d = $this->repo->devolucion((string) $cobro->getKey())) {
            return $d;
        }
        $d = new DevolucionPago;
        $this->repo->guardar($d, ['id_devolucion' => (string) Str::uuid(), 'id_cobro' => $cobro->getKey(),
            'centavos_usd' => $cobro->centavos_usd, 'id_cambio_cancelacion' => $o->id_cambio_anulacion,
            'creada_en' => CarbonImmutable::now('UTC'), 'actualizada_en' => CarbonImmutable::now('UTC')]);
        $this->bitacora->pago('PAGO_DEVOLUCION_PREPARADA', (string) $o->getKey(),
            ['id_devolucion' => (string) $d->getKey(), 'id_cobro' => (string) $cobro->getKey(),
                'id_cambio_cancelacion' => (string) $o->id_cambio_anulacion]);

        return $d;
    }
}
