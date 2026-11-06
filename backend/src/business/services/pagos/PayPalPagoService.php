<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\PayPalPagoRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ImportePago;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayPalPagoService
{
    public function __construct(private StripePagoRepository $repo, private PayPalPagoRepository $paypal,
        private ConfiguracionPayPal $configuracion, private AutenticacionService $auth,
        private BitacoraRepository $bitacora, private DevolucionCancelacionService $devoluciones,
        private SeguridadInicioPagoService $seguridad) {}

    public function elegibilidad(ObligacionPago $o, string $actor): array
    {
        try {
            $c = $this->configuracion->exigir();
        } catch (AppError) {
            return ['puede_iniciar_pago' => false, 'revision_pago' => null];
        }
        $puede = $o->exigible && $o->cita->estado !== 'Cancelada' && ! $this->repo->cobro((string) $o->getKey())
            && ! $this->repo->activo((string) $o->getKey()) && ! $this->repo->escritura((string) $o->getKey())
            && $this->seguridad->disponible((string) $o->getKey());

        return ['puede_iniciar_pago' => $puede, 'revision_pago' => $puede ? Crypt::encryptString(json_encode([
            'o' => (string) $o->getKey(), 'a' => $actor, 'usd' => $o->centavos_usd, 'bob' => $o->centavos_bob,
            'paypal_contexto' => $c['contexto'], 'vence' => CarbonImmutable::now('UTC')->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR)) : null];
    }

    private function titular(string $id): array
    {
        $actor = $this->auth->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Paciente' || $actor->paciente === null || $actor->medico !== null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $o = $this->repo->obligacion($id, (string) $actor->paciente->getKey())
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');

        return [$actor, $o];
    }

    public function iniciar(string $id, string $revision, string $clave): array
    {
        $c = $this->configuracion->exigir();

        return DB::transaction(function () use ($id, $revision, $clave, $c): array {
            [$actor] = $this->titular($id);
            $o = $this->repo->bloquear($id);
            $huella = hash('sha256', json_encode(['paypal', $revision], JSON_THROW_ON_ERROR));
            if ($op = $this->repo->replay($id, (string) $actor->getKey(), $clave)) {
                if (! hash_equals($op->huella_solicitud, $huella) || $op->proveedor !== 'paypal') {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }

                return $this->localizador($op, true);
            }
            try {
                $r = json_decode(Crypt::decryptString($revision), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $r = [];
            }
            if (($r['o'] ?? '') !== $id || ($r['a'] ?? '') !== (string) $actor->getKey()
                || ($r['usd'] ?? null) !== $o->centavos_usd || ($r['bob'] ?? null) !== $o->centavos_bob
                || ($r['paypal_contexto'] ?? '') !== $c['contexto'] || ($r['vence'] ?? 0) <= CarbonImmutable::now('UTC')->timestamp) {
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
            $this->repo->guardar($i, ['id_intento' => (string) Str::uuid(), 'id_obligacion' => $id, 'iniciado_por' => $actor->getKey(),
                'proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $c['contexto'], 'captura_permitida' => true,
                'estado' => 'Preparando', 'creado_en' => $ahora, 'actualizado_en' => $ahora, 'proxima_revision_en' => $ahora]);
            $importe = ImportePago::desdeHistorico($o->centavos_bob, $o->centavos_usd, $o->tasa_numerador, $o->tasa_denominador);
            $retorno = $c['origen_retorno'].'/pagos/retorno/paypal?intento='.$i->getKey();
            $cuerpo = ['intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => (string) $i->getKey(),
                'custom_id' => (string) $i->getKey(), 'payee' => ['merchant_id' => $c['merchant_id']],
                'amount' => ['currency_code' => 'USD', 'value' => $importe->montoPayPal()]]],
                'payment_source' => ['paypal' => ['experience_context' => ['shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW', 'return_url' => $retorno, 'cancel_url' => $retorno]]]];
            $op = $this->preparar($i, 'Crear', $cuerpo, ['solicitada_por' => $actor->getKey(),
                'clave_solicitud' => $clave, 'huella_solicitud' => $huella]);
            $this->bitacora->pago('PAGO_INTENTO_PREPARADO', $id, ['id_intento' => (string) $i->getKey(), 'iniciado_por' => (string) $actor->getKey()], $actor);

            return $this->localizador($op, false);
        });
    }

    public function solicitar(string $id, string $tipo, string $clave): array
    {
        $c = $this->configuracion->exigir();
        if ($tipo === 'Capturar') {
            // Autorizar antes de red, sin reservar un intento de captura si falta aprobación.
            $i = DB::transaction(function () use ($id, $c) {
                $i = $this->repo->intento($id) ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                $this->titular((string) $i->id_obligacion);
                if ($i->proveedor !== 'paypal' || $i->cuenta_contexto !== $c['contexto']) {
                    throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'El intento no corresponde al proveedor disponible.');
                }

                return $i;
            });
            if ($i->captura_permitida && $i->referencia_inicio && ! $this->repo->porTipo($id, 'Capturar')) {
                [$s, $capturas] = app(RecuperacionPayPalService::class)->obtener($i->referencia_inicio);
                app(EvidenciaPayPalService::class)->validar($this->repo->obligacion((string) $i->id_obligacion), $i, $s, $capturas);
                if (($s['status'] ?? '') !== 'APPROVED' || $capturas) {
                    throw new AppError('PAGO_APROBACION_REQUERIDA', 'La orden requiere aprobación antes de capturar.');
                }
            }
        }

        return DB::transaction(function () use ($id, $tipo, $clave, $c): array {
            $i = $this->repo->intento($id) ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            [$actor] = $this->titular((string) $i->id_obligacion);
            $o = $this->repo->bloquear((string) $i->id_obligacion);
            $i->refresh();
            if ($i->proveedor !== 'paypal' || $i->cuenta_contexto !== $c['contexto']) {
                throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'El intento no corresponde al proveedor disponible.');
            }
            if ($op = $this->paypal->solicitud($id, (string) $actor->getKey(), $clave)) {
                if (($op->datos_solicitud_cifrados['solicitud'] ?? $op->tipo) !== $tipo) {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }

                return $this->localizador($op, true);
            }
            if ($tipo === 'Capturar') {
                if (! $o->exigible || $o->cita->estado === 'Cancelada' || ! $i->captura_permitida) {
                    throw new AppError('PAGO_CAPTURA_NO_PERMITIDA', 'No se permite iniciar la captura.');
                }
                if ($this->repo->porTipo($id, 'Capturar') || $this->repo->escritura((string) $o->getKey()) || $this->repo->cobro((string) $o->getKey())) {
                    throw new AppError('PAGO_OPERACION_ACTIVA', 'Existe un pago o una operación en verificación.');
                }
                // La aprobación se comprueba por API fuera de esta transacción, antes del envío.
            }
            if ($tipo === 'Cerrar') {
                $this->repo->guardar($i, ['captura_permitida' => false, 'actualizado_en' => CarbonImmutable::now('UTC')]);
            }
            $op = $this->preparar($i, $tipo === 'Cerrar' ? 'Consultar' : $tipo, ['solicitud' => $tipo],
                ['solicitada_por' => $actor->getKey(), 'clave_solicitud' => $clave]);

            return $this->localizador($op, true);
        });
    }

    public function preparar(IntentoPago $i, string $tipo, array $cuerpo = [], array $extra = []): OperacionPago
    {
        $op = new OperacionPago;
        $ahora = CarbonImmutable::now('UTC');
        $this->repo->guardar($op, ['id_operacion' => (string) Str::uuid(), 'id_intento' => $i->getKey(), 'id_obligacion' => $i->id_obligacion,
            'proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $i->cuenta_contexto, 'tipo' => $tipo,
            'huella_solicitud' => hash('sha256', json_encode($cuerpo, JSON_THROW_ON_ERROR)), 'datos_solicitud_cifrados' => $cuerpo,
            'clave_externa' => $tipo === 'Consultar' ? null : (string) Str::uuid(), 'estado' => 'Pendiente',
            'creada_en' => $ahora, 'actualizada_en' => $ahora, 'proxima_ejecucion_en' => $ahora, ...$extra]);

        return $op;
    }

    private function localizador(OperacionPago $op, bool $repetida): array
    {
        return ['operacion' => (string) $op->getKey(), 'intento' => (string) $op->id_intento, 'repetida' => $repetida];
    }

    public function consulta(IntentoPago $i, array $pistas = []): OperacionPago
    {
        if ($pistas === [] && ($op = $this->repo->porTipo((string) $i->getKey(), 'Consultar'))) {
            if (in_array($op->estado, ['Exitosa', 'Fallida'], true)) {
                $this->repo->guardar($op, ['estado' => 'Pendiente', 'actualizada_en' => CarbonImmutable::now('UTC'),
                    'proxima_ejecucion_en' => CarbonImmutable::now('UTC')]);
            }

            return $op;
        }

        return $this->preparar($i, 'Consultar', $pistas);
    }

    public function coordinarCancelacion(ObligacionPago $o): void
    {
        if ($o->exigible || ! $o->id_cambio_anulacion || $o->cita->estado !== 'Cancelada'
            || $o->cambioAnulacion->operacion !== 'CANCELACION' || (string) $o->cambioAnulacion->id_cita !== (string) $o->id_cita) {
            return;
        }
        $i = $this->repo->activo((string) $o->getKey());
        if ($i?->proveedor === 'paypal') {
            $this->repo->guardar($i, ['captura_permitida' => false, 'actualizado_en' => CarbonImmutable::now('UTC')]);
            $this->consulta($i);
        }
        $cobro = $this->repo->cobro((string) $o->getKey());
        if ($cobro?->proveedor === 'paypal') {
            $d = $this->devoluciones->preparar($o, $cobro);
            $i = $this->repo->intento((string) $cobro->id_intento);
            if (! $this->repo->escritura((string) $o->getKey()) && ! $this->repo->porTipo((string) $i->getKey(), 'Devolver')) {
                $this->preparar($i, 'Devolver', [], ['id_cobro' => $cobro->getKey(), 'id_devolucion' => $d->getKey()]);
            }
        }
    }

    public function respuesta(array $r, PayPalGateway $gateway): array
    {
        $i = $this->repo->intento($r['intento']);
        $o = $this->repo->obligacion((string) $i->id_obligacion);
        $op = $this->repo->operacion($r['operacion']);
        $url = null;
        $requiereUrl = false;
        if ($op->estado === 'Exitosa' && $o->exigible && $i->captura_permitida && $i->estado === 'RequiereAccion' && $i->referencia_inicio) {
            $requiereUrl = true;
            try {
                $s = $gateway->orden($i->referencia_inicio);
                app(EvidenciaPayPalService::class)->validar($o, $i, $s, []);
                $requiereUrl = in_array($s['status'], ['CREATED', 'PAYER_ACTION_REQUIRED'], true);
                if (in_array($s['status'], ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
                    foreach ($s['links'] ?? [] as $link) {
                        if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)
                            && is_string($link['href'] ?? null) && preg_match('~\Ahttps://www\.sandbox\.paypal\.com/[^\s#]*\z~', $link['href'])
                            && $this->repo->obligacion((string) $o->getKey())->exigible) {
                            parse_str(parse_url($link['href'], PHP_URL_QUERY) ?? '', $query);
                            if (($query['token'] ?? '') !== $i->referencia_inicio) {
                                continue;
                            }
                            $url = $link['href'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Sin enlace corroborado no se expone una URL especulativa.
            }
        }
        $cobro = $this->repo->cobro((string) $o->getKey());
        $d = $cobro ? $this->repo->devolucion((string) $cobro->getKey()) : null;
        $pendiente = in_array($op->estado, ['Pendiente', 'Procesando', 'Incierta'], true)
            || in_array($i->estado, ['Preparando', 'Procesando', 'Incierto'], true)
            || ($requiereUrl && $url === null);

        return ['data' => ['id' => (string) $i->getKey(), 'obligacion_id' => (string) $o->getKey(), 'proveedor' => 'paypal',
            'estado' => $i->estado, 'aplicado' => $cobro !== null && (string) $cobro->id_intento === (string) $i->getKey(),
            'accion' => ! $pendiente && $url !== null ? ['tipo' => 'Redirigir', 'url' => $url] : null,
            'obligacion' => ['id' => (string) $o->getKey(), 'exigible' => $o->exigible, 'liquidada' => $cobro !== null],
            'cobro' => $cobro ? ['id' => (string) $cobro->getKey(), 'centavos_usd' => $cobro->centavos_usd, 'moneda' => 'USD'] : null,
            'devolucion' => $d ? ['id' => (string) $d->getKey(), 'estado' => $d->estado, 'centavos_usd' => $d->centavos_usd, 'moneda' => 'USD'] : null,
            'puede_reintentar' => $o->exigible && $this->seguridad->disponible((string) $o->getKey()),
            'operacion_en_verificacion' => $this->repo->escritura((string) $o->getKey()) !== null || in_array($i->estado, ['Preparando', 'Procesando', 'Incierto'], true),
            'ultima_verificacion_at' => $i->revisado_en?->utc()->format('Y-m-d\TH:i:s.u\Z')],
            'meta' => ['repetida' => $r['repetida']], 'status' => $pendiente ? 202 : (! $r['repetida'] && $url !== null ? 201 : 200), 'pendiente' => $pendiente];
    }
}
