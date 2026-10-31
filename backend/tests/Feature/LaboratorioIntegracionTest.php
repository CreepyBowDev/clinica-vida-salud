<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class LaboratorioIntegracionTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_lab_integracion_'.strtolower(Str::random(16));
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

    private function preparar(): array
    {
        // Solo identidades verificadas, recursos y cita son fixtures; consulta y laboratorio usan APIs.
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
        $cita = (string) DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $consulta = $this->consulta($cookies['Médico'], $cita);

        return compact('r', 'actores', 'cookies', 'cita', 'consulta');
    }

    private function consulta(array &$cookies, string $cita): string
    {
        $uri = '/api/citas/'.$cita.'/consulta';
        $originales = $this->navegador($cookies, 'GET', $uri)->assertOk()->json('data.edicion.originales');

        return $this->navegador($cookies, 'POST', $uri, ['originales' => $originales, 'atencion_real_verificada' => true,
            'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'LAB-CLINICO-SECRETO']])->assertCreated()->json('data.consulta.id_consulta');
    }

    private function solicitar(array &$cookies, string $consulta): string
    {
        $uri = '/api/consultas/'.$consulta.'/solicitudes-laboratorio';
        $get = $this->navegador($cookies, 'GET', $uri)->assertOk()->json('data.edicion.originales');

        return $this->navegador($cookies, 'POST', $uri, ['originales' => $get, 'atencion_real_verificada' => true,
            'estudio_solicitado' => 'LAB-ESTUDIO-SECRETO'])->assertCreated()->assertJsonPath('data.estado', 'Pendiente')->json('data.id_solicitud');
    }

    private function detalle(array &$cookies, string $id): array
    {
        return $this->navegador($cookies, 'GET', '/api/solicitudes-laboratorio/'.$id)->assertOk()->json('data');
    }

    private function bytes(string $tipo): string
    {
        if ($tipo === 'pdf') {
            $pdf = "%PDF-1.4\n";
            $offsets = [];
            foreach (['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>'] as $i => $objeto) {
                $offsets[] = strlen($pdf);
                $pdf .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
            }
            $xref = strlen($pdf);
            $pdf .= "xref\n0 4\n0000000000 65535 f \n";
            foreach ($offsets as $offset) {
                $pdf .= sprintf("%010d 00000 n \n", $offset);
            }

            return $pdf."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        }
        $imagen = imagecreatetruecolor(20, 20);
        ob_start();
        $tipo === 'png' ? imagepng($imagen) : imagejpeg($imagen);
        $bytes = ob_get_clean();
        $imagen = null;

        return $bytes;
    }

    private function cargar(array &$cookies, string $id, string $tipo, bool $corregir = false, ?string $motivo = null): TestResponse
    {
        $datos = ['originales' => $this->detalle($cookies, $id)['edicion']['originales'], 'asociacion_verificada' => '1'];
        if ($corregir) {
            $datos += ['tipo' => 'sustituir', 'motivo_cambio' => 'LAB-CORRECCION-SECRETO'];
        }
        if ($motivo !== null) {
            $datos['motivo_cambio'] = $motivo;
        }
        array_walk_recursive($datos, static function (&$v): void {
            $v = $v === null ? '' : $v;
        });
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetScopedInstances();
        $headers = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Origin' => 'http://localhost:5173',
            'Content-Type' => 'multipart/form-data', 'X-XSRF-TOKEN' => $cookies['XSRF-TOKEN']]);
        $headers['REMOTE_ADDR'] = '192.0.2.10';
        $res = $this->call('POST', '/api/solicitudes-laboratorio/'.$id.($corregir ? '/correcciones' : '/documentos'),
            $datos, $cookies, ['archivo' => UploadedFile::fake()->createWithContent('LAB-NOMBRE-PRIVADO.'.$tipo, $this->bytes($tipo))], $headers);
        foreach ($res->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $res;
    }

    private function revisar(array &$cookies, string $id, string $decision): TestResponse
    {
        $datos = ['originales' => $this->detalle($cookies, $id)['edicion']['originales'],
            'decision' => $decision, 'correspondencia_verificada' => true];
        if ($decision === 'Devolver') {
            $datos['motivo'] = 'LAB-DEVOLUCION-SECRETO';
        }

        return $this->navegador($cookies, 'POST', '/api/solicitudes-laboratorio/'.$id.'/revisiones', $datos);
    }

    private function descargar(array &$cookies, string $id, string $version): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/solicitudes-laboratorio/'.$id.'/archivo?id_version_resultado='.$version);
    }

    private function clinica(): array
    {
        $snapshot = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios'] as $tabla) {
            $snapshot[$tabla] = DB::table($tabla)->orderByRaw('1')->get()->toJson();
        }

        return $snapshot;
    }

    private function comprobarDisco(): void
    {
        $docs = DB::table('documentos_laboratorio')->get();
        $this->assertCount($docs->count(), glob($this->root.'/documentos/*') ?: []);
        foreach ($docs as $doc) {
            $this->assertSame((int) $doc->tamano_bytes, filesize($this->root.'/'.$doc->ruta_privada));
            $this->assertSame($doc->sha256, hash_file('sha256', $this->root.'/'.$doc->ruta_privada));
        }
    }

    public static function recorridos(): array
    {
        return [['pdf', 'Médico', 'Administrador', false], ['pdf', 'Administrador', 'Médico', true],
            ['jpg', 'Administrador', 'Administrador', false], ['jpg', 'Médico', 'Médico', true],
            ['png', 'Médico', 'Médico', false], ['png', 'Administrador', 'Administrador', true]];
    }

    #[DataProvider('recorridos')]
    public function test_actual_clinical_request_upload_correction_publication_download_and_reconciliation(string $tipo, string $solicitante, string $revisor, bool $corregir): void
    {
        $c = $this->preparar();
        $admin = &$c['cookies']['Administrador'];
        $paciente = &$c['cookies']['Paciente'];
        $medico = &$c['cookies']['Médico'];
        if ($corregir) {
            $atencion = $this->navegador($medico, 'GET', '/api/citas/'.$c['cita'].'/consulta')->json('data.cita');
            $this->navegador($medico, 'PATCH', '/api/citas/'.$c['cita'].'/atencion', ['atencion_realizada' => true,
                'version_programacion_original' => $atencion['version_programacion'], 'actualizada_en_original' => $atencion['actualizada_en']])->assertOk();
            $profesional = $this->navegador($admin, 'GET', '/api/medicos/'.$c['r']['doctors'][0])->assertOk()->json('data');
            $this->navegador($admin, 'PATCH', '/api/medicos/'.$c['r']['doctors'][0].'/estado', ['activo' => false,
                'actualizado_en_original' => $profesional['actualizado_en']])->assertOk();
        }
        $antes = $this->clinica();
        $id = $this->solicitar($c['cookies'][$solicitante], $c['consulta']);
        $this->assertSame((string) $c['actores'][$solicitante]->getKey(), $this->detalle($admin, $id)['creada_por']);
        $subida = $this->cargar($admin, $id, $tipo)->assertCreated()->assertJsonPath('data.estado', 'En revisión')->json('data');
        $primera = $version = $subida['edicion']['originales']['id_version_actual'];
        $versionInicial = DB::table('versiones_resultado')->where('id_version_resultado', $primera)->sole();
        $documentoInicial = DB::table('documentos_laboratorio')->sole();
        $this->descargar($paciente, $id, $version)->assertNotFound();
        $this->descargar($medico, $id, $version)->assertOk()->assertStreamedContent($this->bytes($tipo));
        if ($corregir) {
            $devolvente = $revisor === 'Médico' ? 'Administrador' : 'Médico';
            $this->revisar($c['cookies'][$devolvente], $id, 'Devolver')->assertCreated()->assertJsonPath('data.estado', 'En revisión');
            $this->revisar($c['cookies'][$revisor], $id, 'Autorizar')->assertConflict();
            $tipo = ['pdf' => 'png', 'jpg' => 'pdf', 'png' => 'jpg'][$tipo];
            $correccion = $this->cargar($admin, $id, $tipo, true)->assertCreated()->assertJsonPath('data.edicion.originales.ultima_version', 2)->json('data');
            $version = $correccion['edicion']['originales']['id_version_actual'];
            $this->assertSame((string) $primera, (string) DB::table('versiones_resultado')->where('id_version_resultado', $version)->value('id_version_origen'));
            $this->assertEquals($versionInicial, DB::table('versiones_resultado')->where('id_version_resultado', $primera)->sole());
            $this->assertEquals($documentoInicial, DB::table('documentos_laboratorio')->where('id_documento', $documentoInicial->id_documento)->sole());
        }
        $publicada = $this->revisar($c['cookies'][$revisor], $id, 'Autorizar')->assertCreated()->assertJsonPath('data.estado', 'Disponible')->json('data');
        $this->assertSame((string) $c['actores'][$revisor]->getKey(), $publicada['edicion']['ultima_revision']['revisada_por']);
        $this->assertSame((string) $c['r']['doctors'][0], $publicada['edicion']['ultima_revision']['id_medico_responsable']);
        foreach (['Administrador', 'Médico', 'Paciente'] as $rol) {
            $this->descargar($c['cookies'][$rol], $id, $version)->assertOk()->assertStreamedContent($this->bytes($tipo));
        }
        $this->navegador($paciente, 'HEAD', '/api/solicitudes-laboratorio/'.$id.'/archivo?id_version_resultado='.$version)
            ->assertOk()->assertContent('')->assertHeader('Content-Length', (string) strlen($this->bytes($tipo)));
        if ($corregir) {
            $this->descargar($paciente, $id, $primera)->assertConflict();
        }
        $this->navegador($paciente, 'GET', '/api/solicitudes-laboratorio?estado=Disponible')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.resultado.id_version_resultado', $version)->assertDontSee('revisada_por')->assertDontSee('LAB-CORRECCION');
        $this->navegador($paciente, 'GET', '/api/solicitudes-laboratorio?estado=Pendiente')->assertOk()->assertJsonPath('meta.total', 0);
        $this->navegador($paciente, 'GET', '/api/citas/'.$c['cita'].'/consulta')->assertOk()->assertJsonPath('data.estudios.0.estado', 'Disponible')
            ->assertJsonPath('data.estudios.0.resultado.numero_version', $corregir ? 2 : 1)->assertDontSee('LAB-DEVOLUCION');
        $this->navegador($medico, 'GET', '/api/solicitudes-laboratorio/'.$id.'/versiones?per_page=1')->assertOk()->assertJsonPath('meta.total', $corregir ? 2 : 1);
        $this->navegador($paciente, 'GET', '/api/solicitudes-laboratorio/'.$id.'/versiones')->assertForbidden();
        $this->descargar($c['cookies']['Recepcionista'], $id, $version)->assertForbidden();
        $this->cargar($admin, $id, $tipo, true)->assertConflict();
        $eventos = $this->navegador($admin, 'GET', '/api/bitacora?entidad_referida=SolicitudLaboratorio&registro_referido='.$id)
            ->assertOk()->assertJsonPath('meta.total', $corregir ? 5 : 3)->json('data');
        foreach ($eventos as $evento) {
            $this->navegador($admin, 'GET', '/api/bitacora/'.$evento['id_evento'])->assertOk()->assertJsonPath('data.referencias.id_medico', (string) $c['r']['doctors'][0])
                ->assertDontSee('LAB-ESTUDIO')->assertDontSee('LAB-CLINICO')->assertDontSee('LAB-DEVOLUCION')->assertDontSee('LAB-CORRECCION')
                ->assertDontSee('LAB-NOMBRE')->assertDontSee('ruta_privada')->assertDontSee('sha256');
        }
        // Revocación administrativa real: reactivar no restaura cookie anterior.
        $usuario = (string) $c['actores']['Paciente']->getKey();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario.'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->descargar($paciente, $id, $version)->assertUnauthorized();
        $this->navegador($admin, 'PATCH', '/api/usuarios/'.$usuario.'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->descargar($paciente, $id, $version)->assertUnauthorized();
        $paciente = $this->csrfAuth();
        $this->loginAuth($paciente, $c['actores']['Paciente'])->assertOk();
        $this->descargar($paciente, $id, $version)->assertOk()->assertStreamedContent($this->bytes($tipo));
        $this->assertDatabaseCount('versiones_resultado', $corregir ? 2 : 1);
        $this->assertDatabaseCount('revisiones_resultado', $corregir ? 2 : 1);
        $this->assertSame($antes, $this->clinica());
        $this->comprobarDisco();
        Mail::assertNothingSent();
    }

    public function test_actual_cross_patient_reassociation_and_origin_reload_preserve_care_and_require_independent_reviews(): void
    {
        $c = $this->preparar();
        $admin = &$c['cookies']['Administrador'];
        $medico = &$c['cookies']['Médico'];
        $paciente = &$c['cookies']['Paciente'];
        $doctorDestino = $this->usuarioAuth('Médico', vincular: false);
        $pacienteDestino = $this->usuarioAuth('Paciente');
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][1])->update(['id_usuario' => $doctorDestino->getKey()]);
        $cookiesDoctor = $this->csrfAuth();
        $this->loginAuth($cookiesDoctor, $doctorDestino)->assertOk();
        $cookiesPaciente = $this->csrfAuth();
        $this->loginAuth($cookiesPaciente, $pacienteDestino)->assertOk();
        $cita = (string) DB::table('citas')->insertGetId($this->citaRow($c['r'], ['id_medico' => $c['r']['doctors'][1],
            'id_consultorio' => $c['r']['rooms'][1], 'id_paciente' => $pacienteDestino->paciente->getKey()]), 'id_cita');
        $consulta = $this->consulta($cookiesDoctor, $cita);
        $antes = $this->clinica();
        $origen = $this->solicitar($medico, $c['consulta']);
        $destino = $this->solicitar($cookiesDoctor, $consulta);
        $original = $this->cargar($admin, $origen, 'pdf')->assertCreated()->json('data.edicion.originales.id_version_actual');
        $this->revisar($medico, $origen, 'Devolver')->assertCreated();
        $datos = ['originales' => $this->detalle($admin, $origen)['edicion']['originales'],
            'originales_destino' => $this->detalle($admin, $destino)['edicion']['originales'], 'tipo' => 'reasociar',
            'id_solicitud_destino' => $destino, 'asociacion_verificada' => 1, 'asociacion_destino_verificada' => 1, 'motivo_cambio' => 'LAB-ORIGEN-PRIVADO'];
        $this->navegador($admin, 'POST', '/api/solicitudes-laboratorio/'.$origen.'/correcciones', $datos)->assertCreated()
            ->assertJsonPath('data.edicion.originales.id_version_actual', null)->assertJsonPath('data.destino.id_solicitud', $destino);
        $vDestino = $this->detalle($cookiesDoctor, $destino)['edicion']['originales']['id_version_actual'];
        $this->navegador($cookiesDoctor, 'GET', '/api/solicitudes-laboratorio/'.$destino.'/versiones')->assertOk()
            ->assertJsonMissingPath('data.0.id_version_origen')->assertDontSee('LAB-ORIGEN');
        $this->descargar($medico, $destino, $vDestino)->assertNotFound();
        $this->descargar($paciente, $destino, $vDestino)->assertNotFound();
        $this->descargar($cookiesPaciente, $destino, $vDestino)->assertNotFound();
        $this->revisar($cookiesDoctor, $destino, 'Autorizar')->assertCreated();
        $this->descargar($cookiesPaciente, $destino, $vDestino)->assertOk()->assertStreamedContent($this->bytes('pdf'));
        $nuevo = $this->cargar($admin, $origen, 'png', motivo: 'LAB-NUEVA-CARGA-PRIVADA')->assertCreated()
            ->assertJsonPath('data.edicion.originales.ultima_version', 2)->json('data.edicion.originales.id_version_actual');
        $this->revisar($admin, $origen, 'Autorizar')->assertCreated();
        $this->descargar($paciente, $origen, $original)->assertConflict();
        $this->descargar($paciente, $origen, $nuevo)->assertOk()->assertStreamedContent($this->bytes('png'));
        $this->descargar($cookiesPaciente, $origen, $nuevo)->assertNotFound();
        $this->assertDatabaseCount('documentos_laboratorio', 2);
        $this->assertDatabaseCount('versiones_resultado', 3);
        $this->assertDatabaseCount('revisiones_resultado', 3);
        $this->assertSame($antes, $this->clinica());
        $this->comprobarDisco();
        Mail::assertNothingSent();
    }
}
