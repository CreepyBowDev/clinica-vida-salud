<?php

namespace Clinica\presentation\controllers\pacientes;

use Clinica\business\services\pacientes\MiPerfilService;
use Clinica\presentation\dtos\pacientes\MiPerfilRequest;
use Clinica\presentation\dtos\pacientes\MiPerfilResource;

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
