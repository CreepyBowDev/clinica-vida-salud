<?php

namespace Clinica\presentation\controllers\citas;

use Clinica\business\services\citas\ReservaService;
use Clinica\presentation\dtos\citas\ReservaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservaController
{
    public function propuesta(ReservaRequest $request, ReservaService $service): JsonResource
    {
        return new JsonResource($service->proponer($request->validated()));
    }

    public function store(ReservaRequest $request, ReservaService $service): JsonResponse
    {
        $resultado = $service->confirmar($request->validated(), $request->attributes->get('contexto_seguridad'));

        return (new JsonResource($resultado['cita']))->additional(['meta' => ['repetida' => $resultado['repetida']]])
            ->response()->setStatusCode($resultado['repetida'] ? 200 : 201);
    }

    public function resultado(ReservaRequest $request, ReservaService $service): JsonResource
    {
        return new JsonResource($service->resultado($request->validated()['propuesta']));
    }
}
