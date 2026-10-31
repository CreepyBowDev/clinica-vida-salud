<?php

namespace Clinica\presentation\controllers\historia_clinica;

use Clinica\business\services\historia_clinica\ConsultaMedicaService;
use Clinica\presentation\dtos\historia_clinica\ActualizarConsultaRequest;
use Clinica\presentation\dtos\historia_clinica\HistoriaClinicaResource;
use Clinica\presentation\dtos\historia_clinica\RegistrarConsultaRequest;
use Illuminate\Http\JsonResponse;

class ConsultaMedicaController
{
    public function actualizar(ActualizarConsultaRequest $request, ConsultaMedicaService $service): HistoriaClinicaResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new HistoriaClinicaResource($service->actualizar($id, $datos,
            $request->attributes->get('contexto_seguridad')));
    }

    public function registrar(RegistrarConsultaRequest $request, ConsultaMedicaService $service): JsonResponse
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return (new HistoriaClinicaResource($service->registrar($id, $datos,
            $request->attributes->get('contexto_seguridad'))))->response()->setStatusCode(201);
    }
}
