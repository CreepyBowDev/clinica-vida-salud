<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\AdministracionCuentaService;
use Clinica\presentation\dtos\CambiarEstadoCuentaRequest;
use Clinica\presentation\dtos\CambiarRolCuentaRequest;
use Clinica\presentation\dtos\CuentaAdministrativaResource;
use Clinica\presentation\dtos\CuentaRequest;
use Clinica\presentation\dtos\ListarCuentasRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CuentaController
{
    public function index(ListarCuentasRequest $request, AdministracionCuentaService $service): AnonymousResourceCollection
    {
        return CuentaAdministrativaResource::collection($service->listar($request->validated()));
    }

    public function show(CuentaRequest $request, AdministracionCuentaService $service): CuentaAdministrativaResource
    {
        return new CuentaAdministrativaResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function estado(CambiarEstadoCuentaRequest $request, AdministracionCuentaService $service): CuentaAdministrativaResource
    {
        $datos = $request->validated();

        return new CuentaAdministrativaResource($service->cambiarEstado($datos['id_objetivo'], $datos['estado_cuenta'], $request->attributes->get('contexto_seguridad')));
    }

    public function rol(CambiarRolCuentaRequest $request, AdministracionCuentaService $service): CuentaAdministrativaResource
    {
        $datos = $request->validated();

        return new CuentaAdministrativaResource($service->cambiarRol($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }
}
