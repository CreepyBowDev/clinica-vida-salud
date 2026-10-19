<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\ClaveService;
use Clinica\presentation\dtos\CambiarClaveRequest;
use Symfony\Component\HttpFoundation\Response;

class ClaveController
{
    public function update(CambiarClaveRequest $request, ClaveService $service): Response
    {
        $datos = $request->validated();
        $service->cambiar($datos['current_password'], $datos['password'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
