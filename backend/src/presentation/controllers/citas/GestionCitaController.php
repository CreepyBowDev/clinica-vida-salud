<?php

namespace Clinica\presentation\controllers\citas;

use Clinica\business\services\citas\GestionCitaService;
use Clinica\presentation\dtos\citas\GestionCitaRequest;
use Illuminate\Http\Resources\Json\JsonResource;

class GestionCitaController
{
    public function cancelar(GestionCitaRequest $request, GestionCitaService $service): JsonResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new JsonResource($service->cancelar($id, $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function consultar(GestionCitaRequest $request, GestionCitaService $service): JsonResource
    {
        return new JsonResource($service->consultar((string) $request->validated()['id_objetivo']));
    }

    public function reprogramar(GestionCitaRequest $request, GestionCitaService $service): JsonResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new JsonResource($service->reprogramar($id, $datos, $request->attributes->get('contexto_seguridad')));
    }
}
