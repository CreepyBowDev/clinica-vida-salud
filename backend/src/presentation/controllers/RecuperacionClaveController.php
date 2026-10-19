<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\RecuperacionClaveService;
use Clinica\presentation\dtos\RestablecerClaveRequest;
use Clinica\presentation\dtos\SolicitarRecuperacionRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RecuperacionClaveController
{
    public function solicitar(SolicitarRecuperacionRequest $request, RecuperacionClaveService $service): JsonResponse
    {
        $service->solicitar($request->validated()['correo_acceso'], $request->attributes->get('contexto_seguridad'));

        $response = response()->json(['data' => ['message' => 'Si corresponde, recibirás un enlace para recuperar tu contraseña.']], 202);
        // Delimitar el cuerpo permite completarlo sin esperar al transporte de correo en terminación.
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }

    public function restablecer(RestablecerClaveRequest $request, RecuperacionClaveService $service): Response
    {
        $datos = $request->validated();
        $service->restablecer($datos['token'], $datos['password'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
