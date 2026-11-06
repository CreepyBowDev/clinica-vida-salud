<?php

namespace Clinica\business\services\pagos;

class HabilitacionAcademicaService
{
    public function permite(): bool
    {
        if (app()->environment('production') || config('pagos.simulacion_habilitada') !== true) {
            return false;
        }
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        $origen = config('pagos.academico.origen');
        $url = is_string($origen) ? parse_url($origen) : false;

        return app()->environment('academic') && config('pagos.academico.instalacion') === true
            && is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['path']) && ! isset($url['query']) && ! isset($url['fragment'])
            && in_array($origen, config('cors.allowed_origins', []), true)
            && config('app.debug') === false && config('session.secure') === true
            && config('session.http_only') === true
            && config('pagos.stripe.habilitado') === false && config('pagos.paypal.habilitado') === false;
    }
}
