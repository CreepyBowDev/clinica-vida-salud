<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\EventoPago;
use Clinica\data\repositories\pagos\PayPalPagoRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventoPayPalService
{
    private const TIPOS = ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'];

    public function __construct(private PayPalGateway $gateway, private ConfiguracionPayPal $configuracion,
        private PayPalPagoRepository $paypal, private StripePagoRepository $repo,
        private PayPalPagoService $pagos, private RecuperacionPayPalService $recuperacion) {}

    public function recibir(string $bytes, array $cabeceras): void
    {
        $e = $this->gateway->firma($bytes, $cabeceras);
        if (! is_string($e['id'] ?? null) || ! preg_match('/\A[A-Za-z0-9-]{3,255}\z/', $e['id'])
            || ! is_string($e['event_type'] ?? null) || ! preg_match('/\A[A-Z0-9._-]{1,128}\z/', $e['event_type'])) {
            throw new AppError('PAGO_EVENTO_INVALIDO', 'El evento no es válido.');
        }
        $r = $e['resource'] ?? [];
        $refs = [];
        $orden = $r['supplementary_data']['related_ids']['order_id'] ?? null;
        if ($e['event_type'] === 'CHECKOUT.ORDER.APPROVED') {
            $orden = $r['id'] ?? null;
            $id = $r['purchase_units'][0]['custom_id'] ?? '';
            if (is_string($id) && Str::isUuid($id)) {
                $refs['intento'] = $id;
            }
        } elseif (str_starts_with($e['event_type'], 'PAYMENT.CAPTURE.')) {
            $refs['captura'] = $r['id'] ?? null;
            if ($e['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') {
                $refs['devolucion'] = $r['id'] ?? null;
                $refs['captura'] = $r['supplementary_data']['related_ids']['capture_id'] ?? null;
                foreach ($r['links'] ?? [] as $l) {
                    if (($l['rel'] ?? '') === 'up' && preg_match('~\Ahttps://(?:api|api-m)\.sandbox\.paypal\.com/v2/payments/captures/([A-Za-z0-9-]{3,100})\z~', $l['href'] ?? '', $m)) {
                        $refs['captura'] = $m[1];
                    }
                }
            }
        }
        $refs['orden'] = $orden;
        $refs = array_filter($refs, fn ($v) => is_string($v) && preg_match('/\A[A-Za-z0-9-]{3,100}\z/', $v));
        $c = $this->configuracion->exigir();
        try {
            DB::transaction(function () use ($bytes, $e, $refs, $c): void {
                $this->paypal->serializar($c['contexto'], $e['id']);
                $previo = $this->paypal->evento($c['contexto'], $e['id']);
                $huella = hash('sha256', $bytes);
                if ($previo) {
                    if (! hash_equals($previo->huella_contenido, $huella)) {
                        throw new AppError('PAGO_EVENTO_CONFLICTO', 'El evento requiere revisión.');
                    }

                    return;
                }
                $ev = new EventoPago;
                $this->repo->guardar($ev, ['id_evento' => (string) Str::uuid(), 'proveedor' => 'paypal', 'entorno' => 'sandbox',
                    'cuenta_contexto' => $c['contexto'], 'id_externo' => $e['id'], 'tipo' => $e['event_type'], 'huella_contenido' => $huella,
                    'referencias' => (object) $refs,
                    'estado' => $e['event_type'] === 'PAYMENT.CAPTURE.REVERSED' ? 'Cuarentena'
                        : (in_array($e['event_type'], self::TIPOS, true) ? 'Pendiente' : 'Ignorado'),
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
        $c = $this->configuracion->exigir();
        $eventos = $this->paypal->eventos($c['contexto'], $limite);
        foreach ($eventos as $ev) {
            $ev = app(ProcesamientoEventoPagoService::class)->reclamar($ev);
            if (! $ev) {
                continue;
            }
            $i = $this->paypal->vincular($c['contexto'], $ev->referencias);
            $op = null;
            if ($i) {
                $op = DB::transaction(function () use ($i, $ev) {
                    $this->repo->bloquear((string) $i->id_obligacion);
                    $refs = $ev->referencias;
                    if (isset($refs['devolucion'], $refs['captura'])) {
                        $d = $this->repo->porTipo((string) $i->getKey(), 'Devolver');
                        if ($d && $d->cobro->referencia_cobro === $refs['captura']
                            && ($d->referencia_externa === null || $d->referencia_externa === $refs['devolucion'])) {
                            // Pista firmada solamente: GET verificará importe, estado y captura antes de efectos.
                            $this->repo->guardar($d, ['referencia_externa' => $refs['devolucion']]);

                            return $d;
                        }
                    }

                    return $this->pagos->consulta($i, ! $i->referencia_inicio && isset($refs['orden']) ? ['orden' => $refs['orden']] : []);
                });
                $this->recuperacion->ejecutar((string) $op->getKey());
            }
            app(ProcesamientoEventoPagoService::class)->finalizar($ev, $i, $op);
        }

        return count($eventos);
    }
}
