<?php

namespace Clinica\business\services\pagos;

use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use LogicException;
use Stripe\ApiRequestor;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Webhook;

/** Única frontera SDK. Las pruebas deben bloquear también su transporte, no solo Http::fake. */
class StripeGateway
{
    public function __construct(private ConfiguracionStripe $configuracion) {}

    public function firma(string $bytes, string $firma): array
    {
        $c = $this->configuracion->exigir();
        try {
            $e = Webhook::constructEvent($bytes, $firma, $c['webhook_secret'], 300)->toArray();
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            throw new AppError('PAGO_FIRMA_INVALIDA', 'No se pudo verificar el evento.');
        }
        if (($e['livemode'] ?? null) !== false || isset($e['account']) || isset($e['context'])
            || ($e['api_version'] ?? '') !== $c['api_version']) {
            throw new AppError('PAGO_EVENTO_INVALIDO', 'El contexto del evento no está permitido.');
        }

        return $e;
    }

    protected function transporte(): CurlClient
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || app()->environment('testing')) {
            throw new LogicException('Las pruebas deben inyectar un transporte Stripe simulado.');
        }

        return new CurlClient([CURLOPT_FOLLOWLOCATION => false]);
    }

    protected function cliente(): StripeClient
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('No se permite red Stripe dentro de una transacción.');
        }
        $c = $this->configuracion->exigir();
        $http = $this->transporte();
        $http->setConnectTimeout(5);
        $http->setTimeout(20);
        ApiRequestor::setHttpClient($http);
        $cliente = new StripeClient(['api_key' => $c['secret_key'], 'stripe_version' => $c['api_version'],
            'max_network_retries' => 0, 'api_base' => 'https://api.stripe.com']);
        if ($cliente->accounts->retrieve()->id !== $c['cuenta']) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'No se pudo verificar el contexto de Stripe.');
        }

        return $cliente;
    }

    public function crear(array $cuerpo, string $clave): array
    {
        return $this->cliente()->checkout->sessions->create($cuerpo, ['idempotency_key' => $clave])->toArray();
    }

    public function sesion(string $id): array
    {
        return $this->cliente()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent.latest_charge']])->toArray();
    }

    public function sesionPorPago(string $pago): array
    {
        $lista = $this->cliente()->checkout->sessions->all(['payment_intent' => $pago, 'limit' => 2])->toArray();
        if (($lista['has_more'] ?? true) !== false || count($lista['data'] ?? []) !== 1) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La sesión requiere verificación.');
        }

        return $this->sesion($lista['data'][0]['id']);
    }

    public function cerrar(string $id, string $clave): array
    {
        return $this->cliente()->checkout->sessions->expire($id, [], ['idempotency_key' => $clave])->toArray();
    }

    public function devolver(array $cuerpo, string $clave): array
    {
        return $this->cliente()->refunds->create($cuerpo, ['idempotency_key' => $clave])->toArray();
    }

    public function devolucion(string $id): array
    {
        return $this->cliente()->refunds->retrieve($id)->toArray();
    }

    public function devoluciones(string $pago): array
    {
        return $this->cliente()->refunds->all(['payment_intent' => $pago, 'limit' => 100])->toArray();
    }
}
