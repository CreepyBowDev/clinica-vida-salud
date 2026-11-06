<?php

namespace Clinica\business\services\pagos;

use Clinica\shared\errors\AppError;

class ConfiguracionStripe
{
    public function exigir(): array
    {
        $c = config('pagos.stripe', []);
        $origen = $c['origen_retorno'] ?? '';
        if (($c['habilitado'] ?? false) !== true || ! app()->environment(['local', 'testing'])
            || ! preg_match('/\Ask_test_[A-Za-z0-9]+\z/', $c['secret_key'] ?? '')
            || ! preg_match('/\Aacct_[A-Za-z0-9]+\z/', $c['cuenta'] ?? '')
            || ! preg_match('/\Awhsec_[A-Za-z0-9]+\z/', $c['webhook_secret'] ?? '')
            || ($c['api_version'] ?? '') !== '2026-10-28.endive'
            || ! in_array($origen, config('cors.allowed_origins', []), true)
            || ! is_string($origen) || ! preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z~', $origen)) {
            throw new AppError('PAGO_NO_CONFIGURADO', 'Stripe de prueba no está disponible.');
        }

        return $c;
    }
}
