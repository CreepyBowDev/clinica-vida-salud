<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\PacienteService;
use Clinica\presentation\dtos\ActualizarPacienteRequest;
use Clinica\presentation\dtos\CambiarEstadoPacienteRequest;
use Clinica\presentation\dtos\CrearPacienteRequest;
use Clinica\presentation\dtos\CuentaRequest;
use Clinica\presentation\dtos\ListarPacientesRequest;
use Clinica\presentation\dtos\PacienteAdministrativoResource;
use Clinica\presentation\dtos\PacienteListadoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PacienteController
{
    public function update(ActualizarPacienteRequest $request, PacienteService $service): PacienteAdministrativoResource
    {
        $datos = $request->validated();

        return new PacienteAdministrativoResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function estado(CambiarEstadoPacienteRequest $request, PacienteService $service): PacienteAdministrativoResource
    {
        $datos = $request->validated();

        return new PacienteAdministrativoResource($service->cambiarEstado($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function index(ListarPacientesRequest $request, PacienteService $service): AnonymousResourceCollection
    {
        return PacienteListadoResource::collection($service->listar($request->validated()));
    }

    public function show(CuentaRequest $request, PacienteService $service): PacienteAdministrativoResource
    {
        return new PacienteAdministrativoResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function store(CrearPacienteRequest $request, PacienteService $service): JsonResponse
    {
        return (new PacienteAdministrativoResource($service->crear($request->validated(),
            $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }
}
