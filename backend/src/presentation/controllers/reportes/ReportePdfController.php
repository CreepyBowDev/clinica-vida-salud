<?php

namespace Clinica\presentation\controllers\reportes;

use Clinica\business\services\reportes\ReporteExportacionService;
use Clinica\presentation\dtos\reportes\ReporteAgendaRequest;
use Clinica\presentation\dtos\reportes\ReporteCantidadesRequest;
use Clinica\presentation\dtos\reportes\ReporteCitasRequest;
use Clinica\presentation\dtos\reportes\ReportePacientesRequest;
use Symfony\Component\HttpFoundation\Response;

class ReportePdfController
{
    public function citas(ReporteCitasRequest $request, ReporteExportacionService $service): Response
    {
        return $this->descarga($service->generar($request->route('reporte'), $request->validated()));
    }

    public function pacientes(ReportePacientesRequest $request, ReporteExportacionService $service): Response
    {
        return $this->descarga($service->generar('pacientes/registrados', $request->validated()));
    }

    public function cantidades(ReporteCantidadesRequest $request, ReporteExportacionService $service): Response
    {
        return $this->descarga($service->generar('citas/cantidades', $request->validated()));
    }

    public function agendaDiaria(ReporteAgendaRequest $request, ReporteExportacionService $service): Response
    {
        return $this->descarga($service->generar('agenda-diaria', $request->validated()));
    }

    private function descarga(array $archivo): Response
    {
        return response($archivo['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($archivo['bytes']),
        ]);
    }
}
