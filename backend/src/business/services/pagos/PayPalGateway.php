<?php

namespace Clinica\business\services\pagos;

use Clinica\shared\errors\AppError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** REST Sandbox fijo. No SDK, URLs de eventos, redirects ni retries financieros. */
class PayPalGateway
{
    private const HOST = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    private int $vence = 0;

    private string $contexto = '';

    public function __construct(private ConfiguracionPayPal $configuracion) {}

    private function http(): PendingRequest
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('PayPal requiere red fuera de transacciones.');
        }

        return Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    private function cliente(): PendingRequest
    {
        $c = $this->configuracion->exigir();
        if ($this->token === null || $this->vence <= time() || $this->contexto !== $c['contexto']) {
            try {
                $r = $this->http()->asForm()->withBasicAuth($c['client_id'], $c['client_secret'])
                    ->post(self::HOST.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
                $d = $r->json();
                if (! $r->successful() || ! is_array($d) || ($d['token_type'] ?? '') !== 'Bearer'
                    || ! is_string($d['access_token'] ?? null) || strlen($d['access_token']) > 4096
                    || ! preg_match('/\A[\x21-\x7E]+\z/', $d['access_token'])
                    || ! is_int($d['expires_in'] ?? null) || $d['expires_in'] < 60
                    || ($d['app_id'] ?? '') !== $c['app_id']) {
                    throw new \RuntimeException;
                }
                $this->token = $d['access_token'];
                $this->vence = time() + min(3600, $d['expires_in']) - 30;
                $this->contexto = $c['contexto'];
            } catch (\Throwable) {
                $this->token = null;
                throw new AppError('PAGO_VERIFICACION_NO_DISPONIBLE', 'No se pudo verificar el servicio Sandbox.');
            }
        }

        return $this->http()->withToken($this->token)->asJson()->withHeaders(['Prefer' => 'return=representation']);
    }

    private function enviar(string $metodo, string $ruta, ?array $cuerpo = null, ?string $clave = null): array
    {
        try {
            $h = $this->cliente();
            if ($clave !== null) {
                $h = $h->withHeaders(['PayPal-Request-Id' => $clave]);
            }
            $r = $metodo === 'GET' ? $h->get(self::HOST.$ruta)
                : $h->withBody($cuerpo === [] ? '{}' : json_encode($cuerpo, JSON_THROW_ON_ERROR), 'application/json')->post(self::HOST.$ruta);
            if ($r->status() === 429 || $r->serverError()) {
                $espera = $r->header('Retry-After');
                $segundos = preg_match('/\A[0-9]{1,9}\z/', $espera) ? (int) $espera
                    : (($fecha = strtotime($espera)) !== false ? max(0, $fecha - time()) : 60);
                throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'El resultado Sandbox requiere verificación.', max(60, $segundos));
            }
            if (! $r->successful() || strlen($r->body()) > 1048576 || ! is_array($r->json())) {
                throw new \RuntimeException;
            }

            return $r->json();
        } catch (AppError $e) {
            throw $e;
        } catch (\Throwable) {
            throw new AppError('PAYPAL_RESULTADO_INCIERTO', 'El resultado Sandbox requiere verificación.');
        }
    }

    public function referencia(string $id): string
    {
        if (! preg_match('/\A[A-Za-z0-9-]{3,100}\z/', $id)) {
            throw new AppError('PAGO_EVIDENCIA_INCOMPATIBLE', 'La referencia requiere verificación.');
        }

        return $id;
    }

    public function crear(array $cuerpo, string $clave): array
    {
        return $this->enviar('POST', '/v2/checkout/orders', $cuerpo, $clave);
    }

    public function orden(string $id): array
    {
        return $this->enviar('GET', '/v2/checkout/orders/'.$this->referencia($id));
    }

    public function capturar(string $id, string $clave): array
    {
        return $this->enviar('POST', '/v2/checkout/orders/'.$this->referencia($id).'/capture', [], $clave);
    }

    public function captura(string $id): array
    {
        return $this->enviar('GET', '/v2/payments/captures/'.$this->referencia($id));
    }

    public function devolver(string $id, string $clave): array
    {
        return $this->enviar('POST', '/v2/payments/captures/'.$this->referencia($id).'/refund', [], $clave);
    }

    public function devolucion(string $id): array
    {
        return $this->enviar('GET', '/v2/payments/refunds/'.$this->referencia($id));
    }

    public function firma(string $bytes, array $cabeceras): array
    {
        $c = $this->configuracion->exigir();
        if (strlen($bytes) > 1048576) {
            throw new AppError('PAGO_EVENTO_GRANDE', 'El evento excede el tamaño permitido.');
        }
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $k) {
            if (! is_string($cabeceras[$k] ?? null) || strlen($cabeceras[$k]) > 4096 || $cabeceras[$k] === ''
                || preg_match('/[\x00-\x1F\x7F]/', $cabeceras[$k])) {
                throw new AppError('PAGO_FIRMA_INVALIDA', 'No se pudo verificar el evento.');
            }
        }
        if ($cabeceras['auth_algo'] !== 'SHA256withRSA'
            || ! preg_match('~\Ahttps://(?:api|api-m)\.sandbox\.paypal\.com/v1/notifications/certs/[A-Za-z0-9_-]+\z~', $cabeceras['cert_url'])) {
            throw new AppError('PAGO_FIRMA_INVALIDA', 'No se pudo verificar el evento.');
        }
        try {
            $evento = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($evento) || ! str_starts_with(ltrim($bytes), '{')) {
                throw new \JsonException;
            }
        } catch (\JsonException) {
            throw new AppError('PAGO_EVENTO_INVALIDO', 'El evento no es válido.');
        }
        // Se inserta el objeto original, sin reconstruirlo ni descargar CERT-URL.
        $envoltura = json_encode([...$cabeceras, 'webhook_id' => $c['webhook_id']], JSON_THROW_ON_ERROR);
        try {
            $r = $this->cliente()->withBody(substr($envoltura, 0, -1).',"webhook_event":'.$bytes.'}', 'application/json')
                ->post(self::HOST.'/v1/notifications/verify-webhook-signature');
            if (! $r->successful() || ! in_array($r->json('verification_status'), ['SUCCESS', 'FAILURE'], true)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new AppError('PAGO_VERIFICACION_NO_DISPONIBLE', 'No se pudo verificar el evento Sandbox.');
        }
        if ($r->json('verification_status') !== 'SUCCESS') {
            throw new AppError('PAGO_FIRMA_INVALIDA', 'No se pudo verificar el evento.');
        }

        return $evento;
    }
}
