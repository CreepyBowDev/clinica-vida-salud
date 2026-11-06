<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\SimulacionPagoService;
use Clinica\presentation\dtos\pagos\SimulacionPagoRequest;
use Illuminate\Http\JsonResponse;

class SimulacionPagoController
{
    public function iniciar(SimulacionPagoRequest $request, SimulacionPagoService $service): JsonResponse
    {
        $r = $service->iniciar($request->validated());

        return response()->json(['data' => $r], $r['repetida'] ? 200 : 201, ['Cache-Control' => 'no-store, private']);
    }

    public function actuar(SimulacionPagoRequest $request, SimulacionPagoService $service): JsonResponse
    {
        return response()->json(['data' => $service->actuar($request->validated(), $request->route('accion'))],
            200, ['Cache-Control' => 'no-store, private']);
    }
}
