<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\RevisionResultado;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\laboratorio\ResultadoLaboratorioRepository;
use Clinica\shared\utils\ReferenciasBitacora;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class RevisionesLaboratorioTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_revisiones_lab_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->root = storage_path('framework/testing/'.$schema);
        config(['laboratorio.raiz' => $this->root, 'session.driver' => 'database', 'session.lottery' => [0, 100],
            'session.lifetime' => 4320, 'seguridad.correo.mailer' => 'array']);
        $this->beforeApplicationDestroyed(function () use ($schema, $anterior): void {
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $anterior]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            (new Filesystem)->deleteDirectory($this->root);
        });
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    }

    private function preparar(string $rol = 'Administrador', bool $propio = true): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        if ($rol === 'Médico') {
            DB::table('medicos')->where('id_medico', $r['doctors'][$propio ? 0 : 1])->update(['id_usuario' => $actor->getKey()]);
        }
        $cita = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $id = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $consulta,
            'estudio_solicitado' => 'Estudio ficticio', 'solicitada_en' => now()], 'id_solicitud');
        mkdir($this->root.'/documentos', 0700, true);
        $ruta = 'documentos/'.Str::uuid().'.png';
        // Documento/versiones son preparación de CU-25; revisión se ejecuta siempre por HTTP.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        file_put_contents($this->root.'/'.$ruta, $bytes);
        $doc = DB::table('documentos_laboratorio')->insertGetId(['ruta_privada' => $ruta, 'nombre_original' => 'ficticio.png',
            'tipo_mime' => 'image/png', 'tamano_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'cargado_por' => $actor->getKey(), 'cargado_en' => now()], 'id_documento');
        $version = (string) DB::table('versiones_resultado')->insertGetId(['id_solicitud' => $id, 'id_documento' => $doc,
            'numero_version' => 1, 'id_autor' => $actor->getKey(), 'registrada_en' => now()], 'id_version_resultado');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->update(['estado_interno' => 'En revisión', 'id_version_actual' => $version]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $uri = '/api/solicitudes-laboratorio/'.$id;
        $originales = ['estado_interno' => 'En revisión', 'id_version_actual' => $version, 'id_ultima_revision' => null, 'ultima_version' => 1];

        return compact('r', 'actor', 'cita', 'consulta', 'id', 'ruta', 'version', 'cookies', 'uri', 'originales');
    }

    private function revisar(array &$c, string $decision = 'Autorizar', array $cambios = []): TestResponse
    {
        $datos = ['originales' => $c['originales'], 'decision' => $decision, 'correspondencia_verificada' => true];
        if ($decision === 'Devolver') {
            $datos['motivo'] = 'MOTIVO-CLINICO-PRIVADO';
        }

        return $this->navegador($c['cookies'], 'POST', $c['uri'].'/revisiones', array_replace($datos, $cambios));
    }

    public static function revisores(): array
    {
        return [['Administrador'], ['Médico']];
    }

    #[DataProvider('revisores')]
    public function test_authorization_publishes_exact_version_with_actual_reviewer_and_does_not_change_care(string $rol): void
    {
        $c = $this->preparar($rol);
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['activo' => false]);
        $cita = DB::table('citas')->get()->toJson();
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.edicion.acciones.revisar', true);
        $res = $this->revisar($c)->assertCreated()->assertJsonPath('data.estado', 'Disponible')
            ->assertJsonPath('data.resultado.id_version_resultado', $c['version'])
            ->assertJsonPath('data.edicion.ultima_revision.revisada_por', (string) $c['actor']->getKey())
            ->assertJsonPath('data.edicion.ultima_revision.id_medico_responsable', (string) $c['r']['doctors'][0]);
        $revision = RevisionResultado::query()->sole();
        $this->assertSame((string) $c['actor']->getKey(), (string) $revision->revisadaPor->getKey());
        $this->assertArrayNotHasKey('revisada_por', $revision->toArray());
        $this->assertSame($cita, DB::table('citas')->get()->toJson());
        $this->assertDatabaseCount('versiones_resultado', 1);
        $this->assertStringNotContainsString('ruta_privada', $res->getContent());
        $audit = DB::table('bitacora')->where('tipo_evento', 'RESULTADO_LABORATORIO_AUTORIZADO')->sole();
        $this->assertSame((string) $c['actor']->getKey(), (string) $audit->id_usuario_responsable);
        $this->assertSame((string) $revision->getKey(), json_decode($audit->referencias)->id_revision);
        $this->assertSame((string) $revision->getKey(), ReferenciasBitacora::adicionales($audit->tipo_evento, json_decode($audit->referencias))->id_revision);
        $paciente = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['id_usuario' => $paciente->getKey()]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $paciente)->assertOk();
        $this->navegador($cookies, 'GET', $c['uri'])->assertOk()->assertJsonPath('data.estado', 'Disponible')
            ->assertJsonMissingPath('data.edicion')->assertJsonMissingPath('data.revisada_por');
        Mail::assertNothingSent();
    }

    #[DataProvider('revisores')]
    public function test_return_requires_new_version_before_any_new_review(string $rol): void
    {
        $c = $this->preparar($rol);
        $this->revisar($c, 'Devolver')->assertCreated()->assertJsonPath('data.estado', 'En revisión')->assertJsonPath('data.resultado', null)
            ->assertJsonPath('data.edicion.ultima_revision.motivo', 'MOTIVO-CLINICO-PRIVADO')->assertJsonPath('data.edicion.acciones.revisar', false);
        $this->assertStringNotContainsString('MOTIVO-CLINICO-PRIVADO', DB::table('bitacora')->get()->toJson());
        $this->revisar($c)->assertConflict()->assertJsonPath('error.code', 'LAB_EDICION_OBSOLETA');
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c)->assertConflict()->assertJsonPath('error.code', 'LAB_ESTADO_NO_PERMITIDO');
        $this->revisar($c, 'Devolver')->assertConflict();
        // Simula nueva versión de CU-25, sin sobrescribir revisión/bytes previos.
        $v = DB::table('versiones_resultado')->insertGetId(['id_solicitud' => $c['id'],
            'id_documento' => DB::table('versiones_resultado')->where('id_version_resultado', $c['version'])->value('id_documento'),
            'numero_version' => 2, 'id_version_origen' => $c['version'], 'id_autor' => $c['actor']->getKey(), 'registrada_en' => now()], 'id_version_resultado');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['id'])->update(['id_version_actual' => $v]);
        $this->revisar($c)->assertConflict();
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c)->assertCreated()->assertJsonPath('data.resultado.numero_version', 2);
        $this->assertDatabaseCount('revisiones_resultado', 2);
    }

    public static function invalidos(): array
    {
        return [[['decision' => 'Publicar']], [['correspondencia_verificada' => '1']], [['correspondencia_verificada' => false]],
            [['revisada_por' => 1]], [['id_medico' => 1]], [['id_objetivo' => 1]], [['motivo' => 'no permitido']],
            [['originales' => []]], [['originales' => ['estado_interno' => 'En revisión', 'id_version_actual' => '01', 'id_ultima_revision' => null, 'ultima_version' => 1]]]];
    }

    #[DataProvider('invalidos')]
    public function test_closed_json_contract(array $datos): void
    {
        $c = $this->preparar();
        $this->revisar($c, cambios: $datos)->assertUnprocessable();
        $this->assertDatabaseCount('revisiones_resultado', 0);
    }

    public static function motivos(): array
    {
        return [[null], [''], ['   '], [str_repeat('a', 2001)]];
    }

    #[DataProvider('motivos')]
    public function test_return_reason_is_mandatory_and_bounded(?string $motivo): void
    {
        $c = $this->preparar();
        $this->revisar($c, 'Devolver', ['motivo' => $motivo])->assertUnprocessable();
        $this->assertDatabaseCount('revisiones_resultado', 0);
    }

    public static function acceso(): array
    {
        return [['Médico', false, 404], ['Paciente', true, 403], ['Recepcionista', true, 403]];
    }

    #[DataProvider('acceso')]
    public function test_wrong_actor_cannot_review(string $rol, bool $propio, int $status): void
    {
        $c = $this->preparar($rol, $propio);
        $this->revisar($c)->assertStatus($status);
        $this->assertDatabaseCount('revisiones_resultado', 0);
    }

    public function test_missing_or_corrupted_bytes_never_publish(): void
    {
        $c = $this->preparar();
        file_put_contents($this->root.'/'.$c['ruta'], 'alterado');
        $this->revisar($c)->assertConflict()->assertJsonPath('error.code', 'LAB_ARCHIVO_NO_DISPONIBLE');
        unlink($this->root.'/'.$c['ruta']);
        $this->revisar($c)->assertConflict();
        $this->assertDatabaseCount('revisiones_resultado', 0);
        $this->assertDatabaseHas('solicitudes_laboratorio', ['estado_interno' => 'En revisión', 'id_revision_publicada' => null]);
    }

    public function test_audit_failure_rolls_back_review_and_publication_but_keeps_bytes(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('resultadoLaboratorioRevisado')->once()->andThrow(new RuntimeException('SECRETO-TECNICO'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $res = $this->revisar($c)->assertStatus(500);
        $this->assertStringNotContainsString('SECRETO-TECNICO', $res->getContent());
        $this->assertDatabaseCount('revisiones_resultado', 0);
        $this->assertDatabaseHas('solicitudes_laboratorio', ['estado_interno' => 'En revisión', 'id_revision_publicada' => null]);
        $this->assertFileExists($this->root.'/'.$c['ruta']);
    }

    public function test_revoked_session_during_review_rolls_back(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(ResultadoLaboratorioRepository::class)->makePartial();
        $repo->shouldReceive('agregarRevision')->once()->andReturnUsing(function (...$args) use ($c) {
            $r = (new ResultadoLaboratorioRepository)->agregarRevision(...$args);
            DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();

            return $r;
        });
        $this->app->instance(ResultadoLaboratorioRepository::class, $repo);
        $this->revisar($c)->assertUnauthorized();
        $this->assertDatabaseCount('revisiones_resultado', 0);
    }

    public function test_csrf_query_pending_and_published_guards(): void
    {
        $c = $this->preparar();
        $datos = ['originales' => $c['originales'], 'decision' => 'Autorizar', 'correspondencia_verificada' => true];
        $this->navegador($c['cookies'], 'POST', $c['uri'].'/revisiones', $datos, csrf: false)->assertStatus(419);
        $this->navegador($c['cookies'], 'POST', $c['uri'].'/revisiones?extra=1', $datos)->assertUnprocessable();
        $this->revisar($c)->assertCreated();
        $this->revisar($c)->assertConflict();
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c, 'Devolver')->assertConflict();
        $this->assertDatabaseCount('revisiones_resultado', 1);
    }

    public function test_additive_migration_preserves_legacy_review_and_immutable_triggers(): void
    {
        $c = $this->preparar();
        $migration = require base_path('src/data/database/migrations/2026_10_30_000037_add_revisada_por_to_revisiones_resultado.php');
        $migration->down();
        $id = DB::table('revisiones_resultado')->insertGetId(['id_version_resultado' => $c['version'],
            'id_medico' => $c['r']['doctors'][0], 'decision' => 'Autorizar', 'revisada_en' => now()], 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['id'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $id]);
        $before = (array) DB::table('revisiones_resultado')->sole();
        $migration->up();
        $after = (array) DB::table('revisiones_resultado')->sole();
        $this->assertNull($after['revisada_por']);
        unset($after['revisada_por']);
        $this->assertSame($before, $after);
        $fk = collect(Schema::getForeignKeys('revisiones_resultado'))->firstWhere('name', 'revisiones_resultado_revisor_fk');
        $this->assertSame(['revisada_por'], $fk['columns']);
        $this->assertSame('restrict', $fk['on_delete']);
        $this->assertSame('restrict', $fk['on_update']);
        try {
            DB::table('revisiones_resultado')->insert(['id_version_resultado' => $c['version'], 'id_medico' => $c['r']['doctors'][0],
                'revisada_por' => 9223372036854775807, 'decision' => 'Autorizar', 'revisada_en' => now()]);
            $this->fail('Debe exigir usuario revisor existente.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.estado', 'Disponible')
            ->assertJsonPath('data.edicion.ultima_revision.revisada_por', null);
        foreach (['UPDATE revisiones_resultado SET revisada_por = NULL', 'DELETE FROM revisiones_resultado', 'TRUNCATE revisiones_resultado CASCADE'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('La revisión debe ser inmutable.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('revisiones_resultado', 1);
    }

    public function test_real_return_reassociation_and_new_review_do_not_reuse_original_authorization(): void
    {
        $c = $this->preparar();
        $this->revisar($c, 'Devolver')->assertCreated();
        $destino = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $c['consulta'],
            'estudio_solicitado' => 'Destino ficticio', 'solicitada_en' => now()], 'id_solicitud');
        $destinoUri = '/api/solicitudes-laboratorio/'.$destino;
        $originales = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $originalesDestino = $this->navegador($c['cookies'], 'GET', $destinoUri)->json('data.edicion.originales');
        $this->navegador($c['cookies'], 'POST', $c['uri'].'/correcciones', ['originales' => $originales,
            'tipo' => 'reasociar', 'asociacion_verificada' => 1, 'asociacion_destino_verificada' => 1,
            'id_solicitud_destino' => $destino, 'originales_destino' => $originalesDestino, 'motivo_cambio' => 'Asociación corregida ficticia'])
            ->assertCreated()->assertJsonPath('data.destino.estado', 'En revisión');
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c)->assertConflict(); // Origen ya sin vigente.
        $c['uri'] = $destinoUri;
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $destinoUri)->json('data.edicion.originales');
        $this->revisar($c)->assertCreated()->assertJsonPath('data.estado', 'Disponible');
        $this->assertDatabaseCount('documentos_laboratorio', 1);
        $this->assertDatabaseCount('revisiones_resultado', 2);
        $this->assertDatabaseHas('solicitudes_laboratorio', ['id_solicitud' => $c['id'], 'id_version_actual' => null, 'estado_interno' => 'En revisión']);
    }

    public function test_pending_without_version_and_nonlatest_version_cannot_be_reviewed(): void
    {
        $c = $this->preparar();
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['id'])->update(['estado_interno' => 'Pendiente', 'id_version_actual' => null]);
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c)->assertConflict();
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['id'])->update(['estado_interno' => 'En revisión', 'id_version_actual' => $c['version']]);
        $v = DB::table('versiones_resultado')->where('id_version_resultado', $c['version'])->sole();
        DB::table('versiones_resultado')->insert(['id_solicitud' => $c['id'], 'id_documento' => $v->id_documento,
            'numero_version' => 2, 'id_autor' => $c['actor']->getKey(), 'registrada_en' => now()]);
        $c['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.originales');
        $this->revisar($c)->assertConflict();
        $this->assertDatabaseCount('revisiones_resultado', 0);
    }

    public function test_lost_response_after_commit_is_reconciled_by_get_without_second_review(): void
    {
        $c = $this->preparar();
        $fired = false;
        Event::listen(TransactionCommitted::class, function ($event) use (&$fired): void {
            if (! $fired && $event->connection->transactionLevel() === 0 && DB::table('revisiones_resultado')->count() === 1) {
                $fired = true;
                throw new RuntimeException('Respuesta perdida simulada.');
            }
        });
        $this->revisar($c)->assertStatus(500);
        $this->assertTrue($fired);
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.estado', 'Disponible');
        $this->revisar($c)->assertConflict();
        $this->assertDatabaseCount('revisiones_resultado', 1);
        $this->assertFileExists($this->root.'/'.$c['ruta']);
    }
}
