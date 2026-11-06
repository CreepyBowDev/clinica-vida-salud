<?php

namespace Clinica\business\services\pagos;

use Clinica\shared\errors\AppError;

class ConfiguracionPayPal
{
    public function exigir(): array
    {
        $c = config('pagos.paypal', []);
        $ok = ($c['habilitado'] ?? false) === true && app()->environment(['local', 'testing'])
            && ($c['environment'] ?? '') === 'sandbox';
        foreach (['client_id', 'client_secret', 'app_id', 'merchant_id', 'webhook_id'] as $k) {
            $ok = $ok && is_string($c[$k] ?? null) && preg_match('/\A[A-Za-z0-9_-]{3,200}\z/', $c[$k]);
        }
        $origen = $c['origen_retorno'] ?? '';
        if (! $ok || ! is_string($origen) || ! preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z~', $origen)
            || ! in_array($origen, config('cors.allowed_origins', []), true)) {
            throw new AppError('PAGO_NO_CONFIGURADO', 'PayPal Sandbox no está disponible.');
        }
        // El contexto identifica conjuntamente merchant y app; no contiene credenciales.
        $c['contexto'] = 'sandbox:'.hash('sha256', $c['client_id'].'|'.$c['app_id'].'|'.$c['merchant_id']);

        return $c;
    }
}
