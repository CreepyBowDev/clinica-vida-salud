<?php

namespace Clinica\presentation\controllers\usuarios;

use Clinica\business\services\usuarios\InvitacionPersonalService;
use Clinica\presentation\dtos\usuarios\AceptarInvitacionRequest;
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
