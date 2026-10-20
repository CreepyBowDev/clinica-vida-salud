<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\MiPerfilService;
use Clinica\presentation\dtos\MiPerfilRequest;
use Clinica\presentation\dtos\MiPerfilResource;

class MiPerfilController
{
    public function show(MiPerfilRequest $request, MiPerfilService $service): MiPerfilResource
    {
        return new MiPerfilResource($service->consultar());
    }

    public function update(MiPerfilRequest $request, MiPerfilService $service): MiPerfilResource
    {
        return new MiPerfilResource($service->actualizar($request->validated(), $request->attributes->get('contexto_seguridad')));
    }
}
