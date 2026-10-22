<?php

namespace Clinica\presentation\controllers\consultorios;

use Clinica\business\services\consultorios\AsignacionConsultorioService;
use Clinica\presentation\dtos\consultorios\AsignacionConsultorioResource;
use Clinica\presentation\dtos\consultorios\ConfirmarAsignacionConsultorioRequest;
use Clinica\presentation\dtos\consultorios\ImpactoAsignacionConsultorioRequest;
use Clinica\presentation\dtos\consultorios\ImpactoAsignacionConsultorioResource;
use Clinica\presentation\dtos\usuarios\CuentaRequest;

class AsignacionConsultorioController
{
    public function show(CuentaRequest $request, AsignacionConsultorioService $service): AsignacionConsultorioResource
    {
        return new AsignacionConsultorioResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function impacto(ImpactoAsignacionConsultorioRequest $request, AsignacionConsultorioService $service): ImpactoAsignacionConsultorioResource
    {
        $datos = $request->validated();

        return new ImpactoAsignacionConsultorioResource($service->impacto($datos['id_objetivo'], $datos));
    }

    public function update(ConfirmarAsignacionConsultorioRequest $request, AsignacionConsultorioService $service): AsignacionConsultorioResource
    {
        $datos = $request->validated();

        return new AsignacionConsultorioResource($service->confirmar($datos['id_objetivo'], $datos, $request->attributes->get('contexto_seguridad')));
    }
}
