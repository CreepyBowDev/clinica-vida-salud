<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\CobroPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\PayPalPagoRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ImportePago;
use Illuminate\Support\Str;

class EvidenciaPayPalService
{
    public function __construct(private StripePagoRepository $repo, private PayPalPagoRepository $paypal,
        private ConfiguracionPayPal $configuracion, private BitacoraRepository $bitacora) {}

    public function exigir(bool $condicion): void
    {
        if (! $condicion) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La evidencia financiera requiere verificación.');
        }
    }

    private function monto(ObligacionPago $o, mixed $m): bool
    {
        return is_array($m) && ($m['currency_code'] ?? '') === 'USD'
            && ($m['value'] ?? null) === ImportePago::desdeHistorico($o->centavos_bob, $o->centavos_usd,
                $o->tasa_numerador, $o->tasa_denominador)->montoPayPal();
    }

    /** Solo valida; el caller aporta capturas GET autenticadas, no las de un evento aislado. */
    public function validar(ObligacionPago $o, IntentoPago $i, array $s, array $capturas): void
    {
        $c = $this->configuracion->exigir();
        $u = $s['purchase_units'] ?? [];
        $this->exigir($i->proveedor === 'paypal' && $i->entorno === 'sandbox' && $i->cuenta_contexto === $c['contexto']
            && is_string($s['id'] ?? null) && preg_match('/\A[A-Za-z0-9-]{3,100}\z/', $s['id'])
            && ($i->referencia_inicio === null || $i->referencia_inicio === $s['id'])
            && ($s['intent'] ?? '') === 'CAPTURE' && is_array($u) && count($u) === 1 && isset($u[0])
            && ($u[0]['custom_id'] ?? '') === (string) $i->getKey()
            && ($u[0]['reference_id'] ?? '') === (string) $i->getKey()
            && ($u[0]['payee']['merchant_id'] ?? '') === $c['merchant_id'] && $this->monto($o, $u[0]['amount'] ?? null)
            && in_array($s['status'] ?? '', ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED', 'COMPLETED', 'VOIDED'], true)
            && empty($u[0]['payments']['authorizations']));
        $embebidas = $u[0]['payments']['captures'] ?? [];
        $this->exigir(is_array($embebidas) && count($embebidas) === count($capturas));
        $ids = [];
        foreach ($capturas as $n => $p) {
            $this->exigir(is_string($p['id'] ?? null) && preg_match('/\A[A-Za-z0-9-]{3,100}\z/', $p['id'])
                && ($embebidas[$n]['id'] ?? '') === $p['id'] && ! in_array($p['id'], $ids, true)
                // GET Capture no garantiza payee ni supplementary_data en su esquema oficial.
                // Destinatario desde la purchase_unit corroborada; pertenencia por su ID y enlace up fijo.
                && (! isset($p['supplementary_data']) || ($p['supplementary_data']['related_ids']['order_id'] ?? '') === $s['id'])
                && (! isset($p['payee']) || ($p['payee']['merchant_id'] ?? '') === $c['merchant_id'])
                && $this->monto($o, $p['amount'] ?? null) && ($p['final_capture'] ?? null) === true
                && in_array($p['status'] ?? '', ['COMPLETED', 'PENDING', 'DECLINED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true));
            if (in_array($p['status'], ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                $this->exigir($this->monto($o, $p['seller_receivable_breakdown']['gross_amount'] ?? null));
            }
            $ids[] = $p['id'];
            $up = array_values(array_filter($p['links'] ?? [], fn ($l) => ($l['rel'] ?? '') === 'up'));
            $this->exigir(count($up) === 1 && in_array($up[0]['href'] ?? '', [
                'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$s['id'],
                'https://api.sandbox.paypal.com/v2/checkout/orders/'.$s['id'],
            ], true));
        }
        $this->exigir(($s['status'] ?? '') !== 'COMPLETED' || count($capturas) > 0);
    }

    public function orden(ObligacionPago $o, IntentoPago $i, array $s, array $capturas): void
    {
        $this->validar($o, $i, $s, $capturas);
        $ahora = CarbonImmutable::now('UTC');
        $pagado = false;
        $capturaPagada = $i->referencia_pago;
        $pendiente = false;
        foreach ($capturas as $p) {
            $pendiente = $pendiente || $p['status'] === 'PENDING';
            if (! in_array($p['status'], ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                continue;
            }
            $pagado = true;
            $capturaPagada ??= $p['id'];
            $existente = $this->paypal->cobro($i->cuenta_contexto, $p['id']);
            if ($existente) {
                $this->exigir((string) $existente->id_intento === (string) $i->getKey());
            } else {
                $aplicado = $this->repo->cobro((string) $o->getKey()) === null;
                $cobro = new CobroPago;
                $this->repo->guardar($cobro, ['id_cobro' => (string) Str::uuid(), 'id_intento' => $i->getKey(),
                    'id_obligacion' => $o->getKey(), 'proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $i->cuenta_contexto,
                    'referencia_cobro' => $p['id'], 'centavos_usd' => $o->centavos_usd, 'moneda' => 'USD', 'aplicado' => $aplicado,
                    'incidencia_codigo' => $aplicado ? null : 'COBRO_ADICIONAL_NO_APLICADO', 'confirmado_en' => $ahora, 'registrado_en' => $ahora]);
                $this->bitacora->pago($aplicado ? 'PAGO_COBRO_APLICADO' : 'PAGO_COBRO_NO_APLICADO', (string) $o->getKey(),
                    ['id_cobro' => (string) $cobro->getKey(), 'id_intento' => (string) $i->getKey()]);
            }
        }
        $exitoso = $i->estado === 'Exitoso' || $pagado;
        $captura = $this->repo->porTipo((string) $i->getKey(), 'Capturar');
        $vuelo = $captura && in_array($captura->estado, ['Pendiente', 'Procesando', 'Incierta'], true);
        // Revocar permiso no prueba que una captura ya enviada no exista.
        $cerrado = ! $i->captura_permitida && ! $pendiente && ! $vuelo;
        $estado = $exitoso ? 'Exitoso' : ($cerrado ? 'Cerrado' : ($pendiente ? 'Procesando' : ($vuelo ? 'Incierto' : 'RequiereAccion')));
        $this->repo->guardar($i, ['referencia_inicio' => $s['id'], 'referencia_pago' => $capturaPagada,
            'captura_permitida' => $exitoso || $cerrado ? false : $i->captura_permitida, 'estado' => $estado,
            'actualizado_en' => $ahora, 'revisado_en' => $ahora,
            'proxima_revision_en' => in_array($estado, StripePagoRepository::ACTIVOS, true) ? $ahora->addMinute() : null]);
        if ($crear = $this->repo->creacion((string) $i->getKey())) {
            $this->terminar($crear, 'Exitosa', ['referencia_externa' => $s['id']]);
        }
        if ($captura && $capturas && ! $pendiente) {
            $this->terminar($captura, $pagado ? 'Exitosa' : 'Fallida', ['resultado_codigo' => 'CAPTURA_VERIFICADA']);
            if (! $exitoso && ! $i->captura_permitida) {
                $this->repo->guardar($i, ['estado' => 'Cerrado', 'proxima_revision_en' => null]);
            }
        }
        if ($i->estado === 'Cerrado' && ! $pagado && ! $pendiente && $crear) {
            $this->repo->guardar($crear, ['resultado_codigo' => 'CIERRE_SIN_COBRO_VERIFICADO']);
        }
        if ($pagado) {
            app(CoordinacionCobroPagoService::class)->coordinar($o);
        }
    }

    public function devolucion(ObligacionPago $o, OperacionPago $op, array $r): void
    {
        $id = $r['id'] ?? '';
        $this->exigir(is_string($id) && preg_match('/\A[A-Za-z0-9-]{3,100}\z/', $id)
            && ($op->referencia_externa === null || $op->referencia_externa === $id)
            && $this->monto($o, $r['amount'] ?? null) && $op->cobro->aplicado && ! $o->exigible
            && $o->cita->estado === 'Cancelada');
        // El enlace up debe identificar exactamente la captura original; nunca se sigue como URL.
        $up = array_values(array_filter($r['links'] ?? [], fn ($l) => ($l['rel'] ?? '') === 'up'));
        $this->exigir(count($up) === 1 && in_array($up[0]['href'] ?? '', [
            'https://api-m.sandbox.paypal.com/v2/payments/captures/'.$op->cobro->referencia_cobro,
            'https://api.sandbox.paypal.com/v2/payments/captures/'.$op->cobro->referencia_cobro,
        ], true));
        $estado = match ($r['status'] ?? '') {
            'COMPLETED' => 'Exitosa', 'PENDING' => 'Procesando', 'FAILED', 'CANCELLED' => 'Fallida', default => 'Incierta',
        };
        if ($op->devolucion->estado !== 'Exitosa') {
            $this->repo->guardar($op->devolucion, ['estado' => $estado, 'actualizada_en' => CarbonImmutable::now('UTC')]);
            $this->terminar($op, in_array($estado, ['Exitosa', 'Fallida'], true) ? $estado : 'Incierta',
                ['referencia_externa' => $id, 'resultado_codigo' => 'DEVOLUCION_VERIFICADA']);
            $this->bitacora->pago('PAGO_DEVOLUCION_VERIFICADA', (string) $o->getKey(),
                ['id_devolucion' => (string) $op->id_devolucion, 'estado' => $estado]);
        }
    }

    public function terminar(OperacionPago $op, string $estado, array $extra = []): void
    {
        $this->repo->guardar($op, ['estado' => $estado, 'codigo_reclamo' => null, 'reclamado_hasta' => null,
            'actualizada_en' => CarbonImmutable::now('UTC'), 'proxima_ejecucion_en' => CarbonImmutable::now('UTC')->addMinute(), ...$extra]);
    }
}
