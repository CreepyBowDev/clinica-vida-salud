<?php

namespace Clinica\presentation\controllers\agenda;

use Clinica\business\services\agenda\ConsultaCitaService;
use Clinica\presentation\dtos\agenda\CitaDetalleResource;
use Clinica\presentation\dtos\agenda\ConsultaCitaRequest;

class ConsultaCitaController
{
    public function show(ConsultaCitaRequest $request, ConsultaCitaService $service): CitaDetalleResource
    {
        return new CitaDetalleResource($service->consultar((string) $request->validated()['id_objetivo']));
    }
}
