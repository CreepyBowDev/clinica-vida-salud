<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Bitacora;
use Clinica\data\models\SolicitudLaboratorio;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\laboratorio\SolicitudLaboratorioRepository;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class SolicitudesLaboratorioTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_solicitudes_lab_'.strtolower(Str::random(16));
        $anterior = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, false)', ['search_path', $schema.', public, pg_temp']);
        $this->beforeApplicationDestroyed(function () use ($schema, $anterior): void {
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $anterior]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        });
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'session.lifetime' => 4320,
            'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    }

    // Identidad/cita/consulta son fixtures declaradas; cookies/CSRF y CU-24 recorren API real.
    private function preparar(string $rol = 'Administrador', string $estado = 'Programada'): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        if ($rol === 'Médico') {
            DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $actor->getKey(), 'activo' => false]);
        }
        if ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        $cita = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado]), 'id_cita');
        $consulta = (string) DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $version = (string) DB::table('versiones_consulta')->insertGetId(['id_consulta' => $consulta, 'numero_version' => 1,
            'id_autor' => $actor->getKey(), 'registrada_en' => now(), 'tipo_consulta' => 'Control', 'motivo_consulta' => 'CLINICA-FICTICIA-PRIVADA'], 'id_version_consulta');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $uri = '/api/consultas/'.$consulta.'/solicitudes-laboratorio';
        $datos = ['estudio_solicitado' => 'ESTUDIO-FICTICIO-PRIVADO', 'originales' => ['id_version_consulta' => $version], 'atencion_real_verificada' => true];

        return compact('r', 'actor', 'cita', 'consulta', 'version', 'cookies', 'uri', 'datos');
    }

    private function solicitud(array $c, string $estado = 'Pendiente', string $decision = 'Autorizar', bool $otroMedico = false, bool $posterior = false): string
    {
        $id = (string) DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $c['consulta'], 'estudio_solicitado' => 'ESTUDIO-FICTICIO-PRIVADO', 'solicitada_en' => now()], 'id_solicitud');
        if ($estado === 'Pendiente') {
            return $id;
        }
        $doc = DB::table('documentos_laboratorio')->insertGetId(['cargado_por' => $c['actor']->getKey(), 'ruta_privada' => 'privado/'.Str::uuid(),
            'nombre_original' => 'SECRETO.pdf', 'tipo_mime' => 'application/pdf', 'tamano_bytes' => 123, 'sha256' => str_repeat('a', 64), 'cargado_en' => now()], 'id_documento');
        $v = DB::table('versiones_resultado')->insertGetId(['id_solicitud' => $id, 'id_documento' => $doc, 'numero_version' => 1,
            'id_autor' => $c['actor']->getKey(), 'registrada_en' => now(), 'motivo_cambio' => 'MOTIVO-DOCUMENTAL-PRIVADO'], 'id_version_resultado');
        $revision = DB::table('revisiones_resultado')->insertGetId(['id_version_resultado' => $v,
            'id_medico' => $c['r']['doctors'][$otroMedico ? 1 : 0], 'decision' => $decision,
            'motivo' => 'DEVOLUCION-PRIVADA', 'revisada_en' => now()], 'id_revision');
        if ($posterior) {
            DB::table('versiones_resultado')->insert(['id_solicitud' => $id, 'id_documento' => $doc, 'numero_version' => 2,
                'id_autor' => $c['actor']->getKey(), 'registrada_en' => now()]);
        }
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->update(['estado_interno' => $estado,
            'id_version_actual' => $v, 'id_revision_publicada' => $estado === 'Disponible' ? $revision : null]);

        return $id;
    }

    private function snapshot(): array
    {
        $s = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios',
            'solicitudes_laboratorio', 'documentos_laboratorio', 'versiones_resultado', 'revisiones_resultado', 'bitacora'] as $t) {
            $s[$t] = DB::table($t)->get()->toJson();
        }

        return $s;
    }

    public static function altas(): array
    {
        $casos = [];
        foreach (['Administrador', 'Médico'] as $rol) {
            foreach (['Programada', 'Confirmada', 'Atendida'] as $estado) {
                $casos[] = [$rol, $estado];
            }
        }

        return $casos;
    }

    #[DataProvider('altas')]
    public function test_authorized_request_has_real_executor_without_changing_clinical_or_appointment_history(string $rol, string $estado): void
    {
        $c = $this->preparar($rol, $estado);
        $antes = $this->snapshot();
        $get = $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.solicitudes', [])
            ->assertJsonPath('data.edicion.acciones.solicitar', true)->assertJsonPath('meta.total', 0);
        $c['datos']['originales'] = $get->json('data.edicion.originales');
        for ($i = 0; $i < 2; $i++) {
            $res = $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated()
                ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.estado', 'Pendiente')
                ->assertJsonPath('data.resultado', null)->assertJsonPath('data.creada_por', (string) $c['actor']->getKey())
                ->assertJsonPath('data.medico.id_medico', (string) $c['r']['doctors'][0])
                ->assertJsonPath('data.solicitada_en', '2026-11-01T12:00:00.000000Z');
            $sol = SolicitudLaboratorio::query()->findOrFail($res->json('data.id_solicitud'));
            $this->assertSame((string) $c['actor']->getKey(), (string) $sol->creadaPor->getKey());
            $this->assertArrayNotHasKey('creada_por', $sol->toArray());
            $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio/'.$sol->getKey())->assertOk()->assertJsonPath('data', $res->json('data'));
        }
        $this->assertSame(2, $c['actor']->solicitudesLaboratorioCreadas()->count());
        $despues = $this->snapshot();
        unset($antes['solicitudes_laboratorio'], $antes['bitacora'], $despues['solicitudes_laboratorio'], $despues['bitacora']);
        $this->assertSame($antes, $despues);
        $eventos = Bitacora::query()->where('tipo_evento', 'ESTUDIO_SOLICITADO')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame((string) $c['actor']->getKey(), (string) $eventos[0]->id_usuario_responsable);
        $this->assertSame((string) $c['r']['doctors'][0], $eventos[0]->referencias->id_medico);
        $detalle = (new BitacoraDetalleResource($eventos[0]))->toArray(Request::create('/api/bitacora/1'));
        $this->assertSame((string) $eventos[0]->registro_referido, $detalle['referencias']->id_solicitud);
        foreach (['ESTUDIO-FICTICIO-PRIVADO', 'CLINICA-FICTICIA-PRIVADA', 'atencion_real_verificada'] as $secreto) {
            $this->assertStringNotContainsString($secreto, DB::table('bitacora')->get()->toJson());
        }
        Mail::assertNothingSent();
    }

    public static function tiempos(): array
    {
        return [['2026-11-01T11:59:59.999999Z', 409], ['2026-11-01T12:00:00Z', 201], ['2026-11-01T12:00:00.000001Z', 201], ['2026-11-03T12:00:00Z', 201]];
    }

    #[DataProvider('tiempos')]
    public function test_request_uses_server_time_from_start_and_can_document_after_attention(string $hora, int $status): void
    {
        $c = $this->preparar('Médico', 'Atendida');
        CarbonImmutable::setTestNow($hora);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus($status);
        $this->assertDatabaseCount('solicitudes_laboratorio', $status === 201 ? 1 : 0);
    }

    public static function entradas(): array
    {
        return [[['estudio_solicitado' => '']], [['estudio_solicitado' => '   ']], [['estudio_solicitado' => null]],
            [['estudio_solicitado' => str_repeat('a', 256)]], [['estudio_solicitado' => ['texto']]],
            [['atencion_real_verificada' => false]], [['atencion_real_verificada' => 'true']], [['atencion_real_verificada' => 1]],
            [['originales' => []]], [['originales' => null]], [['originales' => ['id_version_consulta' => 0]]],
            [['originales' => ['id_version_consulta' => '9223372036854775808']]], [['originales' => ['id_version_consulta' => '01']]],
            [['originales' => ['id_version_consulta' => '1', 'extra' => 1]]], [['creada_por' => 1]], [['id_medico' => 1]],
            [['estado_interno' => 'Disponible']], [['ruta_privada' => '../archivo']], [['id_objetivo' => 1]], [['sha256' => 'secreto']]];
    }

    #[DataProvider('entradas')]
    public function test_closed_payload_rejects_invalid_or_privileged_fields_without_mutations(array $cambios): void
    {
        $c = $this->preparar();
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], array_replace($c['datos'], $cambios))->assertUnprocessable();
        $this->assertSame($antes, $this->snapshot());
    }

    public static function rechazos(): array
    {
        return [['Administrador', 'Cancelada', 409], ['Médico', 'Inasistencia', 409], ['Paciente', 'Programada', 403], ['Recepcionista', 'Programada', 403]];
    }

    #[DataProvider('rechazos')]
    public function test_role_and_state_rejections_leave_no_partial_request(string $rol, string $estado, int $status): void
    {
        $c = $this->preparar($rol, $estado);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus($status);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_obsolete_consultation_version_requires_fresh_context(): void
    {
        $c = $this->preparar();
        DB::table('versiones_consulta')->insert(['id_consulta' => $c['consulta'], 'numero_version' => 2, 'id_autor' => $c['actor']->getKey(),
            'registrada_en' => now(), 'tipo_consulta' => 'Control', 'motivo_consulta' => 'Corrección ficticia']);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'LAB_EDICION_OBSOLETA');
        $c['datos']['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->json('data.edicion.originales');
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated();
    }

    public function test_audit_failure_rolls_back_new_request_and_returns_safe_error(): void
    {
        $c = $this->preparar();
        $antes = $this->snapshot();
        $repo = Mockery::mock(BitacoraRepository::class)->makePartial();
        $repo->shouldReceive('estudioSolicitado')->once()->andThrow(new RuntimeException('RUTA-PRIVADA-Y-SQL'));
        $this->app->instance(BitacoraRepository::class, $repo);
        $res = $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->assertStringNotContainsString('RUTA-PRIVADA', $res->getContent());
        $this->assertSame($antes, $this->snapshot());
    }

    public static function proyecciones(): array
    {
        $casos = [];
        foreach (['Administrador', 'Médico', 'Paciente'] as $rol) {
            foreach ([['Pendiente', 'Autorizar', false, false], ['En revisión', 'Devolver', false, false],
                ['Disponible', 'Autorizar', false, false], ['Disponible', 'Devolver', false, false],
                ['Disponible', 'Autorizar', true, false], ['Disponible', 'Autorizar', false, true]] as $datos) {
                $casos[] = [$rol, ...$datos];
            }
        }

        return $casos;
    }

    #[DataProvider('proyecciones')]
    public function test_metadata_projection_and_patient_filters_do_not_leak_internal_publication_details(string $rol, string $estado, string $decision, bool $otro, bool $posterior): void
    {
        $c = $this->preparar($rol);
        $id = $this->solicitud($c, $estado, $decision, $otro, $posterior);
        $publicado = $estado === 'Disponible' && $decision === 'Autorizar' && ! $otro && ! $posterior;
        $antes = $this->snapshot();
        $res = $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio/'.$id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.estado', $rol === 'Paciente' ? ($publicado ? 'Disponible' : 'Pendiente') : $estado);
        $this->assertSame($publicado, $res->json('data.resultado') !== null);
        if ($rol === 'Paciente') {
            $res->assertJsonMissingPath('data.creada_por')->assertJsonMissingPath('data.edicion');
        } else {
            $res->assertJsonPath('data.creada_por', null);
        }
        foreach (['ruta_privada', 'SECRETO.pdf', 'sha256', 'MOTIVO-DOCUMENTAL-PRIVADO', 'id_documento'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $res->getContent());
        }
        if ($rol === 'Paciente') {
            $this->assertStringNotContainsString('DEVOLUCION-PRIVADA', $res->getContent());
        } elseif ($estado !== 'Pendiente') {
            $res->assertJsonPath('data.edicion.ultima_revision.motivo', 'DEVOLUCION-PRIVADA');
        }
        $url = '/api/solicitudes-laboratorio?id_consulta='.$c['consulta'].'&estado='.rawurlencode($rol === 'Paciente' ? ($publicado ? 'Disponible' : 'Pendiente') : $estado);
        $this->navegador($c['cookies'], 'GET', $url)->assertOk()->assertJsonPath('meta.total', 1);
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.solicitudes.0', Arr::except($res->json('data'), ['edicion']));
        if ($rol === 'Paciente') {
            $this->navegador($c['cookies'], 'GET', $c['uri'])->assertJsonMissingPath('data.edicion');
        }
        $this->navegador($c['cookies'], 'HEAD', '/api/solicitudes-laboratorio/'.$id)->assertOk()->assertContent('');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_admin_requires_selection_and_patient_cannot_filter_private_state(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio')->assertUnprocessable();
        $patient = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $patient)->assertOk();
        $this->navegador($cookies, 'GET', '/api/solicitudes-laboratorio')->assertOk()->assertJsonPath('data', []);
        $this->navegador($cookies, 'GET', '/api/solicitudes-laboratorio?estado=En%20revisi%C3%B3n')->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/solicitudes-laboratorio?id_paciente='.$c['r']['patient'])->assertNotFound();
    }

    public static function filtrosInvalidos(): array
    {
        return [['page=0'], ['per_page=101'], ['id_paciente[]=1'], ['id_consulta=01'], ['estado=Atendida'], ['estado[]=Pendiente'],
            ['fecha_desde=2026-02-30'], ['fecha_desde=2026-11-02&fecha_hasta=2026-11-01'], ['fecha_desde=0000-01-01'],
            ['id_objetivo=1'], ['order_by=estudio_solicitado'], ['creada_por=1'], ['page=1e3']];
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_query_allowlist_rejects_invalid_dates_and_filters(string $query): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio?'.$query)->assertUnprocessable();
    }

    public function test_filters_paginate_in_stable_order_with_clinic_day_boundaries(): void
    {
        $c = $this->preparar();
        $a = $this->solicitud($c);
        $b = $this->solicitud($c);
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $a)->update(['solicitada_en' => '2026-11-01T03:59:59.999999Z']);
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $b)->update(['solicitada_en' => '2026-11-01T04:00:00Z']);
        $base = '/api/solicitudes-laboratorio?id_paciente='.$c['r']['patient'];
        $this->navegador($c['cookies'], 'GET', $base.'&fecha_desde=2026-11-01&fecha_hasta=2026-11-01')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_solicitud', $b);
        $this->navegador($c['cookies'], 'GET', $base.'&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id_solicitud', $a)->assertJsonPath('meta.last_page', 2);
        $this->navegador($c['cookies'], 'GET', $base.'&page=3&per_page=1')->assertOk()->assertJsonPath('data', []);
    }

    #[DataProvider('rolesAjenos')]
    public function test_unrelated_identity_cannot_read_or_create_for_another_consultation(string $rol): void
    {
        $c = $this->preparar();
        $id = $this->solicitud($c);
        $ajeno = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $ajeno)->assertOk();
        foreach ([$c['uri'], '/api/solicitudes-laboratorio/'.$id, '/api/solicitudes-laboratorio?id_consulta='.$c['consulta']] as $uri) {
            $this->navegador($cookies, 'GET', $uri)->assertNotFound();
        }
        $this->navegador($cookies, 'POST', $c['uri'], $c['datos'])->assertStatus($rol === 'Médico' ? 404 : 403);
        $this->assertDatabaseCount('solicitudes_laboratorio', 1);
    }

    public static function rolesAjenos(): array
    {
        return [['Médico'], ['Paciente']];
    }

    public function test_csrf_body_queries_missing_resources_and_revoked_session_are_rejected(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'], csrf: false)->assertStatus(419);
        $this->navegador($c['cookies'], 'POST', $c['uri'].'?extra=1', $c['datos'])->assertUnprocessable();
        $this->navegador($c['cookies'], 'GET', $c['uri'], ['extra' => true])->assertUnprocessable();
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio/9223372036854775807')->assertNotFound();
        $this->navegador($c['cookies'], 'POST', '/api/consultas/9223372036854775807/solicitudes-laboratorio', $c['datos'])->assertNotFound();
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio/01')->assertUnprocessable();
        DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();
        $this->navegador($c['cookies'], 'GET', $c['uri'])->assertUnauthorized();
        $this->assertDatabaseCount('solicitudes_laboratorio', 0);
    }

    public function test_additive_migration_preserves_legacy_published_rows_and_unknown_author(): void
    {
        $c = $this->preparar();
        $id = $this->solicitud($c, 'Disponible');
        $antes = $this->snapshot();
        $migration = require base_path('src/data/database/migrations/2026_10_29_000036_add_creada_por_to_solicitudes_laboratorio.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('solicitudes_laboratorio', 'creada_por'));
        $migration->up();
        $this->assertSame($antes, $this->snapshot());
        $this->assertNull(SolicitudLaboratorio::query()->findOrFail($id)->creada_por);
        $fk = collect(Schema::getForeignKeys('solicitudes_laboratorio'))->firstWhere('name', 'solicitudes_laboratorio_creador_fk');
        $this->assertSame(['usuarios', 'restrict', 'restrict'], [$fk['foreign_table'], $fk['on_update'], $fk['on_delete']]);
        try {
            DB::transaction(fn () => DB::table('solicitudes_laboratorio')->insert(['id_consulta' => $c['consulta'], 'estudio_solicitado' => 'Ficticio', 'solicitada_en' => now(), 'creada_por' => 9223372036854775807]));
            $this->fail('Debe comprobar autor FK.');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
    }

    public static function revocaciones(): array
    {
        return [['cuenta', 401], ['rol', 401], ['vinculo', 401], ['sesion', 401]];
    }

    #[DataProvider('revocaciones')]
    public function test_writer_revalidates_identity_after_context_lookup(string $cambio, int $status): void
    {
        $c = $this->preparar('Médico');
        $repo = Mockery::mock(ConsultaMedicaRepository::class)->makePartial();
        $repo->shouldReceive('porCitaBloqueada')->once()->andReturnUsing(function ($cita, $paciente) use ($c, $cambio) {
            $consulta = (new ConsultaMedicaRepository)->porCitaBloqueada($cita, $paciente);
            match ($cambio) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'rol' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['id_rol' => DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol')]),
                'vinculo' => DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['id_usuario' => null]),
                'sesion' => DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete(),
            };

            return $consulta;
        });
        $this->app->instance(ConsultaMedicaRepository::class, $repo);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus($status);
        $this->assertDatabaseCount('solicitudes_laboratorio', 0);
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'ESTUDIO_SOLICITADO')->count());
    }

    public function test_read_response_is_discarded_if_session_changes_during_materialization(): void
    {
        $c = $this->preparar();
        $id = $this->solicitud($c);
        $repo = Mockery::mock(SolicitudLaboratorioRepository::class)->makePartial();
        $repo->shouldReceive('consultar')->once()->andReturnUsing(function ($id, $ambito) use ($c) {
            $row = (new SolicitudLaboratorioRepository)->consultar($id, $ambito);
            DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();

            return $row;
        });
        $this->app->instance(SolicitudLaboratorioRepository::class, $repo);
        $res = $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio/'.$id)->assertUnauthorized();
        $this->assertStringNotContainsString('ESTUDIO-FICTICIO-PRIVADO', $res->getContent());
    }

    public function test_responsible_doctor_sees_only_own_requests_and_filters_cannot_expand_scope(): void
    {
        $c = $this->preparar('Médico');
        $id = $this->solicitud($c);
        $r = $c['r'];
        $r['doctors'] = array_reverse($r['doctors']);
        $r['rooms'] = array_reverse($r['rooms']);
        $cita = DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-11-01T13:00:00Z', 'fin_at' => '2026-11-01T13:15:00Z']), 'id_cita');
        $consulta = (string) DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid()], 'id_consulta');
        $this->solicitud([...$c, 'consulta' => $consulta]);
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_solicitud', $id);
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio?id_paciente='.$r['patient'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio?id_consulta='.$consulta)->assertNotFound();
        $this->navegador($c['cookies'], 'POST', '/api/consultas/'.$consulta.'/solicitudes-laboratorio', $c['datos'])->assertNotFound();
    }

    public function test_query_of_unpublished_corrupt_result_is_still_pending_for_patient(): void
    {
        $c = $this->preparar('Paciente');
        $id = $this->solicitud($c, 'En revisión', 'Devolver');
        // Disponible con revisión Autorizar no puede tener punteros nulos por CHECK;
        // una decisión Devolver publicada es SQL-persistible, pero no publicable por dominio.
        $row = DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->first();
        $revision = DB::table('revisiones_resultado')->where('id_version_resultado', $row->id_version_actual)->value('id_revision');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $revision]);
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio?estado=Disponible')->assertOk()->assertJsonPath('meta.total', 0);
        $this->navegador($c['cookies'], 'GET', '/api/solicitudes-laboratorio?estado=Pendiente')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
