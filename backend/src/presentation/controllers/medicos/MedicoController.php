<?php

namespace Clinica\presentation\controllers\medicos;

use Clinica\business\services\medicos\MedicoService;
use Clinica\presentation\dtos\medicos\ActualizarMedicoRequest;
use Clinica\presentation\dtos\medicos\CambiarEspecialidadesMedicoRequest;
use Clinica\presentation\dtos\medicos\CambiarEstadoMedicoRequest;
use Clinica\presentation\dtos\medicos\CrearMedicoRequest;
use Clinica\presentation\dtos\medicos\ListarMedicosRequest;
use Clinica\presentation\dtos\medicos\MedicoAdministrativoResource;
use Clinica\presentation\dtos\medicos\MedicoListadoResource;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MedicoController
{
    public function especialidades(CambiarEspecialidadesMedicoRequest $request, MedicoService $service): MedicoAdministrativoResource
    {
        $datos = $request->validated();

        return new MedicoAdministrativoResource($service->cambiarEspecialidades($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function estado(CambiarEstadoMedicoRequest $request, MedicoService $service): MedicoAdministrativoResource
    {
        $datos = $request->validated();

        return new MedicoAdministrativoResource($service->cambiarEstado($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function index(ListarMedicosRequest $request, MedicoService $service): AnonymousResourceCollection
    {
        return MedicoListadoResource::collection($service->listar($request->validated()));
    }

    public function show(CuentaRequest $request, MedicoService $service): MedicoAdministrativoResource
    {
        return new MedicoAdministrativoResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function store(CrearMedicoRequest $request, MedicoService $service): JsonResponse
    {
        return (new MedicoAdministrativoResource($service->crear($request->validated(), $request->attributes->get('contexto_seguridad'))))
            ->response()->setStatusCode(201);
    }

    public function update(ActualizarMedicoRequest $request, MedicoService $service): MedicoAdministrativoResource
    {
        $datos = $request->validated();

        return new MedicoAdministrativoResource($service->actualizar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }
}
