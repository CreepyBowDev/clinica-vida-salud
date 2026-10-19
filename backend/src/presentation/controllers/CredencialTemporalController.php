<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\CredencialTemporalService;
use Clinica\presentation\dtos\CompletarClaveTemporalRequest;
use Clinica\presentation\dtos\EmitirCredencialTemporalRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CredencialTemporalController
{
    public function emitir(EmitirCredencialTemporalRequest $request, CredencialTemporalService $service): JsonResponse
    {
        $datos = $request->validated();

        return response()->json(['data' => $service->emitir($datos['id_objetivo'], (bool) $datos['identidad_verificada'],
            $request->attributes->get('contexto_seguridad'))], 201)->header('Cache-Control', 'no-store, private');
    }

    public function completar(CompletarClaveTemporalRequest $request, CredencialTemporalService $service): Response
    {
        $service->completar($request->validated()['password'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
