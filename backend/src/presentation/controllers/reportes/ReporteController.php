<?php

namespace Clinica\presentation\controllers\reportes;

use Clinica\business\services\reportes\ReporteService;
use Clinica\presentation\dtos\agenda\CitaListadoResource;
use Clinica\presentation\dtos\reportes\CantidadesCitasResource;
use Clinica\presentation\dtos\reportes\PacienteRegistradoResource;
use Clinica\presentation\dtos\reportes\ReporteAgendaRequest;
use Clinica\presentation\dtos\reportes\ReporteCantidadesRequest;
use Clinica\presentation\dtos\reportes\ReporteCitasRequest;
use Clinica\presentation\dtos\reportes\ReportePacientesRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReporteController
{
    public function cantidades(ReporteCantidadesRequest $request, ReporteService $service): CantidadesCitasResource
    {
        $result = $service->cantidades($request->validated());

        return (new CantidadesCitasResource($result['cantidades']))->additional(['meta' => $result['contexto']]);
    }

    public function agendaDiaria(ReporteAgendaRequest $request, ReporteService $service): AnonymousResourceCollection
    {
        $result = $service->agendaDiaria($request->validated());
        $result['pagina']->withPath($request->url())->appends([
            ...array_filter($result['contexto']['filtros'], fn ($valor) => $valor !== null),
            'per_page' => $result['pagina']->perPage()]);

        return CitaListadoResource::collection($result['pagina'])->additional(['meta' => $result['contexto']]);
    }

    public function pacientes(ReportePacientesRequest $request, ReporteService $service): AnonymousResourceCollection
    {
        $filtros = $request->validated();
        $result = $service->pacientesRegistrados($filtros);
        unset($filtros['page']);
        $result['pagina']->withPath($request->url())->appends([...$filtros,
            'orden' => $result['contexto']['filtros']['orden'], 'per_page' => $result['pagina']->perPage()]);

        return PacienteRegistradoResource::collection($result['pagina'])->additional(['meta' => $result['contexto']]);
    }

    public function citas(ReporteCitasRequest $request, ReporteService $service): AnonymousResourceCollection
    {
        $filtros = $request->validated();
        $result = $service->citas($request->route('reporte'), $filtros);
        unset($filtros['page']);
        $result['pagina']->withPath($request->url())->appends([...$filtros,
            'orden' => $result['contexto']['filtros']['orden'], 'per_page' => $result['pagina']->perPage()]);

        return CitaListadoResource::collection($result['pagina'])->additional(['meta' => $result['contexto']]);
    }
}
