<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\EventoPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lease del inbox financiero, independiente del lease de la operación que despierta. */
class ProcesamientoEventoPagoService
{
    public function __construct(private StripePagoRepository $repo) {}

    public function reclamar(EventoPago $evento): ?EventoPago
    {
        return DB::transaction(function () use ($evento): ?EventoPago {
            $e = $this->repo->eventoBloqueado((string) $evento->getKey());
            $ahora = CarbonImmutable::now('UTC');
            if (! in_array($e->estado, ['Pendiente', 'PendienteVinculo', 'Incierto', 'Procesando'], true)
                || $e->proxima_ejecucion_en?->greaterThan($ahora)
                || ($e->estado === 'Procesando' && $e->reclamado_hasta->greaterThan($ahora))) {
                return null;
            }
            $this->repo->guardar($e, ['estado' => 'Procesando', 'ejecuciones' => $e->ejecuciones + 1,
                'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => $ahora->addMinutes(5),
                'proxima_ejecucion_en' => $ahora->addMinutes(5)]);

            return $e;
        });
    }

    public function finalizar(EventoPago $evento, ?IntentoPago $i, ?OperacionPago $op): void
    {
        DB::transaction(function () use ($evento, $i, $op): void {
            $e = $this->repo->eventoBloqueado((string) $evento->getKey());
            if ($e->codigo_reclamo !== $evento->codigo_reclamo) {
                return;
            }
            $ok = $op && $this->repo->operacion((string) $op->getKey())->estado === 'Exitosa';
            $this->repo->guardar($e, ['id_intento' => $i?->getKey(), 'estado' => $ok ? 'Aplicado' : ($i ? 'Incierto' : 'PendienteVinculo'),
                'procesado_en' => $ok ? CarbonImmutable::now('UTC') : null, 'codigo_reclamo' => null, 'reclamado_hasta' => null,
                'proxima_ejecucion_en' => CarbonImmutable::now('UTC')->addSeconds(min(3600, 30 * (2 ** min($e->ejecuciones, 7))))]);
        });
    }
}
