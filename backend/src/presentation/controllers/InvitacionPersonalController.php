<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\InvitacionPersonalService;
use Clinica\presentation\dtos\AceptarInvitacionRequest;
use Symfony\Component\HttpFoundation\Response;

class InvitacionPersonalController
{
    public function aceptar(AceptarInvitacionRequest $request, InvitacionPersonalService $service): Response
    {
        $datos = $request->validated();
        $service->aceptar($datos['token'], $datos['password'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
