<?php

namespace Clinica\presentation\controllers\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\PrecioCitaService;
use Clinica\presentation\dtos\pagos\MontoCitaRequest;
use Clinica\presentation\dtos\pagos\ObligacionPagoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class MontoCitaController
{
    public function proponer(MontoCitaRequest $request, PrecioCitaService $service): JsonResource
    {
        $datos = $request->validated();

        return new JsonResource($service->proponer((string) $datos['id_objetivo'], $datos['monto_bob']));
    }

    public function confirmar(MontoCitaRequest $request, PrecioCitaService $service): JsonResponse
    {
        $datos = $request->validated();
        $resultado = $service->confirmar((string) $datos['id_objetivo'], $datos['propuesta'], $request->attributes->get('contexto_seguridad'));
        $ahora = CarbonImmutable::now('UTC');
        $resultado['obligacion']->setAttribute('calculado_at', $ahora);

        return (new ObligacionPagoResource($resultado['obligacion']))->additional(['meta' => ['repetida' => $resultado['repetida'],
            'calculado_at' => $ahora->format('Y-m-d\TH:i:s.u\Z')]])->response()->setStatusCode($resultado['repetida'] ? 200 : 201);
    }
}
