<?php

namespace Clinica\presentation\controllers\horarios;

use Clinica\business\services\horarios\ConfiguracionCitasService;
use Clinica\presentation\dtos\horarios\ConfiguracionCitasRequest;
use Clinica\presentation\dtos\horarios\PlanificacionResource;

class ConfiguracionCitasController
{
    public function show(ConfiguracionCitasRequest $request, ConfiguracionCitasService $service): PlanificacionResource
    {
        return new PlanificacionResource($service->ejecutar());
    }

    public function impacto(ConfiguracionCitasRequest $request, ConfiguracionCitasService $service): PlanificacionResource
    {
        return new PlanificacionResource($service->ejecutar($request->validated()));
    }

    public function update(ConfiguracionCitasRequest $request, ConfiguracionCitasService $service): PlanificacionResource
    {
        return new PlanificacionResource($service->ejecutar($request->validated(), true, $request->attributes->get('contexto_seguridad')));
    }
}
