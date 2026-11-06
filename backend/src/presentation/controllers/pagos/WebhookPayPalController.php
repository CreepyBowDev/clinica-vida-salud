<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\EventoPayPalService;
use Clinica\shared\errors\AppError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookPayPalController
{
    public function recibir(Request $request, EventoPayPalService $service): JsonResponse
    {
        if (! $request->isJson()) {
            throw new AppError('PAGO_CONTENIDO_NO_ADMITIDO', 'El evento requiere contenido JSON.');
        }
        $cabeceras = [];
        foreach (['auth-algo', 'cert-url', 'transmission-id', 'transmission-sig', 'transmission-time'] as $h) {
            $cabeceras[str_replace('-', '_', $h)] = (string) $request->header('paypal-'.$h, '');
        }
        $service->recibir($request->getContent(), $cabeceras);

        return response()->json(['data' => ['recibido' => true]], 200, ['Cache-Control' => 'no-store, private']);
    }
}
