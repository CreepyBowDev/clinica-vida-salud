<?php

namespace Clinica\presentation\controllers\horarios;

use Clinica\business\services\horarios\HorarioService;
use Clinica\presentation\dtos\horarios\HorarioRequest;
use Clinica\presentation\dtos\horarios\PlanificacionResource;
use Illuminate\Http\JsonResponse;

class HorarioController
{
    public function index(HorarioRequest $request, HorarioService $service): PlanificacionResource
    {
        return new PlanificacionResource($service->ejecutar($request->validated()['id_objetivo'], 'LISTAR', $request->validated()));
    }

    public function impacto(HorarioRequest $request, HorarioService $service): PlanificacionResource
    {
        $datos = $request->validated();

        return new PlanificacionResource($service->ejecutar($datos['id_objetivo'], $datos['accion'], $datos));
    }

    public function store(HorarioRequest $request, HorarioService $service): JsonResponse
    {
        return $this->guardar($request, $service, 'CREAR')->response()->setStatusCode(201);
    }

    public function update(HorarioRequest $request, HorarioService $service): PlanificacionResource
    {
        return $this->guardar($request, $service, 'EDITAR');
    }

    public function estado(HorarioRequest $request, HorarioService $service): PlanificacionResource
    {
        return $this->guardar($request, $service, 'ESTADO');
    }

    private function guardar(HorarioRequest $request, HorarioService $service, string $accion): PlanificacionResource
    {
        $datos = $request->validated();

        return new PlanificacionResource($service->ejecutar($datos['id_objetivo'], $accion, $datos, true, $request->attributes->get('contexto_seguridad')));
    }
}
