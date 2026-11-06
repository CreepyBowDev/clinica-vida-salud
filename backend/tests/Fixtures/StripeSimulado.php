<?php

namespace Tests\Fixtures;

use Clinica\business\services\pagos\StripeGateway;
use Illuminate\Support\Facades\DB;

class StripeSimulado extends StripeGateway
{
    public array $sesiones = [];

    public array $reembolsos = [];

    public array $llamadas = [];

    public array $fallos = [];

    public ?\Closure $duranteRed = null;

    private function registrar(string $metodo, array $datos): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Red simulada bajo transacción.');
        }
        $this->llamadas[] = [$metodo, $datos];
        if ($this->duranteRed) {
            ($this->duranteRed)($metodo, $datos);
        }
        if (($this->fallos[$metodo] ?? 0) > 0) {
            $this->fallos[$metodo]--;
            throw new \RuntimeException('Timeout simulado, sin datos externos.');
        }
    }

    public function crear(array $cuerpo, string $clave): array
    {
        $this->registrar('crear', [$cuerpo, $clave]);
        $id = 'cs_test_'.str_replace('-', '', $clave);
        $this->sesiones[$id] ??= ['id' => $id, 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
            'amount_total' => $cuerpo['line_items'][0]['price_data']['unit_amount'], 'currency' => 'usd',
            'client_reference_id' => $cuerpo['client_reference_id'], 'metadata' => $cuerpo['metadata'],
            'payment_method_types' => ['card'], 'payment_intent' => null, 'url' => 'https://checkout.stripe.com/c/pay/ficticio'];
        if (($this->fallos['crear_despues'] ?? 0) > 0) {
            $this->fallos['crear_despues']--;
            throw new \RuntimeException('Respuesta perdida después de aceptar la creación simulada.');
        }

        return $this->sesiones[$id];
    }

    public function sesion(string $id): array
    {
        $this->registrar('sesion', [$id]);

        return $this->sesiones[$id];
    }

    public function sesionPorPago(string $pago): array
    {
        $this->registrar('sesionPorPago', [$pago]);
        foreach ($this->sesiones as $s) {
            if (($s['payment_intent']['id'] ?? '') === $pago) {
                return $s;
            }
        }
        throw new \RuntimeException('Sin sesión simulada.');
    }

    public function cerrar(string $id, string $clave): array
    {
        $this->registrar('cerrar', [$id, $clave]);
        $this->sesiones[$id]['status'] = 'expired';

        return $this->sesiones[$id];
    }

    public function devolver(array $cuerpo, string $clave): array
    {
        $this->registrar('devolver', [$cuerpo, $clave]);
        $id = 're_'.str_replace('-', '', $clave);
        $this->reembolsos[$id] ??= ['id' => $id, 'object' => 'refund', 'payment_intent' => $cuerpo['payment_intent'],
            'charge' => 'ch_'.substr($cuerpo['payment_intent'], 3), 'amount' => $cuerpo['amount'],
            'currency' => 'usd', 'metadata' => $cuerpo['metadata'], 'status' => 'succeeded'];

        return $this->reembolsos[$id];
    }

    public function devolucion(string $id): array
    {
        $this->registrar('devolucion', [$id]);

        return $this->reembolsos[$id];
    }

    public function devoluciones(string $pago): array
    {
        $this->registrar('devoluciones', [$pago]);

        return ['has_more' => false, 'data' => array_values(array_filter($this->reembolsos, fn ($r) => $r['payment_intent'] === $pago))];
    }

    public function pagar(string $id): array
    {
        $s = &$this->sesiones[$id];
        $ref = substr($id, 8);
        $s['status'] = 'complete';
        $s['payment_status'] = 'paid';
        $s['payment_intent'] = ['id' => 'pi_'.$ref, 'object' => 'payment_intent', 'livemode' => false,
            'status' => 'succeeded', 'amount' => $s['amount_total'], 'amount_received' => $s['amount_total'],
            'currency' => 'usd', 'metadata' => $s['metadata'], 'latest_charge' => ['id' => 'ch_'.$ref,
                'object' => 'charge', 'livemode' => false, 'paid' => true, 'captured' => true, 'status' => 'succeeded',
                'payment_intent' => 'pi_'.$ref, 'amount' => $s['amount_total'], 'amount_captured' => $s['amount_total'], 'currency' => 'usd']];

        return $s;
    }
}
