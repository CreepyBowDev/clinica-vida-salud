<?php

namespace Clinica\business\services\pagos;

use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;

/** Despacho HTTP por proveedor real. No cambia proveedor ni concilia genéricamente. */
class OperacionPagoService
{
    public function __construct(private StripePagoRepository $repo, private StripePagoService $stripe,
        private PayPalPagoService $paypal, private RecuperacionStripeService $recuperacionStripe,
        private RecuperacionPayPalService $recuperacionPayPal, private StripeGateway $stripeGateway,
        private PayPalGateway $paypalGateway) {}

    public function operar(array $d, bool $inicio, string $tipo): array
    {
        $proveedor = $inicio ? $d['proveedor'] : ($this->repo->intento($d['objetivo'])?->proveedor
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.'));
        if (! in_array($proveedor, ['stripe', 'paypal'], true)) {
            throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'El intento no corresponde a un proveedor externo.');
        }
        if ($proveedor === 'paypal') {
            $r = $inicio ? $this->paypal->iniciar($d['objetivo'], $d['revision_pago'], $d['clave'])
                : $this->paypal->solicitar($d['objetivo'], $tipo, $d['clave']);
            $this->recuperacionPayPal->ejecutar($r['operacion']);
            $respuesta = $this->paypal->respuesta($r, $this->paypalGateway);
        } else {
            if ($tipo === 'Capturar') {
                throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'El intento no corresponde al proveedor.');
            }
            $r = $inicio ? $this->stripe->iniciar($d['objetivo'], $d['revision_pago'], $d['clave'])
                : $this->stripe->solicitar($d['objetivo'], $tipo === 'Cerrar', $d['clave']);
            $this->recuperacionStripe->ejecutar($r['operacion']);
            $respuesta = $this->stripe->respuesta($r, $this->stripeGateway);
        }

        return [...$respuesta, 'localizador' => $r];
    }
}
