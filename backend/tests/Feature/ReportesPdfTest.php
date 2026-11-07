<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\utils\ReportePdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\Fixtures\ReportesAisladosFixtures;
use Tests\TestCase;

class ReportesPdfTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures, ReportesAisladosFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aislarReportes();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local'; // Cookies, sesión y CSRF reales del kernel.
        Carbon::setTestNow('2026-11-02T02:00:00.123456Z');
        CarbonImmutable::setTestNow('2026-11-02T02:00:00.123456Z');
        Mail::fake();
    }

    public static function tipos(): array
    {
        return array_map(fn ($tipo) => [$tipo], ['citas/programadas', 'citas/por-medico', 'citas/por-especialidad',
            'citas/por-estado', 'pacientes/registrados', 'citas/cantidades', 'agenda-diaria']);
    }

    private function contexto(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['creado_en' => '2020-01-01T12:00:00Z',
            'documento_identidad' => 'CI_PRIVADO_PDF', 'telefono' => 'CONTACTO_PRIVADO_PDF']);

        return [$cookies, $r, $actor];
    }

    private function filtros(string $tipo, array $r): array
    {
        return ['fecha' => '2026-11-01', ...match ($tipo) {
            'citas/por-medico', 'agenda-diaria' => ['id_medico' => (string) $r['doctors'][0]],
            'citas/por-especialidad' => ['id_especialidad' => (string) $r['specialty']],
            'citas/por-estado' => ['estado' => 'Cancelada'],
            default => [],
        }];
    }

    private function consultar(array &$cookies, string $tipo, array $filtros, bool $pdf = true)
    {
        return $this->navegador($cookies, 'GET', '/api/reportes/'.$tipo.($pdf ? '/pdf' : '').'?'.http_build_query($filtros));
    }

    private function pacientes(int $numero): void
    {
        $filas = [];
        for ($i = 0; $i < $numero; $i++) {
            $filas[] = ['codigo_paciente' => sprintf('SINTETICO-%04d', $i), 'nombres' => 'María José — datos ficticios',
                'apellidos' => 'Álvarez Muñoz de Prueba', 'activo' => $i % 2 === 0,
                'fecha_nacimiento' => '1990-01-01', 'telefono' => 'CONTACTO_PRIVADO_PDF', 'creado_en' => '2026-11-01T12:00:00Z'];
        }
        DB::table('pacientes')->insert($filas);
    }

    private function citas(array $r, int $numero, string $estado = 'Cancelada'): void
    {
        $filas = [];
        for ($i = 0; $i < $numero; $i++) {
            $inicio = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addMinutes($i);
            $filas[] = $this->citaRow($r, ['codigo_cita' => sprintf('SINTETICO-%04d', $i), 'estado' => $estado,
                'inicio_at' => $inicio->toIso8601String(), 'fin_at' => $inicio->addMinute()->toIso8601String(),
                'duracion_minutos' => 1, 'motivo_breve' => 'CONTENIDO_CLINICO_PRIVADO_PDF']);
        }
        DB::table('citas')->insert($filas);
    }

    private function capturar(?array &$documento, ?string &$html): void
    {
        $real = new ReportePdf;
        $renderer = Mockery::mock(ReportePdf::class)->makePartial();
        $renderer->shouldReceive('html')->andReturnUsing(function ($d) use (&$documento, &$html, $real) {
            $documento = $d;

            return $html = $real->html($d);
        });
        $this->app->instance(ReportePdf::class, $renderer);
    }

    private function evidencia(string $nombre, string $bytes): void
    {
        $directorio = getenv('REPORTES_PDF_EVIDENCIA');
        if ($directorio && is_dir($directorio)) {
            file_put_contents($directorio.'/'.$nombre, $bytes);
        }
    }

    #[DataProvider('tipos')]
    public function test_seven_pdf_types_match_existing_json_filters_totals_and_privacy(string $tipo): void
    {
        [$cookies, $r] = $this->contexto();
        $this->pacientes(3);
        $this->citas($r, 3, $tipo === 'citas/programadas' ? 'Programada' : 'Cancelada');
        $filtros = $this->filtros($tipo, $r);
        if ($tipo === 'pacientes/registrados') {
            $filtros += ['activo' => '0', 'orden' => 'nombre_asc'];
        } elseif ($tipo === 'citas/por-medico') {
            unset($filtros['fecha']);
            $filtros += ['desde' => '2026-11-01', 'hasta' => '2026-11-02', 'id_especialidad' => $r['specialty'], 'estado' => 'Cancelada', 'orden' => 'fecha_desc'];
        }
        $json = $this->consultar($cookies, $tipo, $filtros, false)->assertOk();
        $antes = [];
        foreach (['citas', 'pacientes', 'medicos', 'especialidades', 'consultorios', 'bitacora'] as $tabla) {
            $antes[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $this->capturar($documento, $html);
        $pdf = $this->consultar($cookies, $tipo, $filtros)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $bytes = $pdf->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringEndsWith('%%EOF', rtrim($bytes));
        $this->assertSame((string) strlen($bytes), $pdf->headers->get('Content-Length'));
        $this->assertMatchesRegularExpression('/^attachment; filename="reporte-[a-z-]+-20261101-220000\.pdf"$/', $pdf->headers->get('Content-Disposition'));
        $this->assertSame($json->json('meta.filtros'), $documento['contexto']['filtros']);
        $this->assertSame($json->json('meta.periodo'), $documento['contexto']['periodo']);
        $this->assertSame($json->json('meta.estadisticas'), $documento['contexto']['estadisticas']);
        $this->assertSame($tipo === 'citas/cantidades' ? $json->json('data.total') : $json->json('meta.total'), $documento['total']);
        $this->assertStringContainsString('2026-11-01 22:00:00 -04:00', $html);
        $this->assertStringContainsString('America/La_Paz', $html);
        foreach (['CI_PRIVADO_PDF', 'CONTACTO_PRIVADO_PDF', 'CONTENIDO_CLINICO_PRIVADO_PDF', 'documento_identidad', 'motivo_breve', 'centavos_bob'] as $privado) {
            $this->assertStringNotContainsString($privado, $html.json_encode($documento));
        }
        foreach ($antes as $tabla => $contenido) {
            $this->assertSame($contenido, DB::table($tabla)->get()->toJson());
        }
        $this->evidencia(str_replace('/', '-', $tipo).'.pdf', $bytes);
        $this->evidencia(str_replace('/', '-', $tipo).'.html', $html);
    }

    #[DataProvider('tipos')]
    public function test_empty_results_are_downloadable_with_zero_total(string $tipo): void
    {
        [$cookies, $r] = $this->contexto();
        $this->capturar($documento, $html);
        $this->consultar($cookies, $tipo, $this->filtros($tipo, $r))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, $documento['total']);
        $this->assertStringContainsString('Sin resultados', $html);
        if ($tipo === 'citas/cantidades') {
            $this->assertSame(['atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0, 'total' => 0], array_map('intval', $documento['cantidades']));
        } else {
            $this->assertSame([], $documento['filas']);
        }
    }

    #[DataProvider('tipos')]
    public function test_all_downloads_require_normal_admin_session_and_closed_filters(string $tipo): void
    {
        $cookies = $this->csrfAuth();
        $this->consultar($cookies, $tipo, [])->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        foreach (['Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $actor = $this->usuarioAuth($rol);
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $actor)->assertOk();
            $this->consultar($cookies, $tipo, [])->assertForbidden();
        }
        [$cookies, $r] = $this->contexto();
        $filtros = $this->filtros($tipo, $r);
        $this->consultar($cookies, $tipo, [...$filtros, 'page' => 2, 'per_page' => 1, 'formato' => 'html', 'id_paciente' => 1])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR')
            ->assertJsonValidationErrors(['page', 'per_page', 'formato', 'id_paciente'], 'error.details');
        $this->consultar($cookies, $tipo, [...$filtros, 'fecha' => '2026-02-30'])->assertUnprocessable();
    }

    public function test_export_includes_multiple_data_pages_and_exact_500_boundary_without_truncation(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->pacientes(500);
        $filtros = ['fecha' => '2026-11-01', 'orden' => 'registro_desc'];
        $this->consultar($cookies, 'pacientes/registrados', [...$filtros, 'per_page' => 20, 'page' => 2], false)
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 500)->assertJsonPath('meta.current_page', 2);
        $this->capturar($documento, $html);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'WITH filtradas')) {
                $queries[] = $q;
            }
        });
        $pdf = $this->consultar($cookies, 'pacientes/registrados', $filtros)->assertOk();
        $this->assertSame(500, $documento['total']);
        $this->assertCount(500, $documento['filas']);
        $this->assertSame('SINTETICO-0499', $documento['filas'][0]['codigo_paciente']);
        $this->assertSame('SINTETICO-0000', $documento['filas'][499]['codigo_paciente']);
        $this->assertCount(1, $queries);
        $this->assertSame([501, 0], array_slice($queries[0]->bindings, -2));
        $this->evidencia('sintetico-500-filas.pdf', $pdf->getContent());
        $this->evidencia('sintetico-500-filas.html', $html);
    }

    public static function listados(): array
    {
        return array_values(array_filter(self::tipos(), fn ($fila) => $fila[0] !== 'citas/cantidades'));
    }

    public function test_agenda_exports_all_data_pages_with_intersection_and_long_accented_names(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->citas($r, 45);
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['nombres' => 'María José Álvarez Muñoz — '.str_repeat('Nombre largo ficticio ', 4)]);
        DB::table('citas')->insert($this->citaRow($r, ['codigo_cita' => 'SINTETICO-CRUCE', 'estado' => 'Cancelada',
            'inicio_at' => '2026-11-01T03:50:00Z', 'fin_at' => '2026-11-01T04:05:00Z']));
        $filtros = $this->filtros('agenda-diaria', $r) + ['estado' => 'Cancelada'];
        $this->consultar($cookies, 'agenda-diaria', [...$filtros, 'page' => 2], false)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 46);
        $this->capturar($documento, $html);
        $pdf = $this->consultar($cookies, 'agenda-diaria', $filtros)->assertOk();
        $this->assertCount(46, $documento['filas']);
        $this->assertSame(46, $documento['total']);
        $this->assertSame('SINTETICO-CRUCE', $documento['filas'][0]['codigo_cita']);
        $this->assertSame('SINTETICO-0044', $documento['filas'][45]['codigo_cita']);
        $this->assertStringContainsString('31/10/2026 23:50', $html);
        $this->evidencia('sintetico-agenda-46-filas.pdf', $pdf->getContent());
        $this->evidencia('sintetico-agenda-46-filas.html', $html);
    }

    #[DataProvider('listados')]
    public function test_excess_never_renders_or_returns_partial_pdf(string $tipo): void
    {
        [$cookies, $r] = $this->contexto();
        if ($tipo === 'pacientes/registrados') {
            $this->pacientes(501);
        } else {
            $this->citas($r, 501, $tipo === 'citas/programadas' ? 'Programada' : 'Cancelada');
        }
        $pdf = Mockery::mock(ReportePdf::class);
        $pdf->shouldNotReceive('generar');
        $this->app->instance(ReportePdf::class, $pdf);
        $response = $this->consultar($cookies, $tipo, $this->filtros($tipo, $r))->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonValidationErrors('exportacion', 'error.details');
        $this->assertStringContainsString('500 filas', $response->getContent());
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    public function test_aggregate_counts_all_rows_over_500_with_correct_category_totals(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->citas($r, 501);
        foreach (['Atendida', 'Atendida', 'Inasistencia', 'Programada', 'Confirmada'] as $i => $estado) {
            $inicio = CarbonImmutable::parse('2026-11-01T22:00:00Z')->addMinutes($i * 15);
            DB::table('citas')->insert($this->citaRow($r, ['estado' => $estado, 'inicio_at' => $inicio->toIso8601String(), 'fin_at' => $inicio->addMinutes(15)->toIso8601String()]));
        }
        $this->capturar($documento, $html);
        $this->consultar($cookies, 'citas/cantidades', ['fecha' => '2026-11-01', 'id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']])->assertOk();
        $this->assertSame(['atendidas' => 2, 'canceladas' => 501, 'inasistencias' => 1, 'total' => 504], array_map('intval', $documento['cantidades']));
        $this->assertStringContainsString('<td>504</td>', $html);
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['sesion']];
    }

    private function revocar(string $cambio, string $id): void
    {
        if ($cambio === 'estado') {
            DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Desactivada']);
        } elseif ($cambio === 'rol') {
            DB::table('usuarios')->where('id_usuario', $id)->update(['id_rol' => DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol')]);
        } else {
            DB::table('sessions')->where('user_id', $id)->delete();
        }
    }

    #[DataProvider('revocaciones')]
    public function test_authorization_is_rechecked_before_query_after_middleware(string $cambio): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $original = $this->app->make(UsuarioRepository::class);
        $repo = Mockery::mock(UsuarioRepository::class)->makePartial();
        $repo->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock)->once()
            ->andReturnUsing(function ($id, $lock) use ($original, $cambio) {
                $this->revocar($cambio, (string) $id);

                return $original->porId($id, $lock);
            });
        $this->app->instance(UsuarioRepository::class, $repo);
        $pdf = Mockery::mock(ReportePdf::class);
        $pdf->shouldNotReceive('generar');
        $this->app->instance(ReportePdf::class, $pdf);
        $this->consultar($cookies, 'pacientes/registrados', ['fecha' => '2026-11-01'])->assertUnauthorized();
    }

    #[DataProvider('revocaciones')]
    public function test_revocation_during_render_prevents_delivery_of_pdf_bytes(string $cambio): void
    {
        [$cookies, $r, $actor] = $this->contexto();
        $pdf = Mockery::mock(ReportePdf::class);
        $pdf->shouldReceive('generar')->once()->andReturnUsing(function () use ($actor, $cambio) {
            $this->revocar($cambio, (string) $actor->getKey());

            return '%PDF-BYTES_QUE_NO_SE_DEBEN_ENTREGAR';
        });
        $this->app->instance(ReportePdf::class, $pdf);
        $response = $this->consultar($cookies, 'pacientes/registrados', ['fecha' => '2026-11-01'])->assertUnauthorized();
        $this->assertStringNotContainsString('BYTES_QUE', $response->getContent());
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    public function test_restricted_session_origin_get_body_and_throttle_are_enforced(): void
    {
        [$cookies, $r] = $this->contexto();
        $target = $this->usuarioAuth();
        $receipt = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])->assertCreated()->json('data');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $receipt['credencial_temporal'])->assertOk();
        foreach (self::tipos() as [$tipo]) {
            $this->consultar($restricted, $tipo, [])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
            // Permisos se verifican sin agotar la cuota compartida; se prueba 429 abajo.
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        }
        $uri = '/api/reportes/pacientes/registrados/pdf?fecha=2026-11-01';
        $this->navegador($cookies, 'GET', $uri, headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->navegador($cookies, 'GET', $uri, ['fecha' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('body', 'error.details');
        $pdf = Mockery::mock(ReportePdf::class);
        $pdf->shouldReceive('generar')->times(5)->andReturn('%PDF-SINTETICO');
        $this->app->instance(ReportePdf::class, $pdf);
        for ($i = 0; $i < 5; $i++) {
            $this->navegador($cookies, 'GET', $uri)->assertOk();
        }
        $this->navegador($cookies, 'GET', $uri)->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After');
    }

    public function test_renderer_escapes_untrusted_text_blocks_markup_and_has_pre_render_size_limit(): void
    {
        [$cookies, $r] = $this->contexto();
        $this->pacientes(1);
        DB::table('pacientes')->where('codigo_paciente', 'SINTETICO-0000')->update(['nombres' => '<img src="https://example.test/a"> & <script>NO_EJECUTAR</script>']);
        $this->capturar($documento, $html);
        $this->consultar($cookies, 'pacientes/registrados', ['fecha' => '2026-11-01'])->assertOk();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $renderer = Mockery::mock(ReportePdf::class)->makePartial();
        $renderer->shouldReceive('html')->once()->andReturn(str_repeat('x', ReportePdf::MAX_HTML_BYTES + 1));
        $this->expectException(ValidationException::class);
        $renderer->generar($documento);
    }

    public function test_unexpected_renderer_failure_has_safe_json_without_internal_details(): void
    {
        [$cookies] = $this->contexto();
        $pdf = Mockery::mock(ReportePdf::class);
        $pdf->shouldReceive('generar')->once()->andThrow(new RuntimeException('RUTA_INTERNA SQL MOTIVO_PRIVADO'));
        $this->app->instance(ReportePdf::class, $pdf);
        $response = $this->consultar($cookies, 'pacientes/registrados', ['fecha' => '2026-11-01'])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertStringNotContainsString('RUTA_INTERNA', $response->getContent());
        $this->assertNull($response->headers->get('Content-Disposition'));
    }
}
