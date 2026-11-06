<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\CobroPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Str;

/** Valida evidencia recuperada por el SDK; se aplica solo bajo el lock común de obligación. */
class EvidenciaStripeService
{
    public function __construct(private StripePagoRepository $repo, private BitacoraRepository $bitacora) {}

    private function exigir(bool $condicion): void
    {
        if (! $condicion) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La evidencia financiera requiere verificación.');
        }
    }

    public function sesion(ObligacionPago $o, IntentoPago $i, array $s): void
    {
        $this->exigir($i->cuenta_contexto === config('pagos.stripe.cuenta') && ($s['livemode'] ?? null) === false
            && ($s['object'] ?? '') === 'checkout.session' && ($s['mode'] ?? '') === 'payment'
            && is_string($s['id'] ?? null) && str_starts_with($s['id'], 'cs_test_')
            && ($i->referencia_inicio === null || $i->referencia_inicio === $s['id'])
            && ($s['amount_total'] ?? null) === $o->centavos_usd && ($s['currency'] ?? '') === 'usd'
            && ($s['client_reference_id'] ?? '') === (string) $i->getKey()
            && ($s['metadata']['intento'] ?? '') === (string) $i->getKey()
            && ($s['metadata']['obligacion'] ?? '') === (string) $o->getKey()
            && ($s['payment_method_types'] ?? []) === ['card']);
        $ahora = CarbonImmutable::now('UTC');
        $estado = $s['status'] ?? '';
        $this->exigir(in_array($estado, ['open', 'complete', 'expired'], true));
        $p = $s['payment_intent'] ?? null;
        if ($estado === 'expired') {
            $this->exigir(($s['payment_status'] ?? '') === 'unpaid'
                && ($p === null || (is_array($p) && ($p['status'] ?? '') === 'canceled'
                    && ($p['amount_received'] ?? null) === 0 && empty($p['latest_charge']))));
        }
        $pagado = $estado === 'complete' && ($s['payment_status'] ?? '') === 'paid';
        if ($pagado) {
            $cargo = is_array($p) ? ($p['latest_charge'] ?? null) : null;
            $this->exigir(is_array($p) && is_array($cargo) && ($p['livemode'] ?? null) === false
                && ($p['object'] ?? '') === 'payment_intent' && ($p['status'] ?? '') === 'succeeded'
                && is_string($p['id'] ?? null) && str_starts_with($p['id'], 'pi_')
                && ($i->referencia_pago === null || $i->referencia_pago === $p['id'])
                && ($p['amount'] ?? null) === $o->centavos_usd && ($p['amount_received'] ?? null) === $o->centavos_usd
                && ($p['currency'] ?? '') === 'usd' && ($p['metadata']['intento'] ?? '') === (string) $i->getKey()
                && ($p['on_behalf_of'] ?? null) === null && ($p['transfer_data'] ?? null) === null
                && ($p['metadata']['obligacion'] ?? '') === (string) $o->getKey()
                && ($cargo['object'] ?? '') === 'charge' && ($cargo['livemode'] ?? null) === false
                && ($cargo['paid'] ?? null) === true && ($cargo['captured'] ?? null) === true
                && ($cargo['status'] ?? '') === 'succeeded' && ($cargo['payment_intent'] ?? '') === $p['id']
                && ($cargo['amount'] ?? null) === $o->centavos_usd && ($cargo['amount_captured'] ?? null) === $o->centavos_usd
                && ($cargo['currency'] ?? '') === 'usd' && is_string($cargo['id'] ?? null)
                && str_starts_with($cargo['id'], 'ch_'));
            $existente = $this->repo->cobroReferencia($i->cuenta_contexto, $cargo['id']);
            if ($existente) {
                $this->exigir((string) $existente->id_intento === (string) $i->getKey());
            } else {
                $aplicado = $this->repo->cobro((string) $o->getKey()) === null;
                $c = new CobroPago;
                $this->repo->guardar($c, ['id_cobro' => (string) Str::uuid(), 'id_intento' => $i->getKey(),
                    'id_obligacion' => $o->getKey(), 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $i->cuenta_contexto,
                    'referencia_cobro' => $cargo['id'], 'centavos_usd' => $o->centavos_usd, 'moneda' => 'USD',
                    'aplicado' => $aplicado, 'incidencia_codigo' => $aplicado ? null : 'COBRO_ADICIONAL_NO_APLICADO',
                    'confirmado_en' => $ahora, 'registrado_en' => $ahora]);
                $this->bitacora->stripe($aplicado ? 'PAGO_COBRO_APLICADO' : 'PAGO_COBRO_NO_APLICADO', (string) $o->getKey(),
                    ['id_cobro' => (string) $c->getKey(), 'id_intento' => (string) $i->getKey()]);
            }
        }
        // Un evento atrasado/open nunca degrada un éxito ya registrado.
        $nuevo = $i->estado === 'Exitoso' || $pagado ? 'Exitoso'
            : ($i->estado === 'Cerrado' ? 'Cerrado'
                : ($estado === 'expired' ? 'Cerrado' : ($estado === 'open' ? 'RequiereAccion' : 'Incierto')));
        $this->repo->guardar($i, ['referencia_inicio' => $s['id'],
            'referencia_pago' => $pagado ? $p['id'] : $i->referencia_pago,
            'estado' => $nuevo, 'actualizado_en' => $ahora, 'revisado_en' => $ahora,
            'proxima_revision_en' => in_array($nuevo, StripePagoRepository::ACTIVOS, true) ? $ahora->addMinute() : null]);
        // La sesión recuperada prueba que Crear concluyó, incluso antes de su respuesta perdida.
        if (($crear = $this->repo->creacion((string) $i->getKey())) && $crear->estado !== 'Exitosa') {
            $this->terminar($crear, 'Exitosa', ['referencia_externa' => $s['id']]);
        }
        if ($crear && $estado === 'expired' && ! $pagado && $nuevo === 'Cerrado') {
            $this->repo->guardar($crear, ['resultado_codigo' => 'CIERRE_SIN_COBRO_VERIFICADO']);
        }
        if (($estado === 'expired' || $pagado) && ($cierre = $this->repo->escritura((string) $o->getKey()))
            && $cierre->tipo === 'Cerrar' && (string) $cierre->id_intento === (string) $i->getKey()) {
            $this->terminar($cierre, 'Exitosa', ['resultado_codigo' => 'CIERRE_VERIFICADO']);
        }
        if ($pagado) {
            app(CoordinacionCobroPagoService::class)->coordinar($o);
        }
    }

    public function devolucion(ObligacionPago $o, IntentoPago $i, OperacionPago $op, array $r): void
    {
        $this->exigir(($r['object'] ?? '') === 'refund' && is_string($r['id'] ?? null) && str_starts_with($r['id'], 're_')
            // Refund no expone livemode; cuenta SDK test + Session/PI/Charge corroborados fijan el contexto.
            && ($r['livemode'] ?? false) === false && ($r['payment_intent'] ?? '') === $i->referencia_pago
            && ($r['charge'] ?? '') === $op->cobro->referencia_cobro
            && ($r['amount'] ?? null) === $op->cobro->centavos_usd && ($r['currency'] ?? '') === 'usd'
            && ($r['metadata']['devolucion'] ?? '') === (string) $op->id_devolucion
            && ($op->referencia_externa === null || $op->referencia_externa === $r['id'])
            && $op->cobro->aplicado && ! $o->exigible && $o->cita->estado === 'Cancelada');
        $estado = match ($r['status'] ?? '') {
            'succeeded' => 'Exitosa', 'pending', 'requires_action' => 'Procesando',
            'failed', 'canceled' => 'Fallida', default => 'Incierta',
        };
        $d = $op->devolucion;
        if ($d->estado === 'Exitosa') {
            return;
        }
        $this->repo->guardar($d, ['estado' => $estado, 'actualizada_en' => CarbonImmutable::now('UTC')]);
        $this->terminar($op, in_array($estado, ['Exitosa', 'Fallida'], true) ? $estado : 'Incierta',
            ['referencia_externa' => $r['id'], 'resultado_codigo' => ($r['status'] ?? '') === 'requires_action'
                ? 'DEVOLUCION_ACCION_NO_SOPORTADA' : 'DEVOLUCION_VERIFICADA']);
        $this->bitacora->stripe('PAGO_DEVOLUCION_VERIFICADA', (string) $o->getKey(),
            ['id_devolucion' => (string) $d->getKey(), 'estado' => $estado]);
    }

    public function terminar(OperacionPago $op, string $estado, array $extra = []): void
    {
        $this->repo->guardar($op, ['estado' => $estado, 'codigo_reclamo' => null, 'reclamado_hasta' => null,
            'actualizada_en' => CarbonImmutable::now('UTC'), 'proxima_ejecucion_en' => CarbonImmutable::now('UTC')->addMinute(), ...$extra]);
    }
}
