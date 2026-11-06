<?php

namespace Clinica\business\services\pagos;

use Clinica\data\models\CambioCita;
use Clinica\data\models\Cita;
use Clinica\data\repositories\pagos\ObligacionPagoRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

class CoordinacionFinancieraCitaService
{
    public function __construct(private ObligacionPagoRepository $obligaciones, private StripePagoService $stripe,
        private PayPalPagoService $paypal) {}

    /** Uso interno: la transacción llamante ya mantiene el lock de Cita. Orden: Cita → obligación. */
    public function coordinar(Cita $cita, CambioCita $cambio): ?array
    {
        if (DB::transactionLevel() === 0 || (string) $cambio->id_cita !== (string) $cita->getKey()
            || ! in_array($cambio->operacion, ['REPROGRAMACION', 'CANCELACION'], true)
            || $cambio->estado_nuevo !== $cita->estado) {
            throw new LogicException('La coordinación requiere un cambio real en la transacción de la cita.');
        }
        // También conserva los flujos previos a instalar el esquema financiero: no consulta sus tablas.
        if (! $cita->incorporada_finanzas) {
            return null;
        }
        $obligacion = $this->obligaciones->porCitaBloqueada((string) $cita->getKey());
        if ($obligacion === null) {
            return null;
        }
        $antes = ['exigible' => $obligacion->exigible,
            'vencimiento_at' => $obligacion->vencimiento_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
        if ($cambio->operacion === 'CANCELACION') {
            if ($cita->estado !== 'Cancelada') {
                throw new LogicException('La anulación requiere una cancelación real.');
            }
            if (! $obligacion->exigible) {
                return null;
            }
            $obligacion->forceFill(['exigible' => false, 'anulada_en' => $cambio->realizado_en,
                'id_cambio_anulacion' => $cambio->getKey()]);
        } else {
            // Los futuros aplicadores de cobro deberán adquirir este mismo lock antes de escribir.
            if (! $obligacion->exigible || $this->obligaciones->tieneLiquidacion($obligacion)
                || $obligacion->vencimiento_at->equalTo($cita->inicio_at)) {
                return null;
            }
            $obligacion->forceFill(['vencimiento_at' => $cita->inicio_at]);
        }
        $this->obligaciones->guardar($obligacion);
        if ($cambio->operacion === 'CANCELACION') {
            $this->stripe->coordinarCancelacion($obligacion);
            $this->paypal->coordinarCancelacion($obligacion);
        }

        return ['id_obligacion' => (string) $obligacion->getKey(), 'id_cambio_cita' => (string) $cambio->getKey(),
            'antes' => $antes, 'despues' => ['exigible' => $obligacion->exigible,
                'vencimiento_at' => $obligacion->vencimiento_at->utc()->format('Y-m-d\TH:i:s.u\Z')]];
    }
}
