<?php

namespace Clinica\presentation\controllers\usuarios;

use Clinica\business\services\usuarios\ClaveService;
use Clinica\presentation\dtos\usuarios\CambiarClaveRequest;
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
