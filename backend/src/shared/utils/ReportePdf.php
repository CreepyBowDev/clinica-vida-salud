<?php

namespace Clinica\shared\utils;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReportePdf
{
    public const MAX_HTML_BYTES = 2 * 1024 * 1024;

    public const MAX_PDF_BYTES = 8 * 1024 * 1024;

    private const TITULOS = [
        'citas/programadas' => 'Citas Programadas por fecha',
        'citas/por-medico' => 'Citas por Médico',
        'citas/por-especialidad' => 'Citas por especialidad',
        'citas/por-estado' => 'Citas por estado',
        'pacientes/registrados' => 'Pacientes registrados',
        'citas/cantidades' => 'Cantidades de citas por estado',
        'agenda-diaria' => 'Agenda diaria por Médico',
    ];

    public function generar(array $documento): string
    {
        $html = $this->html($documento);
        if (strlen($html) > self::MAX_HTML_BYTES) {
            $this->exceso();
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols([]);
        $options->setChroot([storage_path('framework/cache')]);
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(36, 568, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
        $bytes = $pdf->output();
        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            $this->exceso();
        }
        if (! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
            throw new RuntimeException('El renderer no produjo un PDF.');
        }

        return $bytes;
    }

    private function exceso(): never
    {
        throw ValidationException::withMessages(['exportacion' => ['El documento excede el tamaño seguro. Acota los filtros y vuelve a exportar. No se generó un PDF parcial.']]);
    }

    private function escapar(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function fecha(string $valor): string
    {
        return CarbonImmutable::parse($valor)->setTimezone('America/La_Paz')->format('d/m/Y H:i');
    }

    public function html(array $d): string
    {
        $c = $d['contexto'];
        $tipo = $c['reporte'];
        $pacientes = $tipo === 'pacientes/registrados';
        $cantidades = $tipo === 'citas/cantidades';
        $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><style>'
            .'@page{margin:30pt 30pt 42pt}body{font-family:"DejaVu Sans",sans-serif;font-size:8pt;color:#18333b}'
            .'h1{font-size:16pt;margin:5pt 0}p{margin:5pt 0}table{border-collapse:collapse;width:100%;table-layout:fixed}'
            .'thead{display:table-header-group}tr{page-break-inside:avoid}th,td{padding:5pt;border:1px solid #ccd7da;text-align:left;overflow-wrap:break-word}'
            .'th{background:#edf3f4}small{font-size:7pt}</style></head><body>'
            .'<p>Clínica Vida Salud — proyecto universitario, clínica ficticia</p><h1>'.$this->escapar(self::TITULOS[$tipo]).'</h1>'
            .'<p>Período: '.$this->escapar($c['periodo']['desde']).' a '.$this->escapar($c['periodo']['hasta']).' (inclusive)</p>'
            .'<p>Generado: '.$this->escapar($d['generado_en']).' — America/La_Paz</p>'
            .'<p>Referencia: '.$this->escapar($c['periodo']['referencia']).' / '.$this->escapar($c['periodo']['criterio']).'. Estados actuales; no es un corte histórico.</p>'
            .'<p>Nueva lectura del servidor; puede diferir de la consulta previa en pantalla.</p><p>Filtros efectivos: ';
        foreach ($c['filtros'] as $clave => $valor) {
            $texto = $valor === null ? 'Sin restricción' : (is_bool($valor) ? ($valor ? 'Sí' : 'No') : (is_array($valor) ? implode(', ', $valor) : $valor));
            $html .= $this->escapar($clave).': '.$this->escapar($texto).'; ';
        }
        $html .= '</p><p><strong>Total: '.$d['total'].'</strong></p>';
        if ($tipo === 'agenda-diaria') {
            $html .= '<p>Médico: '.$this->escapar($c['medico']['codigo_medico'].' — '.$c['medico']['apellidos'].', '.$c['medico']['nombres'])
                .'. Intersección con el día; no sumar agendas como conteo de citas. Orden: inicio ascendente.</p>';
        }
        if ($d['total'] === 0) {
            $html .= '<p>Sin resultados para los filtros aplicados.</p>';
        }
        $cabeceras = $cantidades ? ['Atendidas', 'Canceladas', 'Inasistencias', 'Total']
            : ($pacientes ? ['Código de Paciente', 'Paciente', 'Activo', 'Registro (hora local)']
                : ['Cita', 'Inicio / fin (hora local)', 'Paciente', 'Médico', 'Especialidad', 'Estado / duración', 'Ubicación presencial']);
        $html .= '<table><thead><tr>';
        foreach ($cabeceras as $cabecera) {
            $html .= '<th>'.$this->escapar($cabecera).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        $filas = $cantidades ? [array_map('intval', $d['cantidades'])] : $d['filas'];
        foreach ($filas as $fila) {
            $valores = $cantidades ? [$fila['atendidas'], $fila['canceladas'], $fila['inasistencias'], $fila['total']]
                : ($pacientes ? [$fila['codigo_paciente'], $fila['apellidos'].', '.$fila['nombres'], $fila['activo'] ? 'Sí' : 'No', $this->fecha($fila['creado_en'])]
                    : [$fila['codigo_cita'], $this->fecha($fila['inicio_at']).' / '.$this->fecha($fila['fin_at']),
                        $fila['paciente_apellidos'].', '.$fila['paciente_nombres'], $fila['medico_apellidos'].', '.$fila['medico_nombres'],
                        $fila['especialidad_nombre'], $fila['estado'].' / '.$fila['duracion_minutos'].' min', $fila['sede_atencion'].' / '.$fila['consultorio_atencion']]);
            $html .= '<tr>';
            foreach ($valores as $valor) {
                $html .= '<td>'.$this->escapar($valor).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></body></html>';
    }
}
