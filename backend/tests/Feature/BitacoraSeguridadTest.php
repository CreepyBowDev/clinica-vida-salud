<?php

namespace Tests\Feature;

use Clinica\data\models\Bitacora;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class BitacoraSeguridadTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function preparar(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return [$cookies, (string) DB::table('bitacora')->value('id_evento')];
    }

    #[DataProvider('escrituras')]
    public function test_api_rejects_every_write_method_without_changing_events(string $metodo, bool $detalle): void
    {
        [$cookies, $id] = $this->preparar();
        $antes = DB::table('bitacora')->orderBy('id_evento')->get()->toJson();
        $ruta = '/api/bitacora'.($detalle ? '/'.$id : '');
        $this->navegador($cookies, $metodo, $ruta, ['resultado' => 'ERROR', 'tipo_evento' => 'MANUAL', 'id_evento' => $id])
            ->assertStatus(405)->assertJsonPath('error.code', 'METODO_NO_PERMITIDO');
        $this->assertSame($antes, DB::table('bitacora')->orderBy('id_evento')->get()->toJson());
        $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertJsonPath('data.resultado', 'EXITO');
    }

    public static function escrituras(): array
    {
        $casos = [];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $metodo) {
            $casos[] = [$metodo, false];
            $casos[] = [$metodo, true];
        }

        return $casos;
    }

    public function test_head_is_private_and_empty_and_no_alternative_mutation_route_exists(): void
    {
        [$cookies, $id] = $this->preparar();
        $antes = DB::table('bitacora')->count();
        foreach (['/api/bitacora', '/api/bitacora/'.$id] as $ruta) {
            $this->navegador($cookies, 'HEAD', $ruta)->assertOk()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach (['/api/bitacora/exportar', '/api/bitacora/'.$id.'/editar', '/api/bitacora/'.$id.'/eliminar'] as $ruta) {
            $this->navegador($cookies, 'GET', $ruta)->assertNotFound();
        }
        $this->assertSame($antes, DB::table('bitacora')->count());
    }

    public function test_sql_and_eloquent_mutations_remain_rejected_and_api_reads_original_event_afterwards(): void
    {
        [$cookies, $id] = $this->preparar();
        $antes = $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->json();
        foreach ([
            fn () => DB::table('bitacora')->where('id_evento', $id)->update(['resultado' => 'ERROR']),
            fn () => DB::table('bitacora')->where('id_evento', $id)->delete(),
            fn () => DB::statement('TRUNCATE TABLE bitacora'),
            fn () => Bitacora::query()->findOrFail($id)->forceFill(['resultado' => 'ERROR'])->saveOrFail(),
        ] as $mutacion) {
            try {
                DB::transaction($mutacion);
                $this->fail('PostgreSQL debe impedir alterar la bitácora.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('bitacora_inmutable_ck', $error->getMessage());
            }
            $this->navegador($cookies, 'GET', '/api/bitacora/'.$id)->assertOk()->assertExactJson($antes);
        }
    }

    #[DataProvider('filtrosHostiles')]
    public function test_hostile_filter_shapes_and_client_selected_json_or_sql_are_rejected(array $filtros): void
    {
        [$cookies] = $this->preparar();
        $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query($filtros))->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDACION_ERROR');
    }

    public static function filtrosHostiles(): array
    {
        return [
            [['direccion_ip' => ['192.0.2.10']]], [['id_usuario_responsable' => ['1']]],
            [['page' => ['1']]], [['per_page' => '1e2']], [['resultado' => ['ERROR']]],
            [['referencias' => ['id_medico' => '1']]], [['orderBy' => 'registrado_en; DELETE FROM bitacora']],
            [['entidad_referida' => 'Medico', 'json_path' => "referencias->>'password'"]],
        ];
    }

    public function test_literal_sql_like_values_cannot_expand_results_or_mutate_data(): void
    {
        [$cookies] = $this->preparar();
        $antes = DB::table('bitacora')->count();
        foreach (["' OR 1=1 --", "'; DELETE FROM bitacora; --", '%', '_'] as $valor) {
            $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query(['modulo' => $valor]))
                ->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertSame($antes, DB::table('bitacora')->count());
    }

    public function test_representative_volume_has_bounded_queries_and_uses_existing_order_and_date_indexes(): void
    {
        [$cookies] = $this->preparar();
        $correlacion = (string) Str::uuid();
        DB::insert(<<<'SQL'
            INSERT INTO bitacora (tipo_evento, tipo_actor, modulo, operacion, resultado, ocurrido_en, registrado_en, correlacion, referencias, metadatos_seguros)
            SELECT 'MEDICO_ACTUALIZADO', 'SISTEMA', 'VOLUMEN_FICTICIO', 'PRUEBA', 'EXITO',
                TIMESTAMPTZ '2026-01-01 00:00:00+00' + g * INTERVAL '1 hour',
                TIMESTAMPTZ '2026-01-01 00:00:00+00' + g * INTERVAL '1 second', ?::uuid,
                jsonb_build_object('id_medico', g::text), jsonb_build_object('token_ficticio', repeat('x', 1024))
            FROM generate_series(1, 5000) AS g
            SQL, [$correlacion]);
        DB::statement('ANALYZE bitacora');
        $evidencia = [];
        foreach ([
            'listado' => ['per_page' => 100],
            'listado_uno' => ['per_page' => 1],
            'fecha' => ['fecha_desde' => '2026-02-01', 'fecha_hasta' => '2026-02-01', 'per_page' => 100],
            'recurso' => ['entidad_referida' => 'Medico', 'registro_referido' => '1234', 'per_page' => 100],
        ] as $nombre => $filtros) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $r = $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query($filtros))->assertOk();
                $lecturas = count(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with(strtolower($q['query']), 'select')));
                $consultas = array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/from "bitacora"/i', $q['query'])));
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount(2, $consultas, 'Count y página, sin N+1 por evento.');
            $this->assertLessThanOrEqual(100, count($r->json('data')));
            $this->assertStringNotContainsString('token_ficticio', $r->getContent());
            $this->assertStringNotContainsString('metadatos_seguros', $consultas[1]['query']);
            $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$consultas[1]['query'], $consultas[1]['bindings'])->{'QUERY PLAN'}, true)[0];
            $evidencia[$nombre] = ['total' => $r->json('meta.total'), 'consultas' => count($consultas), 'lecturas_totales' => $lecturas, 'ms_pagina' => $plan['Execution Time']];
            if (str_starts_with($nombre, 'listado')) {
                $this->assertSame(5001, $r->json('meta.total'));
                $this->assertStringContainsString('bitacora_orden_idx', json_encode($plan));
                if ($nombre === 'listado_uno') {
                    $this->assertSame($evidencia['listado']['lecturas_totales'], $lecturas, 'La cardinalidad de la página no añade consultas de relaciones.');
                }
            } elseif ($nombre === 'fecha') {
                $this->assertSame(24, $r->json('meta.total'));
                $this->assertStringContainsString('bitacora_fecha_idx', json_encode($plan));
            } else {
                $this->assertSame(1, $r->json('meta.total'));
            }
        }
        fwrite(STDOUT, "\nBitacora: EXPLAIN local, 5000 eventos ficticios: ".json_encode($evidencia)."\n");
        $this->assertSame(5001, DB::table('bitacora')->count());
    }
}
