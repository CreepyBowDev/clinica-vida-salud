<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\EventoPago;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventoStripeService
{
    private const TIPOS = ['checkout.session.completed', 'checkout.session.expired', 'payment_intent.succeeded',
        'payment_intent.payment_failed', 'refund.created', 'refund.updated', 'refund.failed'];

    public function __construct(private StripeGateway $gateway, private StripePagoRepository $repo,
        private StripePagoService $pagos, private RecuperacionStripeService $recuperacion) {}

    public function recibir(string $bytes, string $firma): void
    {
        if (strlen($bytes) > 1048576 || strlen($firma) > 4096) {
            throw new AppError('PAGO_EVENTO_GRANDE', 'El evento excede el tamaño permitido.');
        }
        $e = $this->gateway->firma($bytes, $firma);
        if (! is_string($e['id'] ?? null) || ! preg_match('/\Aevt_[A-Za-z0-9]{1,240}\z/', $e['id'])
            || ! is_string($e['type'] ?? null) || strlen($e['type']) > 128 || $e['type'] === '') {
            throw new AppError('PAGO_EVENTO_INVALIDO', 'El evento no es válido.');
        }
        $obj = $e['data']['object'] ?? [];
        if (isset($obj['livemode']) && $obj['livemode'] !== false) {
            throw new AppError('PAGO_EVENTO_INVALIDO', 'El contexto del evento no está permitido.');
        }
        $refs = [];
        foreach (['intento', 'obligacion'] as $k) {
            if (is_string($obj['metadata'][$k] ?? null) && Str::isUuid($obj['metadata'][$k])) {
                $refs[$k] = $obj['metadata'][$k];
            }
        }
        if (($obj['object'] ?? '') === 'checkout.session' && is_string($obj['id'] ?? null)) {
            $refs['sesion'] = substr($obj['id'], 0, 255);
        }
        if (($obj['object'] ?? '') === 'payment_intent' && is_string($obj['id'] ?? null)) {
            $refs['pago'] = substr($obj['id'], 0, 255);
        } elseif (is_string($obj['payment_intent'] ?? null)) {
            $refs['pago'] = substr($obj['payment_intent'], 0, 255);
        }
        try {
            DB::transaction(function () use ($bytes, $e, $refs): void {
                $cuenta = config('pagos.stripe.cuenta');
                $this->repo->serializarEvento($cuenta, $e['id']);
                $previo = $this->repo->evento($cuenta, $e['id']);
                $huella = hash('sha256', $bytes);
                if ($previo) {
                    if (! hash_equals($previo->huella_contenido, $huella)) {
                        throw new AppError('PAGO_EVENTO_CONFLICTO', 'El evento requiere revisión.');
                    }

                    return;
                }
                $ev = new EventoPago;
                $this->repo->guardar($ev, ['id_evento' => (string) Str::uuid(), 'proveedor' => 'stripe', 'entorno' => 'test',
                    'cuenta_contexto' => $cuenta, 'id_externo' => $e['id'], 'tipo' => $e['type'], 'huella_contenido' => $huella,
                    'referencias' => (object) $refs, 'estado' => in_array($e['type'], self::TIPOS, true) ? 'Pendiente' : 'Ignorado',
                    'recepcion_en' => CarbonImmutable::now('UTC'), 'proxima_ejecucion_en' => CarbonImmutable::now('UTC')]);
            });
        } catch (AppError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new AppError('PAGO_EVENTO_NO_PERSISTIDO', 'No se pudo conservar el evento.');
        }
    }

    public function lote(int $limite = 100): int
    {
        if ($limite < 1 || $limite > 1000) {
            throw new \InvalidArgumentException('Lote fuera de rango.');
        }
        $c = app(ConfiguracionStripe::class)->exigir();
        $eventos = $this->repo->eventos($limite, $c['cuenta']);
        foreach ($eventos as $ev) {
            $ev = app(ProcesamientoEventoPagoService::class)->reclamar($ev);
            if (! $ev) {
                continue;
            }
            $i = $this->repo->vincular($ev->referencias, $ev->cuenta_contexto);
            $op = null;
            if ($i) {
                $op = DB::transaction(function () use ($i, $ev) {
                    $this->repo->bloquear((string) $i->id_obligacion);
                    // El ID firmado es pista, no evidencia de cobro: será recuperado por SDK.
                    if (! $i->referencia_inicio && isset($ev->referencias['sesion'])) {
                        return $this->pagos->preparar($i, 'Consultar', ['sesion' => $ev->referencias['sesion']]);
                    }
                    if (! $i->referencia_inicio && isset($ev->referencias['pago'])) {
                        return $this->pagos->preparar($i, 'Consultar', ['pago' => $ev->referencias['pago']]);
                    }

                    return $this->pagos->consulta($i);
                });
                $this->recuperacion->ejecutar((string) $op->getKey());
            }
            app(ProcesamientoEventoPagoService::class)->finalizar($ev, $i, $op);
        }

        return count($eventos);
    }
}
