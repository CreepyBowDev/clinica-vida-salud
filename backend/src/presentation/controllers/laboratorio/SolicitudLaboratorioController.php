<?php

namespace Clinica\presentation\controllers\laboratorio;

use Clinica\business\services\laboratorio\SolicitudLaboratorioService;
use Clinica\presentation\dtos\laboratorio\CrearSolicitudLaboratorioRequest;
use Clinica\presentation\dtos\laboratorio\SolicitudLaboratorioResource;
use Illuminate\Http\JsonResponse;

class SolicitudLaboratorioController
{
    public function crear(CrearSolicitudLaboratorioRequest $request, SolicitudLaboratorioService $service): JsonResponse
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return (new SolicitudLaboratorioResource($service->crear($id, $datos,
            $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }
}
