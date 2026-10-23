<?php

namespace Clinica\presentation\controllers\agenda;

use Clinica\business\services\agenda\HistorialCitasService;
use Clinica\presentation\dtos\agenda\CitaListadoResource;
use Clinica\presentation\dtos\agenda\HistorialCitasRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HistorialCitasController
{
    public function propias(HistorialCitasRequest $request, HistorialCitasService $service): AnonymousResourceCollection
    {
        return $this->respuesta($request, $service->consultar($request->validated()));
    }

    public function paciente(HistorialCitasRequest $request, HistorialCitasService $service): AnonymousResourceCollection
    {
        $data = $request->validated();
        $id = (string) $data['id_objetivo'];
        unset($data['id_objetivo']);

        return $this->respuesta($request, $service->consultar($data, $id));
    }

    private function respuesta(HistorialCitasRequest $request, array $result): AnonymousResourceCollection
    {
        $links = [...$result['contexto']['filtros'], 'per_page' => $result['pagina']->perPage()];
        $result['pagina']->withPath($request->url())->appends(array_filter($links, fn ($value) => $value !== null));

        return CitaListadoResource::collection($result['pagina'])->additional(['meta' => $result['contexto']]);
    }
}
