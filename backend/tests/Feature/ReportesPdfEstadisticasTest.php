<?php

namespace Tests\Feature;

use Clinica\business\services\reportes\ReporteExportacionService;
use Clinica\business\services\reportes\ReporteService;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ReportePdf;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\ReportesPdfEstadisticasDocumentos as Documentos;

/** Pruebas SIN SQL ni kernel HTTP. Dompdf real salvo dobles explícitos de límites/exportación. */
class ReportesPdfEstadisticasTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $dir = getenv('EST4_EVIDENCE_DIR');
        if (! $dir || ! is_dir($dir)) {
            throw new RuntimeException('EST4_EVIDENCE_DIR debe ser un directorio externo existente.');
        }
        if (! is_dir($dir.'/storage/framework/cache')) {
            mkdir($dir.'/storage/framework/cache', 0777, true);
        }
        $this->app->useStoragePath($dir.'/storage');
        // Sin bootstrap/providers/DatabaseManager: una consulta no puede resolverse.
        $this->app->bind('db', fn () => throw new RuntimeException('SQL no autorizado en EST-4.'));
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader, 'es'), $this->app));
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $this->app->flush();
            Application::setInstance(null);
            parent::tearDown();
        }
    }

    public static function variantes(): array
    {
        $casos = [];
        foreach (Documentos::tipos() as [$tipo]) {
            foreach ([false, true] as $vacio) {
                $casos[$tipo.($vacio ? '-vacio' : '-datos')] = [$tipo, $vacio];
            }
        }

        return $casos;
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    private function guardar(string $nombre, array $d): string
    {
        $renderer = new ReportePdf;
        $html = $renderer->html($d);
        $bytes = $renderer->generar($d);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringEndsWith('%%EOF', rtrim($bytes));
        $this->assertLessThanOrEqual(ReportePdf::MAX_PDF_BYTES, strlen($bytes));
        $dir = getenv('EST4_EVIDENCE_DIR');
        file_put_contents($dir.'/'.$nombre.'.pdf', $bytes);
        file_put_contents($dir.'/'.$nombre.'.html', $html);
        file_put_contents($dir.'/'.$nombre.'.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $html;
    }

    #[DataProvider('variantes')]
    public function test_seven_synthetic_documents_and_empties_with_real_dompdf(string $tipo, bool $vacio): void
    {
        $d = Documentos::documento($tipo, $vacio);
        $html = $this->guardar(str_replace('/', '-', $tipo).($vacio ? '-vacio' : ''), $d);
        $x = $this->dom($html);
        $e = $d['contexto']['estadisticas'];
        $this->assertStringContainsString('thead tr{page-break-after:avoid}', $html);
        $this->assertLessThan(strpos($html, 'Detalle del reporte'), strpos($html, 'Resumen estadístico'));
        $this->assertSame($d['total'], $e['resumen']['cantidad_total']);
        $this->assertSame(count($e['series']), $x->query('//table[@class="estadistica"]')->length);
        foreach ($e['series'] as $i => $s) {
            $this->assertSame($d['total'], array_sum(array_column($s['categorias'], 'cantidad')));
            $tabla = $x->query('//table[@class="estadistica"]')->item($i);
            $filas = $x->query('./tbody/tr', $tabla);
            $this->assertSame(max(1, count($s['categorias'])), $filas->length);
            foreach ($s['categorias'] as $j => $c) {
                $celdas = $x->query('./td', $filas->item($j));
                $this->assertSame($c['etiqueta'], $celdas->item(0)->textContent);
                $this->assertSame((string) $c['cantidad'], $celdas->item($s['dimension'] === 'periodo' ? 2 : 1)->textContent);
            }
        }
        $this->assertSame(count(array_filter($e['series'], fn ($s) => $s['grafico']['visible'])), $x->query('//table[@class="grafico"]')->length);
        if ($vacio) {
            $this->assertStringContainsString('Sin resultados para los filtros aplicados.', $html);
            $this->assertSame(0, $x->query('//td[@class="relleno"]')->length);
        } elseif ($tipo !== 'citas/cantidades') {
            $this->assertCount($d['total'], $d['filas']);
            $this->assertSame($d['total'], $x->query('//h2[text()="Detalle del reporte"]/following-sibling::table[1]/tbody/tr')->length);
        }
        $this->assertStringContainsString('Nueva lectura del servidor; puede diferir', $html);
        $this->assertStringContainsString('2026-11-07 12:00:00 -04:00', $html);
        $this->assertStringContainsString('America/La_Paz', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_geometry_zero_one_four_and_server_visibility_without_reconstruction(): void
    {
        $d = Documentos::documento('citas/cantidades'); // 1,4,0
        $renderer = new ReportePdf;
        $x = $this->dom($renderer->html($d));
        $barras = $x->query('//table[@class="barra"]');
        $this->assertSame(2, $barras->length);
        $this->assertSame('width:25.0000%', $x->query('.//td[@class="relleno"]', $barras->item(0))->item(0)->getAttribute('style'));
        $this->assertSame('width:75.0000%', $x->query('.//td[not(@class)]', $barras->item(0))->item(0)->getAttribute('style'));
        $this->assertSame('width:100.0000%', $x->query('.//td[@class="relleno"]', $barras->item(1))->item(0)->getAttribute('style'));
        $this->assertSame(1, $x->query('.//td', $barras->item(1))->length);
        $this->assertSame(0, $x->query('//table[@class="grafico"]/tbody/tr[3]//table')->length);
        $this->assertSame('0', $x->query('//table[@class="grafico"]/tbody/tr[3]/td[3]')->item(0)->textContent);
        // Centinelas intencionadamente inconsistentes: el renderer NO recalcula máximo/utilidad.
        $d['contexto']['estadisticas']['series'][0]['grafico']['maximo'] = 8;
        $this->assertStringContainsString('width:12.5000%', $renderer->html($d));
        $d['contexto']['estadisticas']['series'][0]['grafico']['visible'] = false;
        $d['contexto']['estadisticas']['series'][0]['grafico']['motivo_omision'] = 'unica_categoria_con_datos';
        $html = $renderer->html($d);
        $this->assertStringNotContainsString('class="grafico"', $html);
        $this->assertStringContainsString('una única categoría con datos', $html);
        $this->assertStringContainsString('class="estadistica"', $html);
    }

    public static function porcentajes(): array
    {
        return [[[0, 0, 0], ['No aplica', 'No aplica', 'No aplica']], [[1, 1, 1], ['33,3 %', '33,3 %', '33,3 %']],
            [[1, 15, 0], ['6,3 %', '93,8 %', '0,0 %']], [[1, 1, 4], ['16,7 %', '16,7 %', '66,7 %']]];
    }

    #[DataProvider('porcentajes')]
    public function test_percentage_strings_rounding_denominator_and_no_adjustment(array $conteos, array $esperados): void
    {
        $d = Documentos::cantidades(Documentos::documento('citas/cantidades'), $conteos);
        $html = (new ReportePdf)->html($d);
        $x = $this->dom($html);
        foreach ($esperados as $i => $texto) {
            $this->assertSame($texto, $x->query('//table[@class="estadistica"]/tbody/tr['.($i + 1).']/td[3]')->item(0)->textContent);
        }
        $this->assertSame(array_sum($conteos) > 0, str_contains($html, 'Los porcentajes pueden sumar 99,9 % o 100,1 % por redondeo.'));
        $this->assertStringContainsString('Denominador: '.array_sum($conteos).' citas en Atendida, Cancelada e Inasistencia.', $html);
        $this->assertStringNotContainsString('tasa', $html);
        if (array_sum($conteos) > 0) {
            $d['contexto']['estadisticas']['series'][0]['categorias'][0]['porcentaje'] = '42.7';
            $this->assertStringContainsString('42,7 %', (new ReportePdf)->html($d)); // Copia literal servidor, no fórmula renderer.
        }
    }

    public static function temporales(): array
    {
        return [['citas/por-estado', '2026-11-01', '2026-11-01', 1, 'diaria'],
            ['pacientes/registrados', '2026-01-01', '2026-01-31', 31, 'diaria'],
            ['citas/por-estado', '2026-01-20', '2026-02-20', 2, 'mensual'],
            ['pacientes/registrados', '2024-01-20', '2025-01-19', 13, 'mensual']];
    }

    #[DataProvider('temporales')]
    public function test_temporal_intervals_zeros_partial_months_and_activity(string $tipo, string $desde, string $hasta, int $categorias, string $agrupacion): void
    {
        $d = Documentos::documento($tipo, true);
        $d['contexto']['periodo'] = Documentos::periodo($desde, $hasta);
        $d['contexto']['estadisticas'] = Documentos::mapear($d['contexto'], ['total' => 0, 'grupos' => [], 'actividad' => []]);
        $html = (new ReportePdf)->html($d);
        $x = $this->dom($html);
        $serie = $d['contexto']['estadisticas']['series'][$tipo === 'pacientes/registrados' ? 1 : 0];
        $this->assertCount($categorias, $serie['categorias']);
        $this->assertStringContainsString('Agrupación '.$agrupacion, $html);
        foreach ($serie['categorias'] as $c) {
            $this->assertStringContainsString($c['intervalo']['desde'].' al '.$c['intervalo']['hasta'], $html);
            if ($c['intervalo']['parcial']) {
                $this->assertStringContainsString($c['intervalo']['hasta'].' (mes parcial)', $html);
            }
        }
        $this->assertSame(0, $x->query('//table[@class="barra"]')->length);
        if ($tipo === 'pacientes/registrados') {
            $this->assertStringContainsString('no sumar ambas series', $html);
            $this->assertStringContainsString('Actividad actual de las fichas', $html);
        }
    }

    public function test_minutes_historical_cross_midnight_and_only_approved_reports(): void
    {
        foreach (Documentos::tipos() as [$tipo]) {
            $d = Documentos::documento($tipo);
            $html = (new ReportePdf)->html($d);
            $minutos = in_array($tipo, ['citas/por-medico', 'agenda-diaria'], true);
            $this->assertSame($minutos, str_contains($html, 'Duración histórica completa (minutos)'));
            if ($minutos) {
                $this->assertStringContainsString('200</strong>', $html);
                $this->assertStringContainsString('<td>160</td>', $html);
                $this->assertStringContainsString('<td>40</td>', $html);
            }
            if ($tipo === 'agenda-diaria') {
                $this->assertStringContainsString('31/10/2026 23:50 / 01/11/2026 00:30', $html);
                $this->assertStringContainsString('No sumar agendas como cantidades o minutos únicos.', $html);
            }
        }
    }

    public function test_real_monthly_pdfs_with_partial_extremes_and_server_intervals(): void
    {
        foreach (['citas/por-estado', 'pacientes/registrados'] as $tipo) {
            $d = Documentos::documento($tipo);
            $d['contexto']['periodo'] = Documentos::periodo('2026-01-20', '2026-02-20', $tipo === 'pacientes/registrados' ? 'pacientes.creado_en' : 'citas.inicio_at');
            $d['contexto']['filtros'] = ['desde' => '2026-01-20', 'hasta' => '2026-02-20'];
            if ($tipo === 'citas/por-estado') {
                $d['contexto']['filtros']['estado'] = 'Cancelada';
            }
            foreach ($d['filas'] as $i => &$fila) {
                $inicio = $i === 4 ? '2026-02-20T12:00:00Z' : '2026-01-20T12:00:00Z';
                if ($tipo === 'pacientes/registrados') {
                    $fila['creado_en'] = $inicio;
                } else {
                    $fila['inicio_at'] = $inicio;
                    $fila['fin_at'] = str_replace('12:00', '12:40', $inicio);
                }
            }
            unset($fila);
            $d['contexto']['estadisticas'] = Documentos::mapear($d['contexto'], ['total' => 5,
                'grupos' => [['periodo' => '2026-01', 'cantidad' => 4], ['periodo' => '2026-02', 'cantidad' => 1]],
                'actividad' => [['activo' => true, 'cantidad' => 4], ['activo' => false, 'cantidad' => 1]]]);
            $html = $this->guardar(str_replace('/', '-', $tipo).'-meses-parciales', $d);
            $this->assertStringContainsString('2026-01-20 al 2026-01-31 (mes parcial)', $html);
            $this->assertStringContainsString('2026-02-01 al 2026-02-20 (mes parcial)', $html);
        }
    }

    public function test_long_names_homonyms_all_categories_escape_and_multipage_real_renderer(): void
    {
        $d = Documentos::muchosMedicos();
        $html = $this->guardar('muchos-medicos-nombres-largos', $d);
        $x = $this->dom($html);
        $this->assertSame(60, $x->query('//table[@class="grafico"]/tbody/tr')->length);
        $this->assertSame(60, $x->query('//table[@class="estadistica"]/tbody/tr')->length);
        foreach (range(1, 60) as $i) {
            $this->assertStringContainsString(sprintf('MED-%03d', $i), $html);
        }
        $this->assertStringContainsString('MED-002', (new ReportePdf)->html(Documentos::documento('citas/programadas')));
        $this->assertStringContainsString('MED-010', (new ReportePdf)->html(Documentos::documento('citas/programadas')));
        $d['contexto']['estadisticas']['series'][0]['categorias'][0]['etiqueta'] = '<img src="https://ajeno.test"> & <script>NO</script>';
        $d['contexto']['filtros']['orden'] = '<b>NO</b>';
        $d['filas'][0]['paciente_nombres'] = '<svg onload="NO">';
        $html = (new ReportePdf)->html($d);
        foreach (['<img', '<script', '<svg', '<b>NO'] as $etiqueta) {
            $this->assertStringNotContainsString($etiqueta, $html);
        }
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;svg', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->guardar('pacientes-500', Documentos::documento('pacientes/registrados', numero: 500));
    }

    public static function listados(): array
    {
        return array_values(array_filter(Documentos::tipos(), fn ($fila) => $fila[0] !== 'citas/cantidades'));
    }

    private function servicio(array $d, array $filtros): ReporteService
    {
        $mock = Mockery::mock(ReporteService::class);
        $tipo = $d['contexto']['reporte'];
        $resultado = ['contexto' => $d['contexto']];
        if ($tipo === 'citas/cantidades') {
            $resultado['cantidades'] = $d['cantidades'];
            $mock->shouldReceive('cantidades')->with($filtros)->once()->andReturn($resultado);
        } else {
            $resultado['pagina'] = new LengthAwarePaginator($d['filas'], $d['total'], 501, 1);
            $entrada = [...$filtros, 'page' => 1, 'per_page' => 501];
            if (str_starts_with($tipo, 'citas/')) {
                $mock->shouldReceive('citas')->with($tipo, $entrada)->once()->andReturn($resultado);
            } else {
                $mock->shouldReceive($tipo === 'agenda-diaria' ? 'agendaDiaria' : 'pacientesRegistrados')->with($entrada)->once()->andReturn($resultado);
            }
        }

        return $mock;
    }

    #[DataProvider('variantes')]
    public function test_exporter_passes_same_envelope_and_authorizes_after_render_with_doubles(string $tipo, bool $vacio): void
    {
        $d = Documentos::documento($tipo, $vacio);
        $filtros = $d['contexto']['filtros'];
        $service = $this->servicio($d, $filtros);
        $orden = [];
        $renderer = Mockery::mock(ReportePdf::class);
        $renderer->shouldReceive('generar')->once()->andReturnUsing(function ($documento) use ($d, &$orden) {
            $this->assertSame($d['contexto'], $documento['contexto']);
            $this->assertSame($d['filas'], $documento['filas']);
            $this->assertSame($d['total'], $documento['total']);
            $this->assertSame($d['cantidades'], $documento['cantidades']);
            $orden[] = 'render';

            return '%PDF-SINTETICO';
        });
        $service->shouldReceive('autorizarEntrega')->once()->andReturnUsing(function () use (&$orden) {
            $orden[] = 'autorizar';
        });
        $resultado = (new ReporteExportacionService($service, $renderer))->generar($tipo, $filtros);
        $this->assertSame(['render', 'autorizar'], $orden);
        $this->assertSame('%PDF-SINTETICO', $resultado['bytes']);
        $this->assertMatchesRegularExpression('/^reporte-[a-z-]+-\d{8}-\d{6}\.pdf$/', $resultado['nombre']);
    }

    #[DataProvider('listados')]
    public function test_500_inclusive_501_rejected_before_render_with_service_double(string $tipo): void
    {
        foreach ([500, 501] as $total) {
            $d = Documentos::documento($tipo, numero: $total);
            $service = $this->servicio($d, []);
            $renderer = Mockery::mock(ReportePdf::class);
            if ($total === 500) {
                $renderer->shouldReceive('generar')->once()->withArgs(function ($documento) {
                    $this->assertCount(500, $documento['filas']);
                    $this->assertSame(500, $documento['contexto']['estadisticas']['resumen']['cantidad_total']);

                    return true;
                })->andReturn('%PDF-SINTETICO');
                $service->shouldReceive('autorizarEntrega')->once();
                $this->assertSame('%PDF-SINTETICO', (new ReporteExportacionService($service, $renderer))->generar($tipo, [])['bytes']);
            } else {
                $renderer->shouldNotReceive('generar');
                $service->shouldNotReceive('autorizarEntrega');
                try {
                    (new ReporteExportacionService($service, $renderer))->generar($tipo, []);
                    $this->fail('Debe rechazar exceso sin PDF parcial.');
                } catch (ValidationException $e) {
                    $this->assertSame(422, $e->status);
                    $this->assertArrayHasKey('exportacion', $e->errors());
                }
            }
        }
    }

    public function test_complete_quantities_over_500_without_detail_and_final_denial_with_doubles(): void
    {
        $d = Documentos::cantidades(Documentos::documento('citas/cantidades'), [168, 168, 168]);
        $this->assertSame([], $d['filas']);
        $this->guardar('cantidades-504', $d);
        $service = $this->servicio($d, []);
        $renderer = Mockery::mock(ReportePdf::class);
        $renderizado = false;
        $renderer->shouldReceive('generar')->once()->andReturnUsing(function ($documento) use (&$renderizado) {
            $this->assertSame(504, $documento['contexto']['estadisticas']['resumen']['cantidad_total']);
            $this->assertSame([], $documento['filas']);
            $renderizado = true;

            return '%PDF-NO_ENTREGAR';
        });
        $service->shouldReceive('autorizarEntrega')->once()->andReturnUsing(function () use (&$renderizado) {
            $this->assertTrue($renderizado);
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        });
        $this->expectException(AppError::class);
        (new ReporteExportacionService($service, $renderer))->generar('citas/cantidades', []);
    }

    public static function limites(): array
    {
        return [['html', false], ['html', true], ['pdf', false], ['pdf', true]];
    }

    #[DataProvider('listados')]
    public function test_final_authorization_denial_never_returns_bytes_with_doubles(string $tipo): void
    {
        $d = Documentos::documento($tipo);
        $service = $this->servicio($d, []);
        $renderer = Mockery::mock(ReportePdf::class);
        $renderizado = false;
        $renderer->shouldReceive('generar')->once()->andReturnUsing(function () use (&$renderizado) {
            $renderizado = true;

            return '%PDF-NO_ENTREGAR';
        });
        $service->shouldReceive('autorizarEntrega')->once()->andReturnUsing(function () use (&$renderizado) {
            $this->assertTrue($renderizado);
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        });
        $this->expectException(AppError::class);
        (new ReporteExportacionService($service, $renderer))->generar($tipo, []);
    }

    #[DataProvider('limites')]
    public function test_exact_and_exceeded_byte_boundaries_with_explicit_renderer_double(string $tipo, bool $exceso): void
    {
        $d = Documentos::documento('citas/cantidades');
        $html = (new ReportePdf)->html($d);
        $renderer = Mockery::mock(ReportePdf::class)->makePartial()->shouldAllowMockingProtectedMethods();
        if ($tipo === 'html') {
            $html .= str_repeat(' ', ReportePdf::MAX_HTML_BYTES + (int) $exceso - strlen($html));
            $renderer->shouldReceive('html')->once()->andReturn($html);
            if ($exceso) {
                $renderer->shouldNotReceive('renderizar');
            } else {
                $renderer->shouldReceive('renderizar')->with($html)->once()->andReturn('%PDF-SINTETICO\n%%EOF');
            }
        } else {
            $bytes = '%PDF-'.str_repeat('x', ReportePdf::MAX_PDF_BYTES + (int) $exceso - 10).'%%EOF';
            $renderer->shouldReceive('renderizar')->with($html)->once()->andReturn($bytes);
        }
        if ($exceso) {
            try {
                $renderer->generar($d);
                $this->fail('Debe rechazar exceso de bytes.');
            } catch (ValidationException $e) {
                $this->assertSame(422, $e->status);
                $this->assertArrayHasKey('exportacion', $e->errors());
            }
        } else {
            $bytes = $renderer->generar($d);
            $this->assertStringStartsWith('%PDF-', $bytes);
            if ($tipo === 'pdf') {
                $this->assertSame(ReportePdf::MAX_PDF_BYTES, strlen($bytes));
            }
        }
    }

    public function test_statistics_size_is_not_silently_downgraded_and_missing_statistics_fails(): void
    {
        $d = Documentos::documento('citas/programadas');
        $d['contexto']['estadisticas']['series'][0]['categorias'][0]['etiqueta'] = str_repeat('nombre ', 400000);
        try {
            (new ReportePdf)->generar($d);
            $this->fail('Estadísticas cuentan para el límite HTML.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('exportacion', $e->errors());
        }
        unset($d['contexto']['estadisticas']);
        $this->expectException(RuntimeException::class);
        (new ReportePdf)->html($d);
    }
}
