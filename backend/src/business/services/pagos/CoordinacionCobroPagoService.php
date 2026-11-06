<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\ObligacionPago;
use Clinica\data\repositories\pagos\StripePagoRepository;

/** Evidencia tardía tras cierre: conservar el hecho y retirar nuevas oportunidades de cobro. */
class CoordinacionCobroPagoService
{
    public function __construct(private StripePagoRepository $repo, private StripePagoService $stripe,
        private PayPalPagoService $paypal) {}

    public function coordinar(ObligacionPago $o): void
    {
        $cobro = $this->repo->cobro((string) $o->getKey());
        $activo = $this->repo->activo((string) $o->getKey());
        if (! $cobro || ! $activo || (string) $activo->getKey() === (string) $cobro->id_intento) {
            return;
        }
        if ($activo->proveedor === 'paypal') {
            $this->repo->guardar($activo, ['captura_permitida' => false, 'actualizado_en' => CarbonImmutable::now('UTC')]);
            $this->paypal->consulta($activo);
        } elseif ($activo->proveedor === 'simulado') {
            $this->repo->guardar($activo, ['estado' => 'Cerrado', 'actualizado_en' => CarbonImmutable::now('UTC')]);
        } elseif ($activo->proveedor === 'stripe' && ! $this->repo->escritura((string) $o->getKey())) {
            $this->stripe->preparar($activo, 'Cerrar');
        } elseif ($activo->proveedor === 'stripe') {
            $this->stripe->consulta($activo);
        }
    }
}
