<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\InvitacionPersonalService;
use Clinica\presentation\dtos\CrearPersonalRequest;
use Clinica\presentation\dtos\ReenviarInvitacionRequest;
use Illuminate\Http\JsonResponse;

class PersonalController
{
    public function store(CrearPersonalRequest $request, InvitacionPersonalService $service): JsonResponse
    {
        return $this->respuesta($service->crear($request->validated(), $request->attributes->get('contexto_seguridad')), 201);
    }

    public function reenviar(ReenviarInvitacionRequest $request, InvitacionPersonalService $service): JsonResponse
    {
        return $this->respuesta($service->reenviar($request->validated()['id_usuario'], $request->attributes->get('contexto_seguridad')), 200);
    }

    private function respuesta(array $resultado, int $status): JsonResponse
    {
        $usuario = $resultado['usuario'];

        return response()->json(['data' => [
            'id_usuario' => (string) $usuario->getKey(), 'correo_acceso' => $usuario->correo_acceso,
            'rol' => $usuario->rol->nombre, 'estado_cuenta' => $usuario->estado_cuenta,
        ], 'meta' => ['invitacion' => $resultado['invitacion']]], $status);
    }
}
