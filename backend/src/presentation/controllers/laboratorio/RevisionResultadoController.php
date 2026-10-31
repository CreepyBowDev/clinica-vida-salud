<?php

namespace Clinica\presentation\controllers\laboratorio;

use Clinica\business\services\laboratorio\RevisionResultadoService;
use Clinica\presentation\dtos\laboratorio\RevisarResultadoLaboratorioRequest;
use Clinica\presentation\dtos\laboratorio\SolicitudLaboratorioResource;
use Illuminate\Http\JsonResponse;

class RevisionResultadoController
{
    public function crear(RevisarResultadoLaboratorioRequest $request, RevisionResultadoService $service): JsonResponse
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return (new SolicitudLaboratorioResource($service->revisar($id, $datos,
            $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }
}
