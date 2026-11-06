<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\pagos\PayPalPagoRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Recuperación específica PayPal coordinada por el slot común de obligación. */
class RecuperacionPayPalService
{
    public function __construct(private StripePagoRepository $repo, private PayPalPagoRepository $paypal,
        private ConfiguracionPayPal $configuracion, private PayPalGateway $gateway,
        private PayPalPagoService $pagos, private EvidenciaPayPalService $evidencia) {}

    public function obtener(string $orden): array
    {
        $s = $this->gateway->orden($orden);
        $capturas = [];
        foreach ($s['purchase_units'][0]['payments']['captures'] ?? [] as $p) {
            if (count($capturas) >= 10) {
                throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La orden requiere revisión.');
            }
            $capturas[] = $this->gateway->captura($p['id'] ?? '');
        }

        return [$s, $capturas];
    }

    public function ejecutar(string $id): void
    {
        $c = $this->configuracion->exigir();
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('PayPal debe comenzar fuera de transacciones.');
        }
        $op = DB::transaction(function () use ($id, $c): ?OperacionPago {
            $op = $this->repo->operacion($id);
            if (! $op || $op->proveedor !== 'paypal' || $op->entorno !== 'sandbox' || $op->cuenta_contexto !== $c['contexto']) {
                return null;
            }
            $o = $this->repo->bloquear((string) $op->id_obligacion);
            $op->refresh();
            $ahora = CarbonImmutable::now('UTC');
            if (in_array($op->estado, ['Exitosa', 'Fallida'], true)
                || ($op->estado === 'Incierta' && $op->proxima_ejecucion_en?->greaterThan($ahora))
                || ($op->estado === 'Procesando' && $op->reclamado_hasta->greaterThan($ahora))) {
                return null;
            }
            $i = $this->repo->intento((string) $op->id_intento);
            if ($op->tipo === 'Crear' && ! $o->exigible && $op->ejecuciones === 0) {
                $this->evidencia->terminar($op, 'Fallida', ['resultado_codigo' => 'CANCELADA_ANTES_ENVIO']);
                $this->repo->guardar($i, ['estado' => 'Cerrado', 'captura_permitida' => false,
                    'actualizado_en' => $ahora, 'proxima_revision_en' => null]);

                return null;
            }
            if ($op->tipo === 'Capturar' && ! ($op->datos_solicitud_cifrados['enviada'] ?? false)
                && (! $o->exigible || ! $i->captura_permitida)) {
                $this->evidencia->terminar($op, 'Fallida', ['resultado_codigo' => 'PAGO_CAPTURA_NO_PERMITIDA']);
                $this->pagos->consulta($i);

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
            $orden = $i->referencia_inicio ?? ($op->datos_solicitud_cifrados['orden'] ?? null);
            if (! $orden && $op->tipo === 'Crear') {
                $this->ventana($op);
                if (! $this->autorizarCreacion($op)) {
                    return;
                }
                $cuerpo = $op->datos_solicitud_cifrados;
                unset($cuerpo['_handoff']);
                $creada = $this->gateway->crear($cuerpo, $op->clave_externa);
                $orden = $creada['id'] ?? '';
                $this->referencia($op, $orden, true);
            }
            if (! $orden) {
                if ($i->estado === 'Cerrado' && $this->repo->creacion((string) $i->getKey())?->resultado_codigo === 'CANCELADA_ANTES_ENVIO') {
                    $this->finalizarSinOrden($op);

                    return;
                }
                throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'La orden requiere verificación.');
            }
            [$s, $capturas] = $this->obtener($orden);
            $o = $this->repo->obligacion((string) $i->id_obligacion);
            $this->evidencia->validar($o, $i, $s, $capturas);
            if ($op->tipo === 'Capturar' && ! $capturas) {
                if (($s['status'] ?? '') !== 'APPROVED') {
                    // No se envió: rechazo seguro; no transformar aprobación en cobro.
                    if (! ($op->datos_solicitud_cifrados['enviada'] ?? false)) {
                        DB::transaction(function () use ($op): void {
                            $this->repo->bloquear((string) $op->id_obligacion);
                            $a = $this->repo->operacion((string) $op->getKey());
                            if ($a->codigo_reclamo === $op->codigo_reclamo) {
                                $this->evidencia->terminar($a, 'Fallida', ['resultado_codigo' => 'PAGO_APROBACION_REQUERIDA']);
                            }
                        });
                        throw new AppError('PAGO_APROBACION_REQUERIDA', 'La orden requiere aprobación antes de capturar.');
                    }
                    throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'La captura requiere verificación.');
                }
                $this->ventana($op);
                if ($this->autorizarEnvio($op, true)) {
                    $this->gateway->capturar($orden, $op->clave_externa);
                    [$s, $capturas] = $this->obtener($orden);
                }
            }
            $refund = null;
            if ($op->tipo === 'Devolver') {
                // Corroborar el cobro original antes de cualquier refund.
                $originales = array_values(array_filter($capturas, fn ($p) => $p['id'] === $op->cobro->referencia_cobro));
                $this->evidencia->exigir(count($originales) === 1);
                $ref = $this->repo->operacion((string) $op->getKey())->referencia_externa;
                if ($ref) {
                    $refund = $this->gateway->devolucion($ref);
                } else {
                    $this->evidencia->exigir($originales[0]['status'] === 'COMPLETED');
                    // Sin ventana refund asumida: tras handoff incierto solo GET por ID/evento firmado.
                    if ($op->datos_solicitud_cifrados['enviada'] ?? false) {
                        throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'La devolución espera una referencia verificable.');
                    }
                    if (! $this->autorizarEnvio($op, false)) {
                        throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'La devolución requiere verificación.');
                    }
                    $creada = $this->gateway->devolver($op->cobro->referencia_cobro, $op->clave_externa);
                    $this->referencia($op, $creada['id'] ?? '', false);
                    $refund = $this->gateway->devolucion($creada['id']);
                }
            }
            DB::transaction(function () use ($op, $s, $capturas, $refund): void {
                $o = $this->repo->bloquear((string) $op->id_obligacion);
                $a = $this->repo->operacion((string) $op->getKey());
                if ($a->codigo_reclamo !== $op->codigo_reclamo) {
                    return;
                }
                $i = $this->repo->intento((string) $op->id_intento);
                $this->evidencia->orden($o, $i, $s, $capturas);
                if ($refund !== null) {
                    $this->evidencia->devolucion($o, $a, $refund);
                } else {
                    $a->refresh();
                    if ($a->codigo_reclamo === $op->codigo_reclamo) {
                        $incierta = $op->tipo === 'Capturar' && (! $capturas || collect($capturas)->contains('status', 'PENDING'));
                        $this->evidencia->terminar($a, $incierta ? 'Incierta' : 'Exitosa');
                    }
                }
                $this->pagos->coordinarCancelacion($o);
            });
        } catch (\Throwable $error) {
            DB::transaction(function () use ($op, $error): void {
                $this->repo->bloquear((string) $op->id_obligacion);
                $a = $this->repo->operacion((string) $op->getKey());
                if ($a->codigo_reclamo !== $op->codigo_reclamo) {
                    return;
                }
                $this->evidencia->terminar($a, 'Incierta', ['resultado_codigo' => $error instanceof AppError ? $error->codigo : 'PAYPAL_RESULTADO_INCIERTO',
                    'proxima_ejecucion_en' => CarbonImmutable::now('UTC')->addSeconds(max(60, $error instanceof AppError ? ($error->esperaSegundos ?? 60) : 60))]);
                if ($op->tipo === 'Devolver') {
                    $this->repo->guardar($a->devolucion, ['estado' => 'Incierta', 'actualizada_en' => CarbonImmutable::now('UTC')]);
                } else {
                    $i = $this->repo->intento((string) $op->id_intento);
                    if (in_array($i->estado, StripePagoRepository::ACTIVOS, true)) {
                        $this->repo->guardar($i, ['estado' => 'Incierto', 'actualizado_en' => CarbonImmutable::now('UTC'),
                            'proxima_revision_en' => CarbonImmutable::now('UTC')->addMinute()]);
                    }
                }
            });
            if ($error instanceof AppError && $error->codigo === 'PAGO_APROBACION_REQUERIDA') {
                throw $error;
            }
        }
    }

    private function autorizarCreacion(OperacionPago $op): bool
    {
        return DB::transaction(function () use ($op): bool {
            $o = $this->repo->bloquear((string) $op->id_obligacion);
            $a = $this->repo->operacion((string) $op->getKey());
            if ($a->estado !== 'Procesando' || $a->codigo_reclamo !== $op->codigo_reclamo
                || $a->reclamado_hasta->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                || (string) $this->repo->activo((string) $o->getKey())?->getKey() !== (string) $op->id_intento) {
                throw new AppError('PAGO_ENVIO_NO_AUTORIZADO', 'La operación requiere conciliación.');
            }
            if ((! $o->exigible || $this->repo->cobro((string) $o->getKey())
                || $this->repo->cierrePayPalSolicitado((string) $op->id_intento))
                && ! ($a->datos_solicitud_cifrados['_handoff'] ?? false)) {
                $this->evidencia->terminar($a, 'Fallida', ['resultado_codigo' => 'CANCELADA_ANTES_ENVIO']);
                $this->repo->guardar($this->repo->intento((string) $op->id_intento), ['estado' => 'Cerrado',
                    'captura_permitida' => false, 'actualizado_en' => CarbonImmutable::now('UTC'), 'proxima_revision_en' => null]);

                return false;
            }
            $this->repo->guardar($a, ['datos_solicitud_cifrados' => [...$a->datos_solicitud_cifrados, '_handoff' => true]]);

            return true;
        });
    }

    /** Punto durable de handoff. Cancelación anterior gana; posterior trata la captura como en vuelo. */
    private function autorizarEnvio(OperacionPago $op, bool $captura): bool
    {
        return DB::transaction(function () use ($op, $captura): bool {
            // Mismo orden humano: cuenta antes de Cita. Una sesión perdida no impide recuperar lo ya enviado.
            $actor = $captura ? $this->paypal->capturador((string) $op->solicitada_por) : null;
            $o = $this->repo->bloquear((string) $op->id_obligacion);
            $a = $this->repo->operacion((string) $op->getKey());
            $i = $this->repo->intento((string) $op->id_intento);
            if ($a->estado !== 'Procesando' || $a->codigo_reclamo !== $op->codigo_reclamo
                || $a->reclamado_hasta->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                return false;
            }
            if ($captura && (! $o->exigible || $o->cita->estado === 'Cancelada'
                || $actor === null || $actor->estado_cuenta !== 'Activa' || $actor->correo_verificado_en === null
                || $actor->cambio_clave_obligatorio || $actor->rol?->nombre !== 'Paciente'
                || (string) $actor->paciente?->getKey() !== (string) $o->cita->id_paciente
                || $this->repo->cierrePayPalSolicitado((string) $i->getKey())
                || $this->repo->cobro((string) $o->getKey()) !== null
                || (string) $this->repo->activo((string) $o->getKey())?->getKey() !== (string) $i->getKey()
                || (! $i->captura_permitida && ! ($a->datos_solicitud_cifrados['enviada'] ?? false)))) {
                if (! ($a->datos_solicitud_cifrados['enviada'] ?? false)) {
                    $this->evidencia->terminar($a, 'Fallida', ['resultado_codigo' => 'PAGO_CAPTURA_NO_PERMITIDA']);
                    $this->repo->guardar($i, ['captura_permitida' => false, 'actualizado_en' => CarbonImmutable::now('UTC')]);
                    $this->pagos->consulta($i);
                }

                return false;
            }
            if (! $captura && ($o->exigible || ! $o->id_cambio_anulacion || ! $a->cobro->aplicado)) {
                return false;
            }
            $this->repo->guardar($a, ['datos_solicitud_cifrados' => [...$a->datos_solicitud_cifrados, 'enviada' => true]]);
            if ($captura) {
                $this->repo->guardar($i, ['captura_permitida' => false, 'estado' => 'Procesando', 'actualizado_en' => CarbonImmutable::now('UTC')]);
            }

            return true;
        });
    }

    private function ventana(OperacionPago $op): void
    {
        if ($op->creada_en->addHours(6)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new AppError('PAGO_VENTANA_AGOTADA', 'La operación requiere revisión sin repetir el envío.');
        }
    }

    private function referencia(OperacionPago $op, string $id, bool $orden): void
    {
        $this->gateway->referencia($id);
        DB::transaction(function () use ($op, $id, $orden): void {
            $this->repo->bloquear((string) $op->id_obligacion);
            $a = $this->repo->operacion((string) $op->getKey());
            $this->evidencia->exigir($a->referencia_externa === null || $a->referencia_externa === $id);
            $this->repo->guardar($a, ['referencia_externa' => $id]);
            if ($orden) {
                $i = $this->repo->intento((string) $op->id_intento);
                $this->evidencia->exigir($i->referencia_inicio === null || $i->referencia_inicio === $id);
                $this->repo->guardar($i, ['referencia_inicio' => $id]);
            }
        });
    }

    private function finalizarSinOrden(OperacionPago $op): void
    {
        DB::transaction(function () use ($op): void {
            $this->repo->bloquear((string) $op->id_obligacion);
            $a = $this->repo->operacion((string) $op->getKey());
            if ($a->codigo_reclamo === $op->codigo_reclamo) {
                $this->evidencia->terminar($a, 'Exitosa');
            }
        });
    }

    public function lote(int $limite = 100): array
    {
        if ($limite < 1 || $limite > 1000) {
            throw new \InvalidArgumentException('Lote fuera de rango.');
        }
        $c = $this->configuracion->exigir();
        foreach ($this->repo->anuladasSinDespacho('paypal', $c['contexto'], $limite) as $id) {
            DB::transaction(fn () => $this->pagos->coordinarCancelacion($this->repo->bloquear($id)));
        }
        foreach ($this->paypal->revisables($c['contexto'], $limite) as $i) {
            DB::transaction(function () use ($i): void {
                $o = $this->repo->bloquear((string) $i->id_obligacion);
                $this->pagos->consulta($i);
                $this->pagos->coordinarCancelacion($o);
            });
        }
        $ids = $this->paypal->operaciones($c['contexto'], $limite);
        foreach ($ids as $id) {
            try {
                $this->ejecutar($id);
            } catch (AppError $e) {
                if ($e->codigo !== 'PAGO_APROBACION_REQUERIDA') {
                    throw $e;
                }
            }
        }

        return ['operaciones_revisadas' => count($ids)];
    }
}
