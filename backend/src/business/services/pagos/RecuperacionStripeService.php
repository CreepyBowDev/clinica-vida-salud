<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

/** Recuperación acotada a Stripe con exclusión común entre proveedores. */
class RecuperacionStripeService
{
    public function __construct(private StripePagoRepository $repo, private StripeGateway $gateway,
        private StripePagoService $pagos, private EvidenciaStripeService $evidencia,
        private ConfiguracionStripe $configuracion) {}

    public function ejecutar(string $id): void
    {
        $c = $this->configuracion->exigir();
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('El procesador Stripe debe comenzar fuera de transacciones.');
        }
        $op = DB::transaction(function () use ($id, $c): ?OperacionPago {
            $op = $this->repo->operacion($id);
            if (! $op || $op->proveedor !== 'stripe' || $op->entorno !== 'test' || $op->cuenta_contexto !== $c['cuenta']) {
                return null;
            }
            $o = $this->repo->bloquear((string) $op->id_obligacion);
            $op = $this->repo->operacion($id);
            $ahora = CarbonImmutable::now('UTC');
            if (in_array($op->estado, ['Exitosa', 'Fallida'], true)
                || ($op->estado === 'Incierta' && $op->proxima_ejecucion_en?->greaterThan($ahora))
                || ($op->estado === 'Procesando' && $op->reclamado_hasta->greaterThan($ahora))) {
                return null;
            }
            // Cancelación antes del primer envío: no crear sesión ni liberar incertidumbre si ya se envió.
            if ($op->tipo === 'Crear' && ! $o->exigible && $op->ejecuciones === 0) {
                $this->evidencia->terminar($op, 'Fallida', ['resultado_codigo' => 'CANCELADA_ANTES_ENVIO']);
                $this->repo->guardar($this->repo->intento((string) $op->id_intento), ['estado' => 'Cerrado',
                    'actualizado_en' => $ahora, 'proxima_revision_en' => null]);
                $this->pagos->coordinarCancelacion($o);

                return null;
            }
            $i = $this->repo->intento((string) $op->id_intento);
            if ($op->tipo === 'Consultar' && $i->estado === 'Cerrado' && $i->referencia_inicio === null
                && $this->repo->creacion((string) $i->getKey())?->resultado_codigo === 'CANCELADA_ANTES_ENVIO') {
                $this->evidencia->terminar($op, 'Exitosa', ['resultado_codigo' => 'CANCELADA_ANTES_ENVIO']);
                $this->pagos->coordinarCancelacion($o);

                return null;
            }
            $this->repo->guardar($op, ['estado' => 'Procesando', 'ejecuciones' => $op->ejecuciones + 1,
                'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => $ahora->addMinutes(5),
                'actualizada_en' => $ahora, 'proxima_ejecucion_en' => $ahora->addMinutes(5)]);

            return $op;
        });
        if (! $op) {
            return;
        }
        try {
            $i = $this->repo->intento((string) $op->id_intento);
            $s = null;
            $r = null;
            if ($op->tipo === 'Devolver') {
                $s = $this->gateway->sesion($i->referencia_inicio);
                if (($s['status'] ?? '') !== 'complete' || ($s['payment_status'] ?? '') !== 'paid'
                    || ($s['payment_intent']['latest_charge']['id'] ?? '') !== $op->cobro->referencia_cobro) {
                    throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'El cobro requiere verificación antes de devolver.');
                }
                DB::transaction(function () use ($op, $s): void {
                    $o = $this->repo->bloquear((string) $op->id_obligacion);
                    $this->evidencia->sesion($o, $this->repo->intento((string) $op->id_intento), $s);
                });
                if ($op->referencia_externa) {
                    $r = $this->gateway->devolucion($op->referencia_externa);
                } else {
                    // Antes de escribir se comprueba que no haya devolución externa previa/parcial.
                    $lista = $this->gateway->devoluciones($i->referencia_pago);
                    if (($lista['has_more'] ?? true) !== false) {
                        throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La devolución requiere verificación.');
                    }
                    foreach ($lista['data'] ?? [] as $previa) {
                        if (($previa['metadata']['devolucion'] ?? '') === (string) $op->id_devolucion) {
                            if ($r !== null) {
                                throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La devolución requiere verificación.');
                            }
                            $r = $this->gateway->devolucion($previa['id']);
                        } else {
                            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'Existe una devolución que requiere revisión.');
                        }
                    }
                    if ($r === null) {
                        $this->ventana($op);
                        $this->exigirEnvio($op);
                        $creada = $this->gateway->devolver($op->datos_solicitud_cifrados, $op->clave_externa);
                        $this->conservarReferencia($op, $creada['id'] ?? '', false);
                        $r = $this->gateway->devolucion($creada['id']);
                    }
                }
            } else {
                $referencia = $i->referencia_inicio ?? ($op->datos_solicitud_cifrados['sesion'] ?? null);
                if (! $referencia && $op->tipo === 'Crear') {
                    $this->ventana($op);
                    if (! $this->exigirEnvio($op)) {
                        return;
                    }
                    $cuerpo = $op->datos_solicitud_cifrados;
                    unset($cuerpo['_handoff']);
                    $creada = $this->gateway->crear($cuerpo, $op->clave_externa);
                    $referencia = $creada['id'] ?? null;
                    $this->conservarReferencia($op, $referencia ?? '', true);
                }
                if (! $referencia && isset($op->datos_solicitud_cifrados['pago'])) {
                    $encontrada = $this->gateway->sesionPorPago($op->datos_solicitud_cifrados['pago']);
                    $referencia = $encontrada['id'] ?? null;
                }
                if (! is_string($referencia) || ! str_starts_with($referencia, 'cs_test_')) {
                    throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La sesión requiere verificación.');
                }
                $s = $this->gateway->sesion($referencia);
                if ($op->tipo === 'Cerrar' && ($s['status'] ?? '') === 'open') {
                    // Corroborar modo/importe/contexto antes de ordenar una mutación externa.
                    DB::transaction(function () use ($op, $s): void {
                        $o = $this->repo->bloquear((string) $op->id_obligacion);
                        $this->evidencia->sesion($o, $this->repo->intento((string) $op->id_intento), $s);
                    });
                    $this->ventana($op);
                    $this->exigirEnvio($op);
                    $this->gateway->cerrar($referencia, $op->clave_externa);
                    $s = $this->gateway->sesion($referencia);
                }
            }
            DB::transaction(function () use ($op, $s, $r): void {
                $o = $this->repo->bloquear((string) $op->id_obligacion);
                $actual = $this->repo->operacion((string) $op->getKey());
                if ($actual->codigo_reclamo !== $op->codigo_reclamo) {
                    return; // Otro resultado verificado ya resolvió la operación; nunca finaliza un lease ajeno.
                }
                $i = $this->repo->intento((string) $op->id_intento);
                if ($r !== null) {
                    $this->evidencia->devolucion($o, $i, $actual, $r);
                } else {
                    $this->evidencia->sesion($o, $i, $s);
                    $this->evidencia->terminar($actual, $op->tipo === 'Cerrar'
                        && ! in_array($i->estado, ['Cerrado', 'Exitoso'], true) ? 'Incierta' : 'Exitosa');
                }
                $this->pagos->coordinarCancelacion($o);
                if (($op->datos_solicitud_cifrados['cerrar'] ?? false) && $op->tipo === 'Consultar'
                    && in_array($i->estado, StripePagoRepository::ACTIVOS, true)
                    && ! $this->repo->escritura((string) $o->getKey())) {
                    $this->pagos->preparar($i, 'Cerrar');
                }
            });
        } catch (\Throwable $error) {
            DB::transaction(function () use ($op, $error): void {
                $this->repo->bloquear((string) $op->id_obligacion);
                $actual = $this->repo->operacion((string) $op->getKey());
                if ($actual->codigo_reclamo !== $op->codigo_reclamo) {
                    return;
                }
                $this->evidencia->terminar($actual, 'Incierta', ['resultado_codigo' => $error instanceof AppError
                    ? $error->codigo : 'STRIPE_RESULTADO_INCIERTO',
                    'proxima_ejecucion_en' => CarbonImmutable::now('UTC')->addSeconds(max(60,
                        $error instanceof ApiErrorException ? $this->esperaExterna($error) : 60))]);
                if ($op->tipo === 'Devolver') {
                    $this->repo->guardar($actual->devolucion, ['estado' => 'Incierta', 'actualizada_en' => CarbonImmutable::now('UTC')]);
                } else {
                    $i = $this->repo->intento((string) $actual->id_intento);
                    if (in_array($i->estado, StripePagoRepository::ACTIVOS, true)) {
                        $this->repo->guardar($i, ['estado' => 'Incierto', 'actualizado_en' => CarbonImmutable::now('UTC'),
                            'proxima_revision_en' => CarbonImmutable::now('UTC')->addMinute()]);
                    }
                }
            });
        }
    }

    private function esperaExterna(ApiErrorException $error): int
    {
        $valor = $error->getHttpHeaders()['retry-after'] ?? $error->getHttpHeaders()['Retry-After'] ?? '';

        if (! is_scalar($valor)) {
            return 60;
        }

        return preg_match('/\A[0-9]{1,9}\z/', (string) $valor) ? (int) $valor
            : (($fecha = strtotime((string) $valor)) !== false ? max(0, $fecha - time()) : 60);
    }

    /** Handoff local, no prueba de recepción remota; nunca mantiene locks durante HTTP. */
    private function exigirEnvio(OperacionPago $op): bool
    {
        return DB::transaction(function () use ($op): bool {
            $o = $this->repo->bloquear((string) $op->id_obligacion);
            $a = $this->repo->operacion((string) $op->getKey());
            if ($a->estado !== 'Procesando' || $a->codigo_reclamo !== $op->codigo_reclamo
                || $a->reclamado_hasta->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                || ($op->tipo === 'Crear' && (string) $this->repo->activo((string) $o->getKey())?->getKey() !== (string) $op->id_intento)) {
                throw new AppError('PAGO_ENVIO_NO_AUTORIZADO', 'La operación requiere conciliación.');
            }
            if ($op->tipo === 'Crear' && (! $o->exigible || $this->repo->cobro((string) $o->getKey()))
                && ! ($a->datos_solicitud_cifrados['_handoff'] ?? false)) {
                $this->evidencia->terminar($a, 'Fallida', ['resultado_codigo' => 'CANCELADA_ANTES_ENVIO']);
                $this->repo->guardar($this->repo->intento((string) $op->id_intento), ['estado' => 'Cerrado',
                    'actualizado_en' => CarbonImmutable::now('UTC'), 'proxima_revision_en' => null]);

                return false;
            }
            if ($op->tipo === 'Crear') {
                $this->repo->guardar($a, ['datos_solicitud_cifrados' => [...$a->datos_solicitud_cifrados, '_handoff' => true]]);
            }

            return true;
        });
    }

    private function ventana(OperacionPago $op): void
    {
        // Margen conservador antes de las 24 h: jamás una clave nueva para despejar incertidumbre.
        if ($op->creada_en->addHours(23)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new AppError('PAGO_VENTANA_AGOTADA', 'La operación requiere revisión sin repetir el envío.');
        }
    }

    /** Conserva solo localizador del resultado SDK: no confirma cobro/refund ni libera exclusión. */
    private function conservarReferencia(OperacionPago $op, string $id, bool $sesion): void
    {
        if (! preg_match($sesion ? '/\Acs_test_[A-Za-z0-9]+\z/' : '/\Are_[A-Za-z0-9]+\z/', $id)) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La referencia externa requiere verificación.');
        }
        DB::transaction(function () use ($op, $id, $sesion): void {
            $this->repo->bloquear((string) $op->id_obligacion);
            $actual = $this->repo->operacion((string) $op->getKey());
            if ($actual->referencia_externa !== null && $actual->referencia_externa !== $id) {
                throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La referencia externa requiere verificación.');
            }
            $this->repo->guardar($actual, ['referencia_externa' => $id]);
            if ($sesion) {
                $i = $this->repo->intento((string) $op->id_intento);
                if ($i->referencia_inicio !== null && $i->referencia_inicio !== $id) {
                    throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La sesión requiere verificación.');
                }
                $this->repo->guardar($i, ['referencia_inicio' => $id]);
            }
        });
    }

    public function lote(int $limite = 100): array
    {
        if ($limite < 1 || $limite > 1000) {
            throw new \InvalidArgumentException('Lote fuera de rango.');
        }
        $c = $this->configuracion->exigir();
        foreach ($this->repo->anuladasSinDespacho('stripe', $c['cuenta'], $limite) as $id) {
            DB::transaction(fn () => $this->pagos->coordinarCancelacion($this->repo->bloquear($id)));
        }
        foreach ($this->repo->intentosRevisables($limite, $c['cuenta']) as $i) {
            DB::transaction(function () use ($i): void {
                $o = $this->repo->bloquear((string) $i->id_obligacion);
                $this->pagos->consulta($i);
                $this->pagos->coordinarCancelacion($o);
                app(CoordinacionCobroPagoService::class)->coordinar($o);
            });
        }
        $ids = $this->repo->pendientes($limite, $c['cuenta']);
        foreach ($ids as $id) {
            $this->ejecutar($id);
        }

        return ['operaciones_revisadas' => count($ids)];
    }
}
