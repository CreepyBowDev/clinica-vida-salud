<?php

namespace Clinica\presentation\controllers\laboratorio;

use Clinica\business\services\laboratorio\ResultadoLaboratorioService;
use Clinica\presentation\dtos\laboratorio\LeerLaboratorioRequest;
use Clinica\presentation\dtos\laboratorio\SolicitudLaboratorioResource;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultadoLaboratorioController
{
    public function archivo(LeerLaboratorioRequest $request, ResultadoLaboratorioService $service): Response
    {
        $datos = $request->validated();
        $archivo = $service->archivo((string) $datos['id_objetivo'], (string) $datos['id_version_resultado']);
        $flujo = $archivo['flujo'];
        $cabeceras = ['Content-Type' => $archivo['tipo_mime'], 'Content-Length' => (string) $archivo['tamano_bytes'],
            'Content-Disposition' => 'attachment; filename="resultado-'.$datos['id_version_resultado'].'.'.$archivo['extension'].'"',
            'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Accept-Ranges' => 'none'];
        if ($request->isMethod('HEAD')) {
            fclose($flujo);

            return new Response('', 200, $cabeceras);
        }

        return new StreamedResponse(static function () use ($flujo): void {
            try {
                fpassthru($flujo);
            } finally {
                fclose($flujo);
            }
        }, 200, $cabeceras);
    }

    public function versiones(LeerLaboratorioRequest $request, ResultadoLaboratorioService $service): SolicitudLaboratorioResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new SolicitudLaboratorioResource($service->versiones($id, $datos));
    }

    public function listar(LeerLaboratorioRequest $request, ResultadoLaboratorioService $service): SolicitudLaboratorioResource
    {
        return new SolicitudLaboratorioResource($service->listar($request->validated()));
    }

    public function porConsulta(LeerLaboratorioRequest $request, ResultadoLaboratorioService $service): SolicitudLaboratorioResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new SolicitudLaboratorioResource($service->porConsulta($id, $datos));
    }

    public function consultar(LeerLaboratorioRequest $request, ResultadoLaboratorioService $service): SolicitudLaboratorioResource
    {
        return new SolicitudLaboratorioResource($service->consultar((string) $request->validated('id_objetivo')));
    }
}
