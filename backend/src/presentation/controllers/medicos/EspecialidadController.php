<?php

namespace Clinica\presentation\controllers\medicos;

use Clinica\business\services\medicos\EspecialidadService;
use Clinica\presentation\dtos\medicos\ActualizarEspecialidadRequest;
use Clinica\presentation\dtos\medicos\CambiarEstadoEspecialidadRequest;
use Clinica\presentation\dtos\medicos\CrearEspecialidadRequest;
use Clinica\presentation\dtos\medicos\EspecialidadResource;
use Clinica\presentation\dtos\medicos\ListarEspecialidadesRequest;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EspecialidadController
{
    public function index(ListarEspecialidadesRequest $request, EspecialidadService $service): AnonymousResourceCollection
    {
        return EspecialidadResource::collection($service->listar($request->validated()));
    }

    public function show(CuentaRequest $request, EspecialidadService $service): EspecialidadResource
    {
        return new EspecialidadResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function store(CrearEspecialidadRequest $request, EspecialidadService $service): JsonResponse
    {
        return (new EspecialidadResource($service->crear($request->validated(), $request->attributes->get('contexto_seguridad'))))
            ->response()->setStatusCode(201);
    }

    public function update(ActualizarEspecialidadRequest $request, EspecialidadService $service): EspecialidadResource
    {
        $datos = $request->validated();

        return new EspecialidadResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function estado(CambiarEstadoEspecialidadRequest $request, EspecialidadService $service): EspecialidadResource
    {
        $datos = $request->validated();

        return new EspecialidadResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad'), estado: true));
    }
}
