<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\CambioCorreoService;
use Clinica\presentation\dtos\CambioCorreoRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CambioCorreoController
{
    public function solicitar(CambioCorreoRequest $request, CambioCorreoService $service): JsonResponse
    {
        $datos = $request->validated();

        return response()->json(['data' => $service->solicitar($datos['correo_acceso'], $datos['current_password'],
            $request->attributes->get('contexto_seguridad'))], 202);
    }

    public function reenviar(CambioCorreoRequest $request, CambioCorreoService $service): JsonResponse
    {
        return response()->json(['data' => $service->reenviar($request->attributes->get('contexto_seguridad'))], 202);
    }

    public function verificar(CambioCorreoRequest $request, CambioCorreoService $service): Response
    {
        $service->verificar($request->validated()['codigo'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
