<?php

namespace Clinica\business\validators;

use Illuminate\Validation\ValidationException;

class SuscripcionPushValidator
{
    public function validar(array $datos): array
    {
        $endpoint = $datos['endpoint'];
        if (! $this->endpointValido($endpoint)) {
            throw ValidationException::withMessages(['suscripcion.endpoint' => ['El destino de notificaciones no está admitido.']]);
        }
        $auth = $this->decodificar($datos['keys']['auth']);
        if (! $this->clavePublicaValida($datos['keys']['p256dh']) || $auth === null || strlen($auth) !== 16) {
            throw ValidationException::withMessages(['suscripcion.keys' => ['Las claves de notificación no son válidas.']]);
        }

        return ['endpoint' => $datos['endpoint'], 'expirationTime' => $datos['expirationTime'],
            'keys' => ['p256dh' => $datos['keys']['p256dh'], 'auth' => $datos['keys']['auth']]];
    }

    public function endpointValido(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        $host = $url['host'] ?? '';
        if (! $url || ($url['scheme'] ?? '') !== 'https' || ! in_array($host, config('push.hosts', []), true)
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443) || empty($url['path']) || $url['path'] === '/'
            || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint) !== 0 || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    public function clavePublicaValida(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $p = $this->decodificar($value);
        if ($p === null || strlen($p) !== 65 || $p[0] !== "\x04") {
            return false;
        }
        $pem = $p === null ? '' : "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$p), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = $pem === '' ? false : @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return $details && ($details['ec']['curve_name'] ?? '') === 'prime256v1';
    }

    private function decodificar(string $value): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw !== false && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $value ? $raw : null;
    }
}
