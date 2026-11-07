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
        $bytes = $this->renderizar($html);
        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            $this->exceso();
        }
        if (! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
            throw new RuntimeException('El renderer no produjo un PDF.');
        }

        return $bytes;
    }

    protected function renderizar(string $html): string
    {
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

        return $pdf->output();
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

    private function estadisticas(array $contexto): string
    {
        if (! isset($contexto['estadisticas'])) {
            throw new RuntimeException('Faltan las estadísticas del resultado interno.');
        }
        // Representación del mismo sobre del Service: sin consultas ni agregados desde detalle.
        $e = $contexto['estadisticas'];
        $unidad = $this->escapar($e['unidad']);
        $minutos = in_array($contexto['reporte'], ['citas/por-medico', 'agenda-diaria'], true);
        $html = '<h2>Resumen estadístico</h2><p>Conjunto completo con los filtros aplicados.</p>'
            .'<p>Cantidad total ('.$unidad.'): <strong>'.$this->escapar($e['resumen']['cantidad_total']).'</strong></p>';
        if ($minutos) {
            $html .= '<p>Duración histórica completa (minutos): <strong>'.$this->escapar($e['resumen']['duracion_total_minutos']).'</strong></p>'
                .'<p>Duración histórica completa de las citas incluidas; no representa tiempo trabajado, ocupación ni tiempo atendido.</p>';
        }
        if ($contexto['reporte'] === 'agenda-diaria') {
            $html .= '<p>Las citas que cruzan medianoche pueden aparecer en dos días con su duración completa, sin recorte. No sumar agendas como cantidades o minutos únicos.</p>';
        }
        if ($e['unidad'] === 'fichas') {
            $html .= '<p>Actividad actual de las fichas; períodos por creación de ficha, no alta de cuenta ni incorporación de CI. Cada serie representa el mismo conjunto: no sumar ambas series.</p>';
        }
        foreach ($e['series'] as $serie) {
            $html .= $this->serie($serie, $unidad, $minutos);
        }

        return $html;
    }

    private function serie(array $serie, string $unidad, bool $minutos): string
    {
        $dimension = ['medico' => 'Médico', 'estado' => 'Estado', 'actividad' => 'Actividad actual', 'periodo' => 'Período'][$serie['dimension']];
        $titulo = 'Cantidades por '.$dimension;
        $periodo = $serie['dimension'] === 'periodo';
        $porcentajes = $serie['porcentajes'] !== null;
        $html = '<h3>'.$titulo.'</h3>';
        if ($periodo) {
            $html .= '<p>Agrupación '.$this->escapar($serie['agrupacion']).'. Intervalos de la sede, America/La_Paz; incluye períodos sin datos.</p>';
        }
        if ($serie['grafico']['visible']) {
            $html .= '<table class="grafico"><thead><tr><th colspan="3">'.$titulo.' — escala común desde 0 hasta '
                .$this->escapar($serie['grafico']['maximo']).' '.$unidad.'</th></tr>'
                .'<tr><th style="width:300pt">'.$dimension.'</th><th style="width:240pt">Cantidad desde cero ('.$unidad.')</th><th>Valor ('.$unidad.')</th></tr></thead><tbody>';
            foreach ($serie['categorias'] as $categoria) {
                $html .= '<tr><td>'.$this->escapar($categoria['etiqueta']).'</td><td><div class="pista">';
                if ($categoria['cantidad'] > 0) {
                    // Solo geometría; máximo y utilidad ya determinados por Laravel.
                    $ancho = 100 * $categoria['cantidad'] / $serie['grafico']['maximo'];
                    $html .= '<table class="barra"><tr><td class="relleno" style="width:'.number_format($ancho, 4, '.', '').'%"></td>';
                    if ($categoria['cantidad'] < $serie['grafico']['maximo']) {
                        $html .= '<td style="width:'.number_format(100 - $ancho, 4, '.', '').'%"></td>';
                    }
                    $html .= '</tr></table>';
                }
                $html .= '</div></td><td>'.$this->escapar($categoria['cantidad']).'</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p>Gráfico omitido: '.match ($serie['grafico']['motivo_omision']) {
                'sin_datos' => 'sin datos.',
                'unica_categoria_con_datos' => 'una única categoría con datos.',
                default => throw new RuntimeException('Motivo de omisión estadística no válido.'),
            }.' Se conserva la tabla completa.</p>';
        }
        $columnas = 2 + (int) $periodo + (int) $minutos + (int) $porcentajes;
        $html .= '<table class="estadistica"><thead><tr><th colspan="'.$columnas.'">'.$titulo.' — conjunto completo filtrado ('.$unidad.')</th></tr>'
            .'<tr><th>'.$dimension.'</th>'.($periodo ? '<th>Intervalo incluido (fechas de la sede)</th>' : '')
            .'<th>Cantidad ('.$unidad.')</th>'.($minutos ? '<th>Duración histórica completa (minutos)</th>' : '')
            .($porcentajes ? '<th>Porcentaje (%)</th>' : '').'</tr></thead><tbody>';
        foreach ($serie['categorias'] as $categoria) {
            $html .= '<tr><td>'.$this->escapar($categoria['etiqueta']).'</td>';
            if ($periodo) {
                $intervalo = $categoria['intervalo'];
                $html .= '<td>'.$this->escapar($intervalo['desde']).' al '.$this->escapar($intervalo['hasta']).($intervalo['parcial'] ? ' (mes parcial)' : '').'</td>';
            }
            $html .= '<td>'.$this->escapar($categoria['cantidad']).'</td>'
                .($minutos ? '<td>'.$this->escapar($categoria['duracion_minutos']).'</td>' : '')
                .($porcentajes ? '<td>'.($categoria['porcentaje'] === null ? 'No aplica' : $this->escapar(str_replace('.', ',', $categoria['porcentaje'])).' %').'</td>' : '').'</tr>';
        }
        if ($serie['categorias'] === []) {
            $html .= '<tr><td colspan="'.$columnas.'">Sin categorías para los filtros aplicados.</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($porcentajes) {
            $html .= '<p>Denominador: '.$this->escapar($serie['porcentajes']['denominador']).' citas en Atendida, Cancelada e Inasistencia.</p>';
            if ($serie['porcentajes']['denominador'] > 0) {
                $html .= '<p>Los porcentajes pueden sumar 99,9 % o 100,1 % por redondeo.</p>';
            }
        }

        return $html;
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
            .'thead{display:table-header-group}thead tr{page-break-after:avoid}tr{page-break-inside:avoid}th,td{padding:5pt;border:1px solid #ccd7da;text-align:left;overflow-wrap:break-word}'
            .'th{background:#edf3f4}small{font-size:7pt}h2{font-size:12pt;margin:12pt 0 5pt}h3{font-size:10pt;margin:10pt 0 5pt}'
            .'h2,h3{page-break-after:avoid}table{margin-bottom:8pt}.pista{width:240pt;height:8pt;background:#edf3f4}'
            .'table.barra{width:100%;margin:0;border:0;border-collapse:collapse;table-layout:fixed}'
            .'.barra tr,.barra td{padding:0;border:0;height:8pt;line-height:0;font-size:0;background:transparent}.barra td.relleno{background:#246478}'
            .'</style></head><body>'
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
        $html .= $this->estadisticas($c).'<h2>Detalle del reporte</h2>';
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
