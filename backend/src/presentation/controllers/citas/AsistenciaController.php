<?php

namespace Clinica\presentation\controllers\citas;

use Clinica\business\services\citas\AsistenciaService;
use Clinica\presentation\dtos\citas\AsistenciaRequest;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaController
{
    public function consultar(AsistenciaRequest $request, AsistenciaService $service): JsonResource
    {
        return new JsonResource($service->consultar((string) $request->validated()['id_objetivo']));
    }

    public function confirmarLlegada(AsistenciaRequest $request, AsistenciaService $service): JsonResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new JsonResource($service->confirmarLlegada($id, $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function registrarInasistencia(AsistenciaRequest $request, AsistenciaService $service): JsonResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new JsonResource($service->registrarInasistencia($id, $datos, $request->attributes->get('contexto_seguridad')));
    }

    public function marcarAtendida(AsistenciaRequest $request, AsistenciaService $service): JsonResource
    {
        $datos = $request->validated();
        $id = (string) $datos['id_objetivo'];
        unset($datos['id_objetivo']);

        return new JsonResource($service->marcarAtendida($id, $datos, $request->attributes->get('contexto_seguridad')));
    }
}
