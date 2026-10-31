<?php

namespace Clinica\presentation\controllers\laboratorio;

use Clinica\business\services\laboratorio\DocumentoLaboratorioService;
use Clinica\presentation\dtos\laboratorio\CargarDocumentoLaboratorioRequest;
use Clinica\presentation\dtos\laboratorio\SolicitudLaboratorioResource;
use Illuminate\Http\JsonResponse;
use SplFileInfo;

class DocumentoLaboratorioController
{
    public function cargar(CargarDocumentoLaboratorioRequest $request, DocumentoLaboratorioService $service): JsonResponse
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        $archivo = $request->file('archivo');
        unset($datos['id_objetivo'], $datos['archivo']);

        return (new SolicitudLaboratorioResource($service->cargar($id, $datos, new SplFileInfo($archivo->getPathname()),
            $archivo->getClientOriginalName(), $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }

    public function corregir(CargarDocumentoLaboratorioRequest $request, DocumentoLaboratorioService $service): JsonResponse
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        $archivo = $request->file('archivo');
        unset($datos['id_objetivo'], $datos['archivo']);

        return (new SolicitudLaboratorioResource($service->corregir($id, $datos,
            $archivo === null ? null : new SplFileInfo($archivo->getPathname()), $archivo?->getClientOriginalName(),
            $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }
}
