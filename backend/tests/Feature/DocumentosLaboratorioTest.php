<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\laboratorio\DocumentoLaboratorioRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ArchivoLaboratorio;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class DocumentosLaboratorioTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_documentos_lab_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->root = storage_path('framework/testing/'.$schema);
        config(['laboratorio.raiz' => $this->root]);
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
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'session.lifetime' => 4320, 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    }

    private function preparar(string $rol = 'Administrador'): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        if ($rol === 'Médico') {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actor->getKey()]);
        }
        if ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }
        $cita = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $solicitud = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $consulta,
            'estudio_solicitado' => 'ESTUDIO-FICTICIO-PRIVADO', 'solicitada_en' => now(), 'creada_por' => $actor->getKey()], 'id_solicitud');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $uri = '/api/solicitudes-laboratorio/'.$solicitud;

        return compact('r', 'actor', 'cita', 'consulta', 'solicitud', 'cookies', 'uri');
    }

    private function pdf(): string
    {
        $objetos = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>'];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $i => $objeto) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function bytes(string $tipo): string
    {
        if ($tipo === 'pdf') {
            return $this->pdf();
        }
        $imagen = imagecreatetruecolor(20, 20);
        ob_start();
        $tipo === 'png' ? imagepng($imagen) : imagejpeg($imagen);
        $bytes = ob_get_clean();
        $imagen = null;

        return $bytes;
    }

    private function contexto(array &$c): array
    {
        return $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->json('data.edicion.originales');
    }

    private function multipart(array &$cookies, string $uri, array $datos, ?UploadedFile $archivo, bool $csrf = true): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetScopedInstances();
        $headers = ['Accept' => 'application/json', 'Origin' => 'http://localhost:5173', 'Content-Type' => 'multipart/form-data'];
        if ($csrf && isset($cookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $cookies['XSRF-TOKEN'];
        }
        array_walk_recursive($datos, static function (&$v): void {
            $v = $v === null ? '' : $v;
        });
        $server = $this->transformHeadersToServerVars($headers);
        $server['REMOTE_ADDR'] = '192.0.2.10';
        $res = $this->call('POST', $uri, $datos, $cookies, $archivo === null ? [] : ['archivo' => $archivo], $server);
        foreach ($res->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $res;
    }

    private function cargar(array &$c, string $tipo = 'png'): TestResponse
    {
        $data = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'];

        return $this->multipart($c['cookies'], $c['uri'].'/documentos', $data,
            UploadedFile::fake()->createWithContent('resultado.'.$tipo, $this->bytes($tipo)));
    }

    private function devolver(array $c): void
    {
        $version = DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['solicitud'])->value('id_version_actual');
        DB::table('revisiones_resultado')->insert(['id_version_resultado' => $version, 'id_medico' => $c['r']['doctors'][0],
            'decision' => 'Devolver', 'motivo' => 'MOTIVO-DEVOLUCION-PRIVADO', 'revisada_en' => now()]);
    }

    private function archivos(): array
    {
        return glob($this->root.'/documentos/*') ?: [];
    }

    private function snapshot(): array
    {
        $s = [];
        foreach (['citas', 'consultas_medicas', 'solicitudes_laboratorio', 'versiones_resultado', 'documentos_laboratorio', 'revisiones_resultado', 'bitacora'] as $t) {
            $s[$t] = DB::table($t)->get()->toJson();
        }

        return $s;
    }

    public static function formatos(): array
    {
        return [['png'], ['jpg'], ['pdf']];
    }

    #[DataProvider('formatos')]
    public function test_real_upload_is_private_immutable_versioned_and_not_published(string $tipo): void
    {
        $c = $this->preparar();
        $antes = DB::table('citas')->get()->toJson();
        $res = $this->cargar($c, $tipo)->assertCreated()->assertJsonPath('data.estado', 'En revisión')->assertJsonPath('data.resultado', null)
            ->assertJsonPath('data.edicion.originales.ultima_version', 1)->assertJsonPath('data.edicion.acciones.corregir', false);
        $doc = DB::table('documentos_laboratorio')->sole();
        $this->assertSame(hash('sha256', $this->bytes($tipo)), $doc->sha256);
        $this->assertSame($this->bytes($tipo), file_get_contents($this->root.'/'.$doc->ruta_privada));
        $this->assertSame((string) $c['actor']->getKey(), (string) $doc->cargado_por);
        $this->assertSame($antes, DB::table('citas')->get()->toJson());
        $this->assertDatabaseCount('versiones_resultado', 1);
        $this->assertDatabaseCount('revisiones_resultado', 0);
        $this->assertCount(1, $this->archivos());
        foreach (['ruta_privada', 'sha256', 'resultado.'.$tipo, 'id_documento'] as $privado) {
            $this->assertStringNotContainsString($privado, $res->getContent());
            $this->assertStringNotContainsString($privado, DB::table('bitacora')->get()->toJson());
        }
        $this->navegador($c['cookies'], 'GET', $c['uri'].'/versiones')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.numero_version', 1);
        $this->navegador($c['cookies'], 'HEAD', $c['uri'].'/versiones')->assertOk()->assertContent('');
        $this->navegador($c['cookies'], 'GET', $c['uri'].'/archivo?id_version_resultado='.$res->json('data.edicion.originales.id_version_actual'))
            ->assertOk()->assertStreamedContent($this->bytes($tipo));
        Mail::assertNothingSent();
    }

    public function test_substitution_requires_return_and_keeps_original_bytes_and_history(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $doc = DB::table('documentos_laboratorio')->sole();
        $old = $this->contexto($c);
        $datos = ['originales' => $old, 'tipo' => 'sustituir', 'asociacion_verificada' => '1', 'motivo_cambio' => 'MOTIVO-CORRECCION-PRIVADO'];
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, UploadedFile::fake()->createWithContent('nuevo.jpg', $this->bytes('jpg')))->assertConflict();
        $this->assertCount(1, $this->archivos());
        $this->devolver($c);
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, UploadedFile::fake()->createWithContent('nuevo.jpg', $this->bytes('jpg')))->assertConflict()->assertJsonPath('error.code', 'LAB_EDICION_OBSOLETA');
        $datos['originales'] = $this->contexto($c);
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, UploadedFile::fake()->createWithContent('nuevo.jpg', $this->bytes('jpg')))->assertCreated()
            ->assertJsonPath('data.edicion.originales.ultima_version', 2)->assertJsonPath('data.edicion.originales.id_ultima_revision', null);
        $this->assertSame($this->bytes('png'), file_get_contents($this->root.'/'.$doc->ruta_privada));
        $this->assertCount(2, $this->archivos());
        $this->assertDatabaseCount('revisiones_resultado', 1);
        $this->assertDatabaseCount('versiones_resultado', 2);
        $this->navegador($c['cookies'], 'GET', $c['uri'].'/versiones?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.numero_version', 1);
        $this->assertStringNotContainsString('MOTIVO-CORRECCION-PRIVADO', DB::table('bitacora')->get()->toJson());
    }

    public function test_reassociation_between_patients_preserves_origin_and_requires_new_review_and_new_number_on_reload(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $this->devolver($c);
        $destino = $this->preparar();
        // Se conserva la sesión original del Administrador; destino es otra ficha/consulta real de fixture.
        $destino['cookies'] = $c['cookies'];
        $datos = ['originales' => $this->contexto($c), 'tipo' => 'reasociar', 'asociacion_verificada' => '1',
            'asociacion_destino_verificada' => '1', 'id_solicitud_destino' => $destino['solicitud'],
            'originales_destino' => $this->contexto($destino), 'motivo_cambio' => 'Asociación ficticia corregida'];
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertCreated()
            ->assertJsonPath('data.edicion.originales.id_version_actual', null)->assertJsonPath('data.destino.id_solicitud', $destino['solicitud'])
            ->assertJsonPath('data.destino.estado', 'En revisión');
        $this->assertCount(1, $this->archivos());
        $this->assertDatabaseCount('documentos_laboratorio', 1);
        $this->assertDatabaseCount('versiones_resultado', 2);
        $this->assertTrue($this->navegador($c['cookies'], 'GET', $c['uri'])->json('data.edicion.acciones.cargar'));
        $reload = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1', 'motivo_cambio' => 'Nuevo documento correcto'];
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $reload, UploadedFile::fake()->createWithContent('nuevo.png', $this->bytes('png')))->assertCreated()
            ->assertJsonPath('data.edicion.originales.ultima_version', 2);
    }

    public static function archivosInvalidos(): array
    {
        return [['falso.pdf', '%PDF-1.4 falso sin estructura'], ['falso.png', "\x89PNG\r\n\x1a\n basura"],
            ['imagen.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'], ['vacio.pdf', ''], ['script.png', '<?php echo 1;'],
            ['resultado.zip', 'PK0000'], ['documento.docx', 'Texto falso']];
    }

    #[DataProvider('archivosInvalidos')]
    public function test_invalid_content_never_creates_bytes_or_metadata(string $nombre, string $contenido): void
    {
        $c = $this->preparar();
        $antes = $this->snapshot();
        $this->multipart($c['cookies'], $c['uri'].'/documentos', ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'],
            UploadedFile::fake()->createWithContent($nombre, $contenido))->assertUnprocessable();
        $this->assertSame($antes, $this->snapshot());
        $this->assertCount(0, $this->archivos());
    }

    public function test_limits_missing_tools_and_wrong_extension_reject_safely(): void
    {
        $c = $this->preparar();
        $datos = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'];
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('imagen.pdf', $this->bytes('png')))->assertUnprocessable();
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->create('grande.pdf', 10241))->assertUnprocessable();
        config(['laboratorio.qpdf' => '/ejecutable/inexistente']);
        $res = $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('resultado.pdf', $this->pdf()))->assertStatus(503);
        $this->assertStringNotContainsString('/ejecutable', $res->getContent());
        $this->assertCount(0, $this->archivos());
    }

    public function test_sql_audit_failure_compensates_only_new_file(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('documentoLaboratorioRegistrado')->once()->andThrow(new RuntimeException('FALLO-TECNICO-PRIVADO'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $antes = $this->snapshot();
        $res = $this->cargar($c)->assertStatus(500);
        $this->assertStringNotContainsString('FALLO-TECNICO', $res->getContent());
        $this->assertSame($antes, $this->snapshot());
        $this->assertCount(0, $this->archivos());
    }

    public static function roles(): array
    {
        return [['Médico'], ['Paciente'], ['Recepcionista']];
    }

    #[DataProvider('roles')]
    public function test_nonadministrative_roles_cannot_upload_even_with_real_file(string $rol): void
    {
        $c = $this->preparar($rol);
        $data = ['originales' => ['estado_interno' => 'Pendiente', 'id_version_actual' => null, 'id_ultima_revision' => null, 'ultima_version' => 0], 'asociacion_verificada' => '1'];
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $data, UploadedFile::fake()->createWithContent('resultado.png', $this->bytes('png')))->assertForbidden();
        $this->assertDatabaseCount('documentos_laboratorio', 0);
        $this->assertCount(0, $this->archivos());
        if ($rol === 'Paciente' || $rol === 'Recepcionista') {
            $this->navegador($c['cookies'], 'GET', $c['uri'].'/versiones')->assertForbidden();
        }
    }

    public function test_csrf_and_closed_multipart_fields_and_obsolete_upload_do_not_leak_files(): void
    {
        $c = $this->preparar();
        $datos = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'];
        $file = fn () => UploadedFile::fake()->createWithContent('resultado.png', $this->bytes('png'));
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, $file(), false)->assertStatus(419);
        foreach (['id_autor', 'ruta_privada', 'sha256', 'id_objetivo', '_method'] as $campo) {
            $this->multipart($c['cookies'], $c['uri'].'/documentos', [...$datos, $campo => '1'], $file())->assertStatus($campo === '_method' ? 400 : 422);
        }
        $this->cargar($c)->assertCreated();
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, $file())->assertConflict();
        $this->assertCount(1, $this->archivos());
    }

    public static function camposInvalidos(): array
    {
        return [[['asociacion_verificada' => '0']], [['asociacion_verificada' => true]], [['originales' => []]],
            [['originales' => ['estado_interno' => 'Programada', 'id_version_actual' => null, 'id_ultima_revision' => null, 'ultima_version' => 0]]],
            [['originales' => ['estado_interno' => 'Pendiente', 'id_version_actual' => '01', 'id_ultima_revision' => null, 'ultima_version' => 0]]],
            [['originales' => ['estado_interno' => 'Pendiente', 'id_version_actual' => null, 'id_ultima_revision' => null, 'ultima_version' => -1]]],
            [['originales' => ['estado_interno' => 'Pendiente', 'id_version_actual' => null, 'id_ultima_revision' => null, 'ultima_version' => 0, 'extra' => 1]]],
            [['motivo_cambio' => str_repeat('a', 2001)]], [['id_medico' => '1']], [['id_consulta' => '1']]];
    }

    #[DataProvider('camposInvalidos')]
    public function test_multipart_contract_rejects_invalid_originals_and_declarations(array $cambios): void
    {
        $c = $this->preparar();
        $datos = array_replace(['originales' => $this->contexto($c), 'asociacion_verificada' => '1'], $cambios);
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('archivo.png', $this->bytes('png')))->assertUnprocessable();
        $this->assertDatabaseCount('documentos_laboratorio', 0);
        $this->assertCount(0, $this->archivos());
    }

    public function test_exact_byte_limit_is_accepted_but_oversize_and_excessive_dimensions_are_rejected(): void
    {
        $c = $this->preparar();
        $datos = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'];
        $imagen = imagecreatetruecolor(6001, 1);
        ob_start();
        imagepng($imagen);
        $ancha = ob_get_clean();
        $imagen = null;
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('ancha.png', $ancha))->assertUnprocessable();
        $bytes = str_pad($this->bytes('png'), 10485761, ' ');
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('grande.png', $bytes))->assertUnprocessable();
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('limite.png', substr($bytes, 0, 10485760)))->assertCreated();
        $this->assertSame(10485760, (int) DB::table('documentos_laboratorio')->sole()->tamano_bytes);
    }

    public function test_published_document_and_occupied_destination_are_not_modified(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $this->devolver($c);
        $datos = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1', 'tipo' => 'reasociar', 'asociacion_destino_verificada' => '1',
            'id_solicitud_destino' => $c['solicitud'], 'originales_destino' => $this->contexto($c), 'motivo_cambio' => 'Corrección ficticia'];
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertConflict()->assertJsonPath('error.code', 'LAB_DESTINO_NO_PERMITIDO');
        $destino = $this->preparar();
        $destino['cookies'] = $c['cookies'];
        $this->cargar($destino)->assertCreated();
        $datos['id_solicitud_destino'] = $destino['solicitud'];
        $datos['originales_destino'] = $this->contexto($destino);
        $antes = $this->snapshot();
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertConflict()->assertJsonPath('error.code', 'LAB_DESTINO_NO_PERMITIDO');
        $this->assertSame($antes, $this->snapshot());
        $v = DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['solicitud'])->value('id_version_actual');
        $r = DB::table('revisiones_resultado')->insertGetId(['id_version_resultado' => $v, 'id_medico' => $c['r']['doctors'][0], 'decision' => 'Autorizar', 'revisada_en' => now()->addSecond()], 'id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $c['solicitud'])->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $r]);
        $datos = ['originales' => $this->contexto($c), 'tipo' => 'sustituir', 'asociacion_verificada' => '1', 'motivo_cambio' => 'Cambio rechazado'];
        $antes = $this->snapshot();
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, UploadedFile::fake()->createWithContent('nuevo.png', $this->bytes('png')))->assertConflict();
        $this->assertSame($antes, $this->snapshot());
        $this->assertCount(2, $this->archivos());
    }

    public function test_reassociation_does_not_serve_or_expand_origin_to_destination_doctor(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $this->devolver($c);
        $destino = $this->preparar();
        $destino['cookies'] = $c['cookies'];
        $datos = ['originales' => $this->contexto($c), 'tipo' => 'reasociar', 'asociacion_verificada' => '1', 'asociacion_destino_verificada' => '1',
            'id_solicitud_destino' => $destino['solicitud'], 'originales_destino' => $this->contexto($destino), 'motivo_cambio' => 'MOTIVO-CORRECCION-PRIVADO'];
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertCreated();
        $medico = $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $destino['r']['doctors'][0])->update(['id_usuario' => $medico->getKey()]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $medico)->assertOk();
        $res = $this->navegador($cookies, 'GET', $destino['uri'].'/versiones')->assertOk()->assertJsonMissingPath('data.0.id_version_origen');
        $this->assertStringNotContainsString('ruta_privada', $res->getContent());
        $this->assertStringNotContainsString('MOTIVO-CORRECCION-PRIVADO', $res->getContent());
        $this->navegador($cookies, 'GET', $c['uri'].'/versiones')->assertNotFound();
        $paciente = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $destino['r']['patient'])->update(['id_usuario' => $paciente->getKey()]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $paciente)->assertOk();
        $this->navegador($cookies, 'GET', $destino['uri'])->assertOk()->assertJsonPath('data.estado', 'Pendiente')->assertJsonPath('data.resultado', null)->assertJsonMissingPath('data.edicion');
        $this->navegador($cookies, 'GET', $destino['uri'].'/versiones')->assertForbidden();
    }

    public function test_post_commit_response_failure_preserves_committed_file_and_does_not_repeat_write(): void
    {
        $c = $this->preparar();
        $fired = false;
        Event::listen(TransactionCommitted::class, function ($event) use (&$fired): void {
            if (! $fired && $event->connection->transactionLevel() === 0 && DB::table('documentos_laboratorio')->count() === 1) {
                $fired = true;
                throw new RuntimeException('Fallo simulado después de commit.');
            }
        });
        $this->cargar($c)->assertStatus(500);
        $this->assertTrue($fired);
        $this->assertDatabaseCount('documentos_laboratorio', 1);
        $this->assertDatabaseCount('versiones_resultado', 1);
        $this->assertCount(1, $this->archivos());
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.edicion.originales.ultima_version', 1);
    }

    public function test_missing_source_bytes_reject_reassociation_without_changing_history(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $this->devolver($c);
        $destino = $this->preparar();
        $destino['cookies'] = $c['cookies'];
        $datos = ['originales' => $this->contexto($c), 'tipo' => 'reasociar', 'asociacion_verificada' => '1', 'asociacion_destino_verificada' => '1',
            'id_solicitud_destino' => $destino['solicitud'], 'originales_destino' => $this->contexto($destino), 'motivo_cambio' => 'Corrección ficticia'];
        unlink($this->archivos()[0]);
        $antes = $this->snapshot();
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertConflict()->assertJsonPath('error.code', 'LAB_ARCHIVO_NO_DISPONIBLE');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_filesystem_failure_is_safe_and_does_not_confirm_metadata(): void
    {
        $c = $this->preparar();
        $archivos = Mockery::mock(ArchivoLaboratorio::class)->makePartial();
        $archivos->shouldReceive('guardar')->once()->andThrow(new RuntimeException('RUTA-DISCO-PRIVADA'));
        $this->app->instance(ArchivoLaboratorio::class, $archivos);
        $res = $this->cargar($c)->assertStatus(500);
        $this->assertStringNotContainsString('RUTA-DISCO', $res->getContent());
        $this->assertDatabaseCount('documentos_laboratorio', 0);
    }

    public function test_encrypted_pdf_and_decompression_dimensions_are_rejected(): void
    {
        $c = $this->preparar();
        mkdir($this->root, 0700, true);
        file_put_contents($this->root.'/fixture.pdf', $this->pdf());
        $p = new Process([config('laboratorio.qpdf'), '--encrypt', 'ficticia', 'propietario-ficticio', '256', '--', $this->root.'/fixture.pdf', $this->root.'/cifrado.pdf']);
        $p->mustRun();
        $datos = ['originales' => $this->contexto($c), 'asociacion_verificada' => '1'];
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos,
            UploadedFile::fake()->createWithContent('cifrado.pdf', file_get_contents($this->root.'/cifrado.pdf')))->assertUnprocessable();
        $imagen = imagecreatetruecolor(4000, 3000);
        ob_start();
        imagepng($imagen);
        $bytes = ob_get_clean();
        $imagen = null;
        $this->multipart($c['cookies'], $c['uri'].'/documentos', $datos, UploadedFile::fake()->createWithContent('pixeles.png', $bytes))->assertUnprocessable();
        $this->assertCount(0, $this->archivos());
    }

    public function test_failed_reassociation_audit_rolls_back_both_pointers_and_preserves_source_file(): void
    {
        $c = $this->preparar();
        $this->cargar($c)->assertCreated();
        $this->devolver($c);
        $destino = $this->preparar();
        $destino['cookies'] = $c['cookies'];
        $datos = ['originales' => $this->contexto($c), 'tipo' => 'reasociar', 'asociacion_verificada' => '1', 'asociacion_destino_verificada' => '1',
            'id_solicitud_destino' => $destino['solicitud'], 'originales_destino' => $this->contexto($destino), 'motivo_cambio' => 'Corrección ficticia'];
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('documentoLaboratorioRegistrado')->once()->andThrow(new RuntimeException('Rollback ficticio.'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $antes = $this->snapshot();
        $sha = hash_file('sha256', $this->archivos()[0]);
        $this->multipart($c['cookies'], $c['uri'].'/correcciones', $datos, null)->assertStatus(500);
        $this->assertSame($antes, $this->snapshot());
        $this->assertCount(1, $this->archivos());
        $this->assertSame($sha, hash_file('sha256', $this->archivos()[0]));
    }

    public function test_revocation_during_metadata_creation_rolls_back_bytes_and_data(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(DocumentoLaboratorioRepository::class)->makePartial();
        $repo->shouldReceive('crear')->once()->andReturnUsing(function ($ruta, $metadatos, $autor, $ahora) use ($c) {
            $doc = (new DocumentoLaboratorioRepository)->crear($ruta, $metadatos, $autor, $ahora);
            DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();

            return $doc;
        });
        $this->app->instance(DocumentoLaboratorioRepository::class, $repo);
        $antes = $this->snapshot();
        $this->cargar($c)->assertUnauthorized();
        $this->assertSame($antes, $this->snapshot());
        $this->assertCount(0, $this->archivos());
    }

    public function test_failed_compensation_keeps_private_orphan_without_confirming_operation(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('documentoLaboratorioRegistrado')->once()->andThrow(new RuntimeException('Rollback ficticio.'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $archivos = Mockery::mock(ArchivoLaboratorio::class)->makePartial();
        $archivos->shouldReceive('retirarNuevo')->once()->andThrow(new RuntimeException('Limpieza ficticia rechazada.'));
        $this->app->instance(ArchivoLaboratorio::class, $archivos);
        $this->cargar($c)->assertStatus(500);
        $this->assertDatabaseCount('documentos_laboratorio', 0);
        $this->assertDatabaseCount('versiones_resultado', 0);
        $this->assertCount(1, $this->archivos());
    }

    public function test_malformed_stored_path_is_never_resolved_or_removed(): void
    {
        $c = $this->preparar();
        $archivo = new ArchivoLaboratorio;
        try {
            $archivo->verificar('../../archivo-ajeno.pdf', ['tamano_bytes' => 1, 'sha256' => str_repeat('a', 64)]);
            $this->fail('Debe rechazar ruta ajena.');
        } catch (AppError $e) {
            $this->assertSame('LAB_ARCHIVO_NO_DISPONIBLE', $e->codigo);
        }
        $this->assertCount(0, $this->archivos());
    }
}
