<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Solo tests: simula también OAuth y el postback, nunca altera el receptor productivo. */
class PayPalHttpSimulado
{
    public array $ordenes = [];

    public array $capturas = [];

    public array $devoluciones = [];

    public array $claves = [];

    public array $llamadas = [];

    public array $oauth = ['access_token' => 'TokenFicticioNoValido', 'token_type' => 'Bearer', 'expires_in' => 3600, 'app_id' => 'APP-Ficticia'];

    public string $firma = 'SUCCESS';

    public bool $verificacionCaida = false;

    public ?string $perder = null;

    public ?\Closure $duranteCaptura = null;

    public ?\Closure $antesOrden = null;

    public string $verificacionBody = '';

    public function instalar(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->responder($r));
    }

    public function aprobar(): void
    {
        foreach ($this->ordenes as &$s) {
            $s['status'] = 'APPROVED';
        }
    }

    public function cobrar(string $orden): void
    {
        $id = 'CAP-'.(count($this->capturas) + 1);
        $u = $this->ordenes[$orden]['purchase_units'][0];
        $this->capturas[$id] = ['id' => $id, 'status' => 'COMPLETED', 'amount' => $u['amount'], 'final_capture' => true,
            'payee' => $u['payee'], 'supplementary_data' => ['related_ids' => ['order_id' => $orden]],
            'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$orden]],
            'seller_receivable_breakdown' => ['gross_amount' => $u['amount']]];
        $this->ordenes[$orden]['status'] = 'COMPLETED';
        $this->ordenes[$orden]['purchase_units'][0]['payments']['captures'][] = ['id' => $id];
    }

    public function contar(string $metodo, string $ruta): int
    {
        return count(array_filter($this->llamadas, fn ($r) => $r['metodo'] === $metodo && $r['ruta'] === $ruta));
    }

    private function respuesta(array $datos, string $tipo = ''): mixed
    {
        if ($tipo !== '' && $this->perder === $tipo) {
            $this->perder = null;
            throw new ConnectionException('Timeout simulado sin información externa.');
        }

        return Http::response($datos);
    }

    public function responder(Request $r): mixed
    {
        if (DB::transactionLevel() !== 0 || ! str_starts_with($r->url(), 'https://api-m.sandbox.paypal.com/')) {
            throw new \LogicException('Red no simulada o SQL abierto.');
        }
        $ruta = parse_url($r->url(), PHP_URL_PATH);
        $metodo = $r->method();
        $this->llamadas[] = ['ruta' => $ruta, 'metodo' => $metodo, 'transaccion' => DB::transactionLevel()];
        if ($ruta === '/v1/oauth2/token') {
            return Http::response($this->oauth);
        }
        if ($ruta === '/v1/notifications/verify-webhook-signature') {
            $this->verificacionBody = $r->body();
            if ($this->verificacionCaida) {
                throw new ConnectionException('Verificación simulada incierta.');
            }

            return Http::response(['verification_status' => $this->firma]);
        }
        $clave = $r->header('PayPal-Request-Id')[0] ?? '';
        if ($metodo === 'POST') {
            if ($clave === '' || DB::table('operaciones_pago')->where('clave_externa', $clave)->where('estado', 'Procesando')->doesntExist()) {
                throw new \LogicException('Falta preparación durable antes de red.');
            }
            if (isset($this->claves[$ruta.':'.$clave])) {
                return Http::response($this->claves[$ruta.':'.$clave]);
            }
            if ($ruta === '/v2/checkout/orders') {
                $id = 'ORD-'.(count($this->ordenes) + 1);
                $s = ['id' => $id, 'intent' => 'CAPTURE', 'status' => 'CREATED', 'purchase_units' => $r->data()['purchase_units'],
                    'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$id]]];
                $this->ordenes[$id] = $s;
                $this->claves[$ruta.':'.$clave] = ['id' => $id];

                return $this->respuesta(['id' => $id], 'Crear');
            }
            if (preg_match('~/v2/checkout/orders/([^/]+)/capture\z~', $ruta, $m)) {
                if ($this->duranteCaptura) {
                    ($this->duranteCaptura)();
                }
                if (! isset($this->ordenes[$m[1]]['purchase_units'][0]['payments']['captures'])) {
                    $this->cobrar($m[1]);
                }
                $this->claves[$ruta.':'.$clave] = $this->ordenes[$m[1]];

                return $this->respuesta($this->ordenes[$m[1]], 'Capturar');
            }
            if (preg_match('~/v2/payments/captures/([^/]+)/refund\z~', $ruta, $m)) {
                if ($r->body() !== '{}') {
                    throw new \LogicException('Refund no total.');
                }
                $id = 'REF-'.(count($this->devoluciones) + 1);
                $d = ['id' => $id, 'status' => 'COMPLETED', 'amount' => $this->capturas[$m[1]]['amount'],
                    'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/'.$m[1]]]];
                $this->devoluciones[$id] = $d;
                $this->capturas[$m[1]]['status'] = 'REFUNDED';
                $this->claves[$ruta.':'.$clave] = ['id' => $id];

                return $this->respuesta(['id' => $id], 'Devolver');
            }
        }
        if ($metodo === 'GET') {
            if ($this->antesOrden && str_starts_with($ruta, '/v2/checkout/orders/')) {
                ($this->antesOrden)();
            }
            if (preg_match('~/v2/checkout/orders/([^/]+)\z~', $ruta, $m) && isset($this->ordenes[$m[1]])) {
                return Http::response($this->ordenes[$m[1]]);
            }
            if (preg_match('~/v2/payments/captures/([^/]+)\z~', $ruta, $m) && isset($this->capturas[$m[1]])) {
                return Http::response($this->capturas[$m[1]]);
            }
            if (preg_match('~/v2/payments/refunds/([^/]+)\z~', $ruta, $m) && isset($this->devoluciones[$m[1]])) {
                return Http::response($this->devoluciones[$m[1]]);
            }
        }
        throw new \LogicException('Petición Sandbox no simulada.');
    }
}
