<?php

namespace Clinica\presentation\controllers\consultorios;

use Clinica\business\services\consultorios\ConsultorioService;
use Clinica\presentation\dtos\consultorios\ActualizarConsultorioRequest;
use Clinica\presentation\dtos\consultorios\CambiarEstadoConsultorioRequest;
use Clinica\presentation\dtos\consultorios\ConsultorioResource;
use Clinica\presentation\dtos\consultorios\CrearConsultorioRequest;
use Clinica\presentation\dtos\consultorios\ListarConsultoriosRequest;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsultorioController
{
    public function index(ListarConsultoriosRequest $request, ConsultorioService $service): AnonymousResourceCollection
    {
        return ConsultorioResource::collection($service->listar($request->validated()));
    }

    public function show(CuentaRequest $request, ConsultorioService $service): ConsultorioResource
    {
        return new ConsultorioResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function store(CrearConsultorioRequest $request, ConsultorioService $service): JsonResponse
    {
        return (new ConsultorioResource($service->crear($request->validated(), $request->attributes->get('contexto_seguridad'))))
            ->response()->setStatusCode(201);
    }

    public function update(ActualizarConsultorioRequest $request, ConsultorioService $service): ConsultorioResource
    {
        $datos = $request->validated();

        return new ConsultorioResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function estado(CambiarEstadoConsultorioRequest $request, ConsultorioService $service): ConsultorioResource
    {
        $datos = $request->validated();

        return new ConsultorioResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad'), estado: true));
    }
}
