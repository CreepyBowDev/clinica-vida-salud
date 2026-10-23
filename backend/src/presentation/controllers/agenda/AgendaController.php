<?php

namespace Clinica\presentation\controllers\agenda;

use Clinica\business\services\agenda\AgendaService;
use Clinica\presentation\dtos\agenda\AgendaRequest;
use Clinica\presentation\dtos\agenda\CitaListadoResource;
use Clinica\presentation\dtos\agenda\MedicoAgendaResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgendaController
{
    public function medicos(AgendaRequest $request, AgendaService $service): AnonymousResourceCollection
    {
        $filtros = $request->validated();
        $pagina = $service->medicos($filtros);
        $pagina->withPath($request->url())->appends($request->only(['nombre', 'per_page']));

        return MedicoAgendaResource::collection($pagina);
    }

    public function index(AgendaRequest $request, AgendaService $service): AnonymousResourceCollection
    {
        $result = $service->consultar($request->validated());
        $links = [...$result['contexto']['filtros'], 'per_page' => $result['pagina']->perPage()];
        // El profesional propio nunca se convierte en entrada de la URL del Médico.
        if ($request->user()->rol->nombre === 'Médico') {
            unset($links['id_medico']);
        }
        $result['pagina']->withPath($request->url())->appends(array_filter($links, fn ($v) => $v !== null));

        return CitaListadoResource::collection($result['pagina'])->additional(['meta' => $result['contexto']]);
    }
}
