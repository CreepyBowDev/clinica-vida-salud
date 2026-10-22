<?php

namespace Clinica\presentation\controllers\citas;

use Clinica\business\services\citas\DisponibilidadService;
use Clinica\presentation\dtos\citas\DisponibilidadRequest;
use Clinica\presentation\dtos\citas\DisponibilidadResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DisponibilidadController
{
    public function especialidades(DisponibilidadRequest $request, DisponibilidadService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->especialidades($request->validated()));
    }

    public function medicos(DisponibilidadRequest $request, DisponibilidadService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->medicos($request->validated()));
    }

    public function turnos(DisponibilidadRequest $request, DisponibilidadService $service): DisponibilidadResource
    {
        return new DisponibilidadResource($service->turnos($request->validated()));
    }
}
