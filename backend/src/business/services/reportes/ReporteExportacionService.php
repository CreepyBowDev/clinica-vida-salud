<?php

namespace Clinica\business\services\reportes;

use Carbon\CarbonImmutable;
use Clinica\shared\utils\ReportePdf;
use Illuminate\Validation\ValidationException;

class ReporteExportacionService
{
    public const MAX_FILAS = 500;

    public function __construct(private ReporteService $reportes, private ReportePdf $pdf) {}

    public function generar(string $tipo, array $filtros): array
    {
        // Una única lectura de filas, total y estadísticas; no concatenar páginas HTTP.
        $datos = [...$filtros, 'page' => 1, 'per_page' => self::MAX_FILAS + 1];
        $resultado = match ($tipo) {
            'citas/programadas', 'citas/por-medico', 'citas/por-especialidad', 'citas/por-estado' => $this->reportes->citas($tipo, $datos),
            'pacientes/registrados' => $this->reportes->pacientesRegistrados($datos),
            'agenda-diaria' => $this->reportes->agendaDiaria($datos),
            'citas/cantidades' => $this->reportes->cantidades($filtros),
            default => throw ValidationException::withMessages(['reporte' => ['El reporte no está permitido.']]),
        };
        $total = isset($resultado['pagina']) ? $resultado['pagina']->total() : (int) $resultado['cantidades']['total'];
        if (isset($resultado['pagina']) && $total > self::MAX_FILAS) {
            throw ValidationException::withMessages(['exportacion' => ['El reporte supera el límite de 500 filas. Acota los filtros y vuelve a exportar. No se generó un PDF parcial.']]);
        }
        $documento = ['contexto' => $resultado['contexto'], 'filas' => isset($resultado['pagina']) ? $resultado['pagina']->items() : [],
            'total' => $total, 'cantidades' => $resultado['cantidades'] ?? null,
            'generado_en' => CarbonImmutable::now('America/La_Paz')->format('Y-m-d H:i:s P')];
        $bytes = $this->pdf->generar($documento);
        // El renderer no entrega bytes. Revalidar sesión/cuenta/rol después del trabajo costoso.
        $this->reportes->autorizarEntrega();

        return ['bytes' => $bytes, 'nombre' => 'reporte-'.str_replace('/', '-', $tipo).'-'
            .CarbonImmutable::now('America/La_Paz')->format('Ymd-His').'.pdf'];
    }
}
