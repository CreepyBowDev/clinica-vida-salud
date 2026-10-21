<?php

namespace Clinica\presentation\controllers\usuarios;

use Clinica\business\services\usuarios\AdministracionCuentaService;
use Clinica\presentation\dtos\usuarios\CambiarEstadoCuentaRequest;
use Clinica\presentation\dtos\usuarios\CambiarRolCuentaRequest;
use Clinica\presentation\dtos\usuarios\CuentaAdministrativaResource;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Clinica\presentation\dtos\usuarios\ListarCuentasRequest;
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
