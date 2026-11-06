<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\OperacionPagoService;
use Clinica\presentation\dtos\pagos\IntentoPagoRequest;
use Illuminate\Http\JsonResponse;

class PagoController
{
    public function operar(IntentoPagoRequest $request, OperacionPagoService $service): JsonResponse
    {
        $d = $request->validated();
        $respuesta = $service->operar($d, $request->route('obligacion') !== null,
            $request->routeIs('pagos.paypal.captura') ? 'Capturar' : ($request->routeIs('pagos.stripe.cierre') ? 'Cerrar' : 'Consultar'));
        $r = $respuesta['localizador'];

        return response()->json(['data' => $respuesta['data'], 'meta' => $respuesta['meta']], $respuesta['status'],
            ['Cache-Control' => 'no-store, private', 'Location' => '/api/pagos/intentos/'.$r['intento'].'/resultado',
                ...($respuesta['pendiente'] ? ['Retry-After' => '5'] : [])]);
    }
}
