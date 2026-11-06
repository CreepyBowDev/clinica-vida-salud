<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ImportePago;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StripePagoService
{
    public function __construct(private StripePagoRepository $repo, private ConfiguracionStripe $configuracion,
        private AutenticacionService $auth, private BitacoraRepository $bitacora,
        private SeguridadInicioPagoService $seguridad) {}

    public function revision(ObligacionPago $o, string $actor): string
    {
        try {
            $paypal = app(ConfiguracionPayPal::class)->exigir()['contexto'];
        } catch (AppError) {
            $paypal = null;
        }

        return Crypt::encryptString(json_encode(['o' => (string) $o->getKey(), 'a' => $actor,
            'paypal_contexto' => $paypal,
            'usd' => $o->centavos_usd, 'bob' => $o->centavos_bob, 'cuenta' => config('pagos.stripe.cuenta'),
            'vence' => CarbonImmutable::now('UTC')->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function elegibilidad(ObligacionPago $o, string $actor): array
    {
        try {
            $this->configuracion->exigir();
        } catch (AppError) {
            return ['puede_iniciar_pago' => false, 'revision_pago' => null];
        }
        $puede = $o->exigible && $o->cita->estado !== 'Cancelada' && $this->repo->cobro((string) $o->getKey()) === null
            && $this->repo->activo((string) $o->getKey()) === null && $this->repo->escritura((string) $o->getKey()) === null
            && $this->seguridad->disponible((string) $o->getKey());

        return ['puede_iniciar_pago' => $puede, 'revision_pago' => $puede ? $this->revision($o, $actor) : null];
    }

    private function titular(string $obligacion): array
    {
        $actor = $this->auth->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Paciente' || $actor->paciente === null || $actor->medico !== null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $o = $this->repo->obligacion($obligacion, (string) $actor->paciente->getKey())
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');

        return [$actor, $o];
    }

    public function iniciar(string $id, string $revision, string $clave): array
    {
        $c = $this->configuracion->exigir();

        return DB::transaction(function () use ($id, $revision, $clave, $c): array {
            [$actor] = $this->titular($id);
            $o = $this->repo->bloquear($id);
            $huella = hash('sha256', json_encode(['stripe', $revision], JSON_THROW_ON_ERROR));
            if ($replay = $this->repo->replay($id, (string) $actor->getKey(), $clave)) {
                if (! hash_equals($replay->huella_solicitud, $huella)) {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }

                return ['operacion' => (string) $replay->getKey(), 'intento' => (string) $replay->id_intento, 'repetida' => true];
            }
            try {
                $r = json_decode(Crypt::decryptString($revision), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $r = [];
            }
            if (($r['o'] ?? '') !== $id || ($r['a'] ?? '') !== (string) $actor->getKey()
                || ($r['usd'] ?? null) !== $o->centavos_usd || ($r['bob'] ?? null) !== $o->centavos_bob
                || ($r['cuenta'] ?? '') !== $c['cuenta'] || ($r['vence'] ?? 0) <= CarbonImmutable::now('UTC')->timestamp) {
                throw new AppError('PAGO_REVISION_OBSOLETA', 'Revisa nuevamente el precio antes de pagar.');
            }
            if (! $o->exigible || $o->cita->estado === 'Cancelada') {
                throw new AppError('PAGO_NO_EXIGIBLE', 'La obligación ya no es exigible.');
            }
            if ($this->repo->cobro($id) || $this->repo->activo($id) || $this->repo->escritura($id)) {
                throw new AppError('PAGO_OPERACION_ACTIVA', 'Existe un pago o una operación en verificación.');
            }
            if (! $this->seguridad->disponible($id)) {
                throw new AppError('PAGO_OPERACION_ACTIVA', 'El cierre sin cobro anterior requiere verificación.');
            }
            $i = new IntentoPago;
            $ahora = CarbonImmutable::now('UTC');
            $this->repo->guardar($i, ['id_intento' => (string) Str::uuid(), 'id_obligacion' => $id,
                'iniciado_por' => $actor->getKey(), 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $c['cuenta'],
                'estado' => 'Preparando', 'creado_en' => $ahora, 'actualizado_en' => $ahora, 'proxima_revision_en' => $ahora]);
            $importe = ImportePago::desdeHistorico($o->centavos_bob, $o->centavos_usd, $o->tasa_numerador, $o->tasa_denominador);
            $metadata = ['intento' => (string) $i->getKey(), 'obligacion' => $id];
            $retorno = $c['origen_retorno'].'/pagos/retorno/stripe?intento='.$i->getKey();
            $cuerpo = ['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => (string) $i->getKey(),
                'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
                'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => $importe->centavosUsd,
                    'product_data' => ['name' => 'Pago académico']], 'quantity' => 1]],
                'success_url' => $retorno, 'cancel_url' => $retorno, 'adaptive_pricing' => ['enabled' => false]];
            $op = $this->preparar($i, 'Crear', $cuerpo, ['solicitada_por' => $actor->getKey(),
                'clave_solicitud' => $clave, 'huella_solicitud' => $huella]);
            $this->bitacora->stripe('PAGO_INTENTO_PREPARADO', $id, ['id_intento' => (string) $i->getKey(),
                'iniciado_por' => (string) $actor->getKey()], $actor);

            return ['operacion' => (string) $op->getKey(), 'intento' => (string) $i->getKey(), 'repetida' => false];
        });
    }

    /** Solo preparación durable. El Controller/worker ejecuta red cuando esta transacción terminó. */
    public function solicitar(string $id, bool $cierre, string $clave): array
    {
        $this->configuracion->exigir();

        return DB::transaction(function () use ($id, $cierre, $clave): array {
            $i = $this->repo->intento($id) ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            [$actor] = $this->titular((string) $i->id_obligacion);
            $this->repo->bloquear((string) $i->id_obligacion);
            $i->refresh();
            if ($previa = $this->repo->solicitudIntento($id, (string) $actor->getKey(), $clave)) {
                if (($previa->datos_solicitud_cifrados['cerrar'] ?? false) !== $cierre) {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }

                return ['operacion' => (string) $previa->getKey(), 'intento' => $id, 'repetida' => true];
            }
            $op = $cierre && ! $this->repo->escritura((string) $i->id_obligacion)
                && in_array($i->estado, StripePagoRepository::ACTIVOS, true)
                ? $this->preparar($i, 'Cerrar', ['cerrar' => true], ['solicitada_por' => $actor->getKey(), 'clave_solicitud' => $clave])
                : $this->preparar($i, 'Consultar', ['cerrar' => $cierre], ['solicitada_por' => $actor->getKey(), 'clave_solicitud' => $clave]);

            return ['operacion' => (string) $op->getKey(), 'intento' => $id, 'repetida' => true];
        });
    }

    public function preparar(IntentoPago $i, string $tipo, array $cuerpo = [], array $extra = []): OperacionPago
    {
        $ahora = CarbonImmutable::now('UTC');
        $op = new OperacionPago;
        $this->repo->guardar($op, ['id_operacion' => (string) Str::uuid(), 'id_intento' => $i->getKey(),
            'id_obligacion' => $i->id_obligacion, 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $i->cuenta_contexto,
            'tipo' => $tipo, 'huella_solicitud' => hash('sha256', json_encode($cuerpo, JSON_THROW_ON_ERROR)),
            'datos_solicitud_cifrados' => $cuerpo, 'clave_externa' => $tipo === 'Consultar' ? null : (string) Str::uuid(),
            'estado' => 'Pendiente', 'creada_en' => $ahora, 'actualizada_en' => $ahora, 'proxima_ejecucion_en' => $ahora, ...$extra]);

        return $op;
    }

    public function respuesta(array $r, StripeGateway $gateway): array
    {
        $i = $this->repo->intento($r['intento']);
        $op = $this->repo->operacion($r['operacion']);
        $o = $this->repo->obligacion((string) $i->id_obligacion);
        $url = null;
        if ($op->estado === 'Exitosa' && $o->exigible && ! $this->repo->cobro((string) $o->getKey())
            && $i->estado === 'RequiereAccion' && $i->referencia_inicio) {
            try {
                $s = $gateway->sesion($i->referencia_inicio);
                $candidato = $s['url'] ?? '';
                if (($s['livemode'] ?? null) === false && ($s['status'] ?? '') === 'open'
                    && ($s['id'] ?? '') === $i->referencia_inicio && ($s['amount_total'] ?? null) === $o->centavos_usd
                    && ($s['currency'] ?? '') === 'usd' && ($s['client_reference_id'] ?? '') === (string) $i->getKey()
                    && ($s['metadata']['obligacion'] ?? '') === (string) $o->getKey()
                    && preg_match('~\Ahttps://checkout\.stripe\.com/[^\s]*\z~', $candidato)
                    && $this->repo->obligacion((string) $o->getKey())->exigible
                    && ! $this->repo->cobro((string) $o->getKey())) {
                    $url = $candidato;
                }
            } catch (\Throwable) {
                // Sin URL corroborada se mantiene verificación, nunca éxito inventado.
            }
        }
        $pendiente = $op->estado === 'Fallida' ? false : ($op->estado !== 'Exitosa' || $i->estado === 'Incierto'
            || (($op->datos_solicitud_cifrados['cerrar'] ?? false) && in_array($i->estado, StripePagoRepository::ACTIVOS, true))
            || ($o->exigible && $i->estado === 'RequiereAccion' && $url === null));
        if ($pendiente) {
            $url = null;
        }
        $cobro = $this->repo->cobro((string) $o->getKey());
        $d = $cobro ? $this->repo->devolucion((string) $cobro->getKey()) : null;

        return ['data' => ['id' => (string) $i->getKey(), 'obligacion_id' => (string) $o->getKey(),
            'proveedor' => 'stripe', 'estado' => $i->estado,
            'aplicado' => $cobro !== null && (string) $cobro->id_intento === (string) $i->getKey(),
            'accion' => $url !== null ? ['tipo' => 'Redirigir', 'url' => $url] : null,
            'obligacion' => ['id' => (string) $o->getKey(), 'exigible' => $o->exigible, 'liquidada' => $cobro !== null],
            'cobro' => $cobro ? ['id' => (string) $cobro->getKey(), 'centavos_usd' => $cobro->centavos_usd,
                'moneda' => 'USD', 'confirmado_at' => $cobro->confirmado_en->utc()->format('Y-m-d\TH:i:s.u\Z')] : null,
            'devolucion' => $d ? ['id' => (string) $d->getKey(), 'estado' => $d->estado, 'centavos_usd' => $d->centavos_usd, 'moneda' => 'USD'] : null,
            'puede_reintentar' => $o->exigible && $cobro === null && $this->repo->activo((string) $o->getKey()) === null
                && $this->seguridad->disponible((string) $o->getKey()),
            'operacion_en_verificacion' => $this->repo->escritura((string) $o->getKey()) !== null
                || in_array($i->estado, ['Preparando', 'Procesando', 'Incierto'], true),
            'ultima_verificacion_at' => $i->revisado_en?->utc()->format('Y-m-d\TH:i:s.u\Z')],
            'meta' => ['repetida' => $r['repetida']],
            'status' => $pendiente ? 202 : (! $r['repetida'] && $url !== null ? 201 : 200), 'pendiente' => $pendiente];
    }

    public function consulta(IntentoPago $i): OperacionPago
    {
        $op = $this->repo->porTipo((string) $i->getKey(), 'Consultar');
        if (! $op) {
            return $this->preparar($i, 'Consultar');
        }
        if (in_array($op->estado, ['Exitosa', 'Fallida'], true)) {
            $this->repo->guardar($op, ['estado' => 'Pendiente', 'actualizada_en' => CarbonImmutable::now('UTC'),
                'proxima_ejecucion_en' => CarbonImmutable::now('UTC')]);
        }

        return $op;
    }

    /** Llamada con Cita/obligación bloqueadas; no red ni dependencia de la bandera para trabajo ya adquirido. */
    public function coordinarCancelacion(ObligacionPago $o): void
    {
        if ($o->exigible || ! $o->id_cambio_anulacion || $o->cita->estado !== 'Cancelada'
            || $o->cambioAnulacion->operacion !== 'CANCELACION'
            || (string) $o->cambioAnulacion->id_cita !== (string) $o->id_cita) {
            return;
        }
        $cobro = $this->repo->cobro((string) $o->getKey());
        if ($cobro && $cobro->proveedor === 'stripe') {
            $d = app(DevolucionCancelacionService::class)->preparar($o, $cobro);
            $i = $this->repo->intento((string) $cobro->id_intento);
            if (! $this->repo->escritura((string) $o->getKey()) && ! $this->repo->porTipo((string) $i->getKey(), 'Devolver')) {
                $this->preparar($i, 'Devolver', ['payment_intent' => $i->referencia_pago, 'amount' => $cobro->centavos_usd,
                    'metadata' => ['devolucion' => (string) $d->getKey()]],
                    ['id_devolucion' => $d->getKey(), 'id_cobro' => $cobro->getKey()]);
            }
        } elseif (($i = $this->repo->activo((string) $o->getKey())) && $i->proveedor === 'stripe') {
            if (! $this->repo->escritura((string) $o->getKey())) {
                $this->preparar($i, 'Cerrar');
            } else {
                $this->consulta($i);
            }
        }
    }
}
