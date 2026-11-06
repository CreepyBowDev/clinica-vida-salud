<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\EventoStripeService;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookStripeController
{
    public function recibir(Request $request, EventoStripeService $service): Response
    {
        if (! $request->isJson()) {
            throw new AppError('PAGO_CONTENIDO_NO_ADMITIDO', 'El evento requiere contenido JSON.');
        }
        $service->recibir($request->getContent(), (string) $request->header('Stripe-Signature', ''));

        return response('', 204, ['Cache-Control' => 'no-store, private']);
    }
}
