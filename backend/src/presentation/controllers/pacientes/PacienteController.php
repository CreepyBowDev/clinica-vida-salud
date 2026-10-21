<?php

namespace Clinica\presentation\controllers\pacientes;

use Clinica\business\services\pacientes\PacienteService;
use Clinica\presentation\dtos\pacientes\ActualizarPacienteRequest;
use Clinica\presentation\dtos\pacientes\CambiarEstadoPacienteRequest;
use Clinica\presentation\dtos\pacientes\CrearPacienteRequest;
use Clinica\presentation\dtos\pacientes\ListarPacientesRequest;
use Clinica\presentation\dtos\pacientes\PacienteAdministrativoResource;
use Clinica\presentation\dtos\pacientes\PacienteListadoResource;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
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
