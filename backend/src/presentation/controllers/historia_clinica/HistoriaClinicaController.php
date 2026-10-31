<?php

namespace Clinica\presentation\controllers\historia_clinica;

use Clinica\business\services\historia_clinica\HistoriaClinicaService;
use Clinica\presentation\dtos\historia_clinica\HistoriaClinicaResource;
use Clinica\presentation\dtos\historia_clinica\LeerHistoriaClinicaRequest;

class HistoriaClinicaController
{
    public function historia(LeerHistoriaClinicaRequest $request, HistoriaClinicaService $service): HistoriaClinicaResource
    {
        $datos = $request->validated();

        return new HistoriaClinicaResource($service->consultar((string) $datos['id_objetivo'], $datos));
    }

    public function consulta(LeerHistoriaClinicaRequest $request, HistoriaClinicaService $service): HistoriaClinicaResource
    {
        $datos = $request->validated();

        return new HistoriaClinicaResource($service->consulta((string) $datos['id_objetivo'], $datos));
    }

    public function versionesConsulta(LeerHistoriaClinicaRequest $request, HistoriaClinicaService $service): HistoriaClinicaResource
    {
        $datos = $request->validated();

        return new HistoriaClinicaResource($service->versionesConsulta((string) $datos['id_objetivo'], $datos));
    }

    public function versionesAntecedentes(LeerHistoriaClinicaRequest $request, HistoriaClinicaService $service): HistoriaClinicaResource
    {
        $datos = $request->validated();

        return new HistoriaClinicaResource($service->versionesAntecedentes((string) $datos['id_objetivo'], $datos));
    }
}
