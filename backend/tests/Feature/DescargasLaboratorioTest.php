<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\shared\utils\ArchivoLaboratorio;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class DescargasLaboratorioTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $schema;

    private string $root;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'prueba_lab_http_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->schema.', public, pg_temp']);
        $this->root = storage_path('framework/testing/'.$this->schema);
        config(['laboratorio.raiz' => $this->root, 'session.driver' => 'database', 'session.lottery' => [0, 100],
            'session.lifetime' => 4320, 'seguridad.correo.mailer' => 'array']);
        $this->beforeApplicationDestroyed(function () use ($anterior): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $w) {
                proc_terminate($w['process']);
                foreach ($w['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($w['process']);
            }
            CarbonImmutable::setTestNow();
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $anterior]);
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            (new Filesystem)->deleteDirectory($this->root);
        });
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$this->schema.'", public, pg_temp');
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    }

    private function preparar(bool $publicar = true, array $metadatos = []): array
    {
        $r = $this->citaResources();
        $actores = $cookies = [];
        foreach (['Administrador', 'Médico', 'Paciente', 'Recepcionista'] as $rol) {
            $actores[$rol] = $this->usuarioAuth($rol, vincular: false);
            if ($rol === 'Médico') {
                DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actores[$rol]->getKey()]);
            } elseif ($rol === 'Paciente') {
                DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['id_usuario' => $actores[$rol]->getKey()]);
            }
            $cookies[$rol] = $this->csrfAuth();
            $this->loginAuth($cookies[$rol], $actores[$rol])->assertOk();
        }
        $cita = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $id = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $consulta,
            'estudio_solicitado' => 'Estudio ficticio privado', 'solicitada_en' => now()], 'id_solicitud');
        mkdir($this->root.'/documentos', 0700, true);
        $ruta = 'documentos/'.Str::uuid().'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        file_put_contents($this->root.'/'.$ruta, $bytes);
        // CU-25 es fixture declarada; publicación y descarga se ejercitan por HTTP.
        $doc = DB::table('documentos_laboratorio')->insertGetId(array_replace(['ruta_privada' => $ruta,
            'nombre_original' => 'NOMBRE-CLINICO-PRIVADO.png', 'tipo_mime' => 'image/png', 'tamano_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'cargado_por' => $actores['Administrador']->getKey(), 'cargado_en' => now()], $metadatos), 'id_documento');
        $version = (string) DB::table('versiones_resultado')->insertGetId(['id_solicitud' => $id, 'id_documento' => $doc,
            'numero_version' => 1, 'id_autor' => $actores['Administrador']->getKey(), 'registrada_en' => now()], 'id_version_resultado');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->update(['estado_interno' => 'En revisión', 'id_version_actual' => $version]);
        $uri = '/api/solicitudes-laboratorio/'.$id;
        $originales = ['estado_interno' => 'En revisión', 'id_version_actual' => $version, 'id_ultima_revision' => null, 'ultima_version' => 1];
        if ($publicar) {
            $this->navegador($cookies['Administrador'], 'POST', $uri.'/revisiones', ['originales' => $originales,
                'decision' => 'Autorizar', 'correspondencia_verificada' => true])->assertCreated();
        }
        $archivoUri = $uri.'/archivo?id_version_resultado='.$version;

        return compact('r', 'actores', 'cookies', 'cita', 'consulta', 'id', 'doc', 'version', 'ruta', 'bytes', 'uri', 'archivoUri', 'originales');
    }

    public static function lectores(): array
    {
        return [['Administrador', true], ['Médico', true], ['Paciente', true], ['Administrador', false], ['Médico', false]];
    }

    #[DataProvider('lectores')]
    public function test_get_and_head_deliver_only_exact_authorized_bytes_without_public_names_or_cache(string $rol, bool $publicar): void
    {
        $c = $this->preparar($publicar);
        DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['activo' => false]);
        $res = $this->navegador($c['cookies'][$rol], 'GET', $c['archivoUri'])->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Length', (string) strlen($c['bytes']))
            ->assertHeader('Content-Disposition', 'attachment; filename="resultado-'.$c['version'].'.png"')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Accept-Ranges', 'none')->assertStreamedContent($c['bytes']);
        $this->assertFalse($res->headers->has('ETag'));
        $this->assertFalse($res->headers->has('Last-Modified'));
        $this->assertStringNotContainsString('NOMBRE-CLINICO', (string) $res->headers);
        $this->navegador($c['cookies'][$rol], 'HEAD', $c['archivoUri'])->assertOk()
            ->assertHeader('Content-Length', (string) strlen($c['bytes']))->assertContent('');
        Mail::assertNothingSent();
    }

    public function test_range_and_conditional_headers_never_skip_authorization_or_return_partial_cached_bytes(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'], headers: [
            'Range' => 'bytes=0-1', 'If-None-Match' => '*', 'If-Modified-Since' => 'Wed, 01 Jan 2031 00:00:00 GMT'])
            ->assertOk()->assertHeader('Accept-Ranges', 'none')->assertStreamedContent($c['bytes']);
        DB::table('sessions')->where('user_id', $c['actores']['Paciente']->getKey())->delete();
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'], headers: ['Range' => 'bytes=0-1', 'If-None-Match' => '*'])->assertUnauthorized();
    }

    public function test_unpublished_returned_and_reassociated_source_never_serve_bytes_to_patient(): void
    {
        $c = $this->preparar(false);
        foreach (['GET', 'HEAD'] as $metodo) {
            $this->navegador($c['cookies']['Paciente'], $metodo, $c['archivoUri'])->assertNotFound();
        }
        $this->navegador($c['cookies']['Administrador'], 'POST', $c['uri'].'/revisiones', ['originales' => $c['originales'],
            'decision' => 'Devolver', 'correspondencia_verificada' => true, 'motivo' => 'Motivo ficticio privado'])->assertCreated();
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertNotFound();
        $this->navegador($c['cookies']['Médico'], 'GET', $c['archivoUri'])->assertOk()->assertStreamedContent($c['bytes']);
    }

    public function test_foreign_patient_doctor_reception_and_anonymous_are_rejected_without_binary_data(): void
    {
        $c = $this->preparar();
        foreach (['Paciente', 'Médico'] as $rol) {
            $ajeno = $this->usuarioAuth($rol);
            $cookies = $this->csrfAuth();
            $this->loginAuth($cookies, $ajeno)->assertOk();
            foreach (['GET', 'HEAD'] as $method) {
                $this->navegador($cookies, $method, $c['archivoUri'])->assertNotFound();
            }
        }
        $this->navegador($c['cookies']['Recepcionista'], 'GET', $c['archivoUri'])->assertForbidden();
        $cookies = [];
        $this->navegador($cookies, 'GET', $c['archivoUri'])->assertUnauthorized();
    }

    public static function credencialesInvalidas(): array
    {
        return [['estado_cuenta', 'Desactivada'], ['correo_verificado_en', null], ['id_rol', 'Recepcionista'], ['password_hash', 'hash-distinto-ficticio']];
    }

    #[DataProvider('credencialesInvalidas')]
    public function test_each_download_revalidates_account_and_session_fingerprint(string $campo, mixed $valor): void
    {
        $c = $this->preparar();
        if ($campo === 'id_rol') {
            $valor = $c['actores'][$valor]->id_rol;
        }
        DB::table('usuarios')->where('id_usuario', $c['actores']['Paciente']->getKey())->update([
            $campo => $valor, ...($campo === 'correo_verificado_en' ? ['estado_cuenta' => 'Pendiente'] : [])]);
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertUnauthorized();
    }

    public static function consultasInvalidas(): array
    {
        return [[''], ['?id_version_resultado=01'], ['?id_version_resultado=0'], ['?id_version_resultado=9223372036854775808'],
            ['?id_version_resultado=1&ruta_privada=secreto'], ['?id_version_resultado=1&id_objetivo=2']];
    }

    #[DataProvider('consultasInvalidas')]
    public function test_query_contract_is_closed_and_ids_are_canonical(string $query): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies']['Administrador'], 'GET', $c['uri'].'/archivo'.$query)->assertUnprocessable();
    }

    public function test_old_or_unknown_version_and_get_body_are_not_accepted(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['uri'].'/archivo?id_version_resultado=9223372036854775807')->assertConflict();
        $this->navegador($c['cookies']['Administrador'], 'GET', $c['archivoUri'], ['estado' => 'Disponible'])->assertUnprocessable();
        $this->navegador($c['cookies']['Paciente'], 'GET', '/api/solicitudes-laboratorio/9223372036854775807/archivo?id_version_resultado='.$c['version'])->assertNotFound();
    }

    public function test_corrupt_or_missing_file_returns_safe_json_before_any_download_bytes(): void
    {
        $c = $this->preparar();
        file_put_contents($this->root.'/'.$c['ruta'], str_repeat('x', strlen($c['bytes'])));
        $res = $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertConflict()->assertJsonPath('error.code', 'LAB_ARCHIVO_NO_DISPONIBLE');
        $this->assertStringNotContainsString('documentos/', $res->getContent());
        $this->assertStringNotContainsString('sha256', $res->getContent());
        unlink($this->root.'/'.$c['ruta']);
        $this->navegador($c['cookies']['Paciente'], 'HEAD', $c['archivoUri'])->assertConflict()->assertContent('');
    }

    public static function metadatosInvalidos(): array
    {
        return [[['ruta_privada' => '../../documento-ajeno.png']], [['tipo_mime' => 'text/html']],
            [['tipo_mime' => 'application/pdf']], [['tamano_bytes' => 10485761]]];
    }

    #[DataProvider('metadatosInvalidos')]
    public function test_unsafe_stored_paths_and_mime_never_become_public_or_inline(array $metadatos): void
    {
        $c = $this->preparar(false, $metadatos);
        $this->navegador($c['cookies']['Administrador'], 'GET', $c['archivoUri'])->assertConflict()->assertJsonPath('error.code', 'LAB_ARCHIVO_NO_DISPONIBLE');
    }

    public function test_final_session_revalidation_closes_already_opened_descriptor_on_rejection(): void
    {
        $c = $this->preparar();
        $flujo = null;
        $archivos = Mockery::mock(ArchivoLaboratorio::class)->makePartial();
        $archivos->shouldReceive('abrir')->once()->andReturnUsing(function ($ruta, $meta) use (&$flujo, $c) {
            $abierto = (new ArchivoLaboratorio)->abrir($ruta, $meta);
            $flujo = $abierto['flujo'];
            DB::table('sessions')->where('user_id', $c['actores']['Paciente']->getKey())->delete();

            return $abierto;
        });
        $this->app->instance(ArchivoLaboratorio::class, $archivos);
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertUnauthorized();
        $this->assertFalse(is_resource($flujo));
    }

    public function test_successful_stream_and_head_release_descriptor_without_holding_sql_or_claiming_download_audit(): void
    {
        $c = $this->preparar();
        $flujo = null;
        $archivos = Mockery::mock(ArchivoLaboratorio::class)->makePartial();
        $archivos->shouldReceive('abrir')->twice()->andReturnUsing(function ($ruta, $meta) use (&$flujo) {
            $abierto = (new ArchivoLaboratorio)->abrir($ruta, $meta);
            $flujo = $abierto['flujo'];

            return $abierto;
        });
        $this->app->instance(ArchivoLaboratorio::class, $archivos);
        $antes = DB::table('bitacora')->count();
        $res = $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertOk();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(is_resource($flujo));
        $res->assertStreamedContent($c['bytes']);
        $this->assertFalse(is_resource($flujo));
        $this->navegador($c['cookies']['Paciente'], 'HEAD', $c['archivoUri'])->assertOk()->assertContent('');
        $this->assertFalse(is_resource($flujo));
        $this->assertSame($antes, DB::table('bitacora')->count());
    }

    public function test_legacy_unknown_reviewer_is_not_invented_and_does_not_alone_revoke_valid_publication(): void
    {
        $c = $this->preparar(false);
        $revision = DB::table('revisiones_resultado')->insertGetId(['id_version_resultado' => $c['version'],
            'id_medico' => $c['r']['doctors'][0], 'decision' => 'Autorizar', 'revisada_en' => now()], 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['id'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $revision]);
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertOk()->assertStreamedContent($c['bytes']);
        $this->assertNull(DB::table('revisiones_resultado')->sole()->revisada_por);
    }

    public function test_incoherent_published_pointer_does_not_deliver_bytes_even_when_old_metadata_says_available(): void
    {
        $c = $this->preparar();
        // SQL exclusivo de fixture: las APIs no permiten revisar de nuevo una versión publicada.
        DB::table('revisiones_resultado')->insert(['id_version_resultado' => $c['version'], 'id_medico' => $c['r']['doctors'][0],
            'decision' => 'Devolver', 'motivo' => 'Incoherencia legada ficticia', 'revisada_en' => CarbonImmutable::now('UTC')->addSecond()]);
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertNotFound();
        $this->navegador($c['cookies']['Administrador'], 'GET', $c['archivoUri'])->assertConflict();
    }

    public function test_cross_patient_reassociation_never_grants_origin_actor_access_to_destination_bytes(): void
    {
        $c = $this->preparar(false);
        $this->navegador($c['cookies']['Administrador'], 'POST', $c['uri'].'/revisiones', ['originales' => $c['originales'],
            'decision' => 'Devolver', 'correspondencia_verificada' => true, 'motivo' => 'Asociación equivocada ficticia'])->assertCreated();
        $paciente = $this->usuarioAuth('Paciente');
        $medico = $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][1])->update(['id_usuario' => $medico->getKey()]);
        $r = $c['r'];
        $r['patient'] = $paciente->paciente->getKey();
        $cita = DB::table('citas')->insertGetId(array_replace($this->citaRow($r), ['id_medico' => $r['doctors'][1], 'id_consultorio' => $r['rooms'][1]]), 'id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $destino = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $consulta,
            'estudio_solicitado' => 'Destino ficticio de otra ficha', 'solicitada_en' => now()], 'id_solicitud');
        $uri = '/api/solicitudes-laboratorio/'.$destino;
        $originales = $this->navegador($c['cookies']['Administrador'], 'GET', $c['uri'])->json('data.edicion.originales');
        $originalesDestino = $this->navegador($c['cookies']['Administrador'], 'GET', $uri)->json('data.edicion.originales');
        $this->navegador($c['cookies']['Administrador'], 'POST', $c['uri'].'/correcciones', ['originales' => $originales,
            'originales_destino' => $originalesDestino, 'tipo' => 'reasociar', 'id_solicitud_destino' => $destino,
            'asociacion_verificada' => 1, 'asociacion_destino_verificada' => 1, 'motivo_cambio' => 'MOTIVO-ORIGEN-PRIVADO'])->assertCreated();
        $version = DB::table('solicitudes_laboratorio')->where('id_solicitud', $destino)->value('id_version_actual');
        $archivo = $uri.'/archivo?id_version_resultado='.$version;
        foreach (['Paciente', 'Médico'] as $rol) {
            $this->navegador($c['cookies'][$rol], 'GET', $archivo)->assertNotFound();
        }
        $this->navegador($c['cookies']['Médico'], 'GET', $c['archivoUri'])->assertConflict();
        $cookiesMedico = $this->csrfAuth();
        $this->loginAuth($cookiesMedico, $medico)->assertOk();
        $this->navegador($cookiesMedico, 'GET', $archivo)->assertOk()->assertStreamedContent($c['bytes']);
        $cookiesPaciente = $this->csrfAuth();
        $this->loginAuth($cookiesPaciente, $paciente)->assertOk();
        $this->navegador($cookiesPaciente, 'GET', $archivo)->assertNotFound();
        $originales = $this->navegador($cookiesMedico, 'GET', $uri)->json('data.edicion.originales');
        $this->navegador($cookiesMedico, 'POST', $uri.'/revisiones', ['originales' => $originales,
            'decision' => 'Autorizar', 'correspondencia_verificada' => true])->assertCreated();
        $this->navegador($cookiesPaciente, 'GET', $archivo)->assertOk()->assertStreamedContent($c['bytes']);
        $this->assertDatabaseCount('documentos_laboratorio', 1);
    }

    private function signal(int $worker, string $line): void
    {
        fwrite($this->workers[$worker]['pipes'][0], $line."\n");
        fflush($this->workers[$worker]['pipes'][0]);
    }

    private function line(int $worker): string
    {
        $deadline = microtime(true) + 15;
        $line = '';
        do {
            $chunk = fgets($this->workers[$worker]['pipes'][1]);
            if ($chunk !== false) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    return trim($line);
                }
            }
            if (stream_get_contents($this->workers[$worker]['pipes'][2]) !== '') {
                throw new RuntimeException('Falló worker aislado de laboratorio.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Venció barrera de laboratorio.');
    }

    private function worker(array $cookies, string $metodo, string $ruta, array $payload = [], bool $pausa = false): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/laboratorio-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar worker.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $i = count($this->workers);
        $this->workers[$i] = compact('process', 'pipes');
        $this->signal($i, json_encode(['schema' => $this->schema, 'cookies' => $cookies, 'metodo' => $metodo,
            'ruta' => $ruta, 'payload' => $payload, 'pausar_evento' => $pausa]));
        $ready = json_decode($this->line($i), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ready', $ready);
        $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $ready['ready']);
        foreach ($this->workers as $w) {
            if (isset($w['pid'])) {
                $this->assertNotSame($w['pid'], $ready['ready']);
            }
        }
        $this->workers[$i]['pid'] = $ready['ready'];

        return $i;
    }

    private function blocked(int $worker): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $r = DB::selectOne('SELECT wait_event_type, cardinality(pg_blocking_pids(pid)) AS blockers FROM pg_stat_activity WHERE pid = ?', [$this->workers[$worker]['pid']]);
            if ($r?->wait_event_type === 'Lock' && $r->blockers > 0) {
                $this->assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('No se observó lock PostgreSQL con bloqueador.');
    }

    public static function commits(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('commits')]
    public function test_concurrent_reviews_serialize_and_do_not_publish_twice(bool $commit): void
    {
        $c = $this->preparar(false);
        $payload = ['originales' => $c['originales'], 'decision' => 'Autorizar', 'correspondencia_verificada' => true];
        $a = $this->worker($c['cookies']['Administrador'], 'POST', $c['uri'].'/revisiones', $payload, true);
        $this->signal($a, 'INICIAR');
        $this->assertSame('CAMBIO_PENDIENTE', $this->line($a));
        $b = $this->worker($c['cookies']['Médico'], 'POST', $c['uri'].'/revisiones', $payload);
        $this->signal($b, 'INICIAR');
        $this->blocked($b);
        $this->assertDatabaseCount('revisiones_resultado', 0);
        $this->signal($a, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($a), true);
        $rb = json_decode($this->line($b), true);
        $this->assertSame($commit ? 201 : 500, $ra['status']);
        $this->assertSame($commit ? 409 : 201, $rb['status']);
        $this->assertDatabaseCount('revisiones_resultado', 1);
        $this->assertSame((string) $c['actores'][$commit ? 'Administrador' : 'Médico']->getKey(),
            (string) DB::table('revisiones_resultado')->sole()->revisada_por);
    }

    #[DataProvider('commits')]
    public function test_patient_download_waits_for_publication_commit_or_rollback(bool $commit): void
    {
        $c = $this->preparar(false);
        $a = $this->worker($c['cookies']['Administrador'], 'POST', $c['uri'].'/revisiones',
            ['originales' => $c['originales'], 'decision' => 'Autorizar', 'correspondencia_verificada' => true], true);
        $this->signal($a, 'INICIAR');
        $this->assertSame('CAMBIO_PENDIENTE', $this->line($a));
        $b = $this->worker($c['cookies']['Paciente'], 'GET', $c['archivoUri']);
        $this->signal($b, 'INICIAR');
        $this->blocked($b);
        $clinica = $this->worker($c['cookies']['Paciente'], 'GET', '/api/citas/'.$c['cita'].'/consulta');
        $this->signal($clinica, 'INICIAR');
        $this->blocked($clinica);
        $this->signal($a, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($a), true);
        $rb = json_decode($this->line($b), true);
        $rc = json_decode($this->line($clinica), true);
        $this->assertSame($commit ? 201 : 500, $ra['status']);
        $this->assertSame($commit ? 200 : 404, $rb['status']);
        if ($commit) {
            $this->assertSame(hash('sha256', $c['bytes']), $rb['sha256']);
            $this->assertSame(strlen($c['bytes']), $rb['bytes']);
        } else {
            $this->assertArrayNotHasKey('bytes', $rb);
        }
        $this->assertSame(200, $rc['status']);
        $this->assertSame($commit ? 'Disponible' : 'Pendiente', $rc['body']['data']['estudios'][0]['estado']);
        $this->assertSame($commit, $rc['body']['data']['estudios'][0]['resultado'] !== null);
    }

    #[DataProvider('commits')]
    public function test_download_revalidates_origin_after_concurrent_reassociation(bool $commit): void
    {
        $c = $this->preparar(false);
        $this->navegador($c['cookies']['Administrador'], 'POST', $c['uri'].'/revisiones', ['originales' => $c['originales'],
            'decision' => 'Devolver', 'correspondencia_verificada' => true, 'motivo' => 'Corrección ficticia'])->assertCreated();
        $destino = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $c['consulta'],
            'estudio_solicitado' => 'Destino ficticio', 'solicitada_en' => now()], 'id_solicitud');
        $originales = $this->navegador($c['cookies']['Administrador'], 'GET', $c['uri'])->json('data.edicion.originales');
        $originalesDestino = $this->navegador($c['cookies']['Administrador'], 'GET', '/api/solicitudes-laboratorio/'.$destino)->json('data.edicion.originales');
        $payload = ['originales' => $originales, 'originales_destino' => $originalesDestino, 'tipo' => 'reasociar',
            'id_solicitud_destino' => $destino, 'asociacion_verificada' => 1, 'asociacion_destino_verificada' => 1, 'motivo_cambio' => 'Corrección ficticia'];
        $a = $this->worker($c['cookies']['Administrador'], 'POST', $c['uri'].'/correcciones', $payload, true);
        $this->signal($a, 'INICIAR');
        $this->assertSame('CAMBIO_PENDIENTE', $this->line($a));
        $b = $this->worker($c['cookies']['Médico'], 'GET', $c['archivoUri']);
        $this->signal($b, 'INICIAR');
        $this->blocked($b);
        $this->signal($a, $commit ? 'CONFIRMAR' : 'REVERTIR');
        $ra = json_decode($this->line($a), true);
        $rb = json_decode($this->line($b), true);
        $this->assertSame($commit ? 201 : 500, $ra['status']);
        $this->assertSame($commit ? 409 : 200, $rb['status']);
        if (! $commit) {
            $this->assertSame(hash('sha256', $c['bytes']), $rb['sha256']);
        }
        $this->assertFileExists($this->root.'/'.$c['ruta']);
    }

    public function test_concurrent_account_revocation_prevents_waiting_download_and_old_cookie_after_reactivation(): void
    {
        $c = $this->preparar();
        $w = $this->worker($c['cookies']['Paciente'], 'GET', $c['archivoUri']);
        DB::beginTransaction();
        DB::table('usuarios')->where('id_usuario', $c['actores']['Paciente']->getKey())->update(['estado_cuenta' => 'Desactivada']);
        DB::table('sessions')->where('user_id', $c['actores']['Paciente']->getKey())->delete();
        $this->signal($w, 'INICIAR');
        $this->blocked($w);
        DB::commit();
        $res = json_decode($this->line($w), true);
        $this->assertSame(401, $res['status']);
        $this->assertArrayNotHasKey('bytes', $res);
        DB::table('usuarios')->where('id_usuario', $c['actores']['Paciente']->getKey())->update(['estado_cuenta' => 'Activa']);
        $this->navegador($c['cookies']['Paciente'], 'GET', $c['archivoUri'])->assertUnauthorized();
    }
}
