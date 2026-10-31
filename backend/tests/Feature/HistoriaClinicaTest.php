<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class HistoriaClinicaTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_clinica_'.strtolower(Str::random(16));
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
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function preparar(string $rol = 'Administrador'): array
    {
        $r = $this->citaResources();
        $actor = $this->usuarioAuth($rol, vincular: $rol !== 'Médico');
        $medico = $rol === 'Médico' ? $actor : $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $medico->getKey(), 'activo' => false]);
        if ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }
        $autor = $rol === 'Administrador' ? $actor : $this->usuarioAuth('Administrador');
        $propia = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => 'Programada']), 'id_cita');
        $anterior = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_medico' => $r['doctors'][1],
            'id_consultorio' => $r['rooms'][1], 'inicio_at' => '2026-10-01T12:00:00.123456Z',
            'fin_at' => '2026-10-01T12:15:00.123456Z', 'estado' => 'Atendida', 'motivo_breve' => 'RESERVA-PRIVADA']), 'id_cita');
        $consulta = (string) DB::table('consultas_medicas')->insertGetId(['id_cita' => $anterior,
            'codigo_consulta' => 'CON-FICTICIA', 'iniciada_en' => null, 'creada_en' => '2026-10-01T12:00:00.123456Z'], 'id_consulta');
        foreach ([1, 2] as $version) {
            DB::table('versiones_consulta')->insert(['id_consulta' => $consulta, 'numero_version' => $version,
                'id_autor' => $autor->getKey(), 'registrada_en' => '2026-10-01T12:00:00.123456Z',
                'tipo_consulta' => 'Control', 'motivo_consulta' => 'MOTIVO-CLINICO-'.$version,
                'diagnostico' => $version === 1 ? 'DIAGNOSTICO-ANTERIOR' : 'DIAGNOSTICO-VIGENTE', 'motivo_cambio' => 'CORRECCION-INTERNA']);
            DB::table('versiones_antecedente')->insert(['id_paciente' => $r['patient'], 'id_consulta_origen' => $consulta,
                'id_autor' => $autor->getKey(), 'numero_version' => $version, 'registrada_en' => '2026-10-01T12:00:00.123456Z',
                'alergias' => $version === 1 ? 'ALERGIA-ANTERIOR' : 'ALERGIA-VIGENTE', 'motivo_cambio' => 'CORRECCION-INTERNA']);
        }
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return compact('r', 'actor', 'autor', 'cookies', 'propia', 'anterior', 'consulta');
    }

    private function ruta(array $c, string $vista): string
    {
        return match ($vista) {
            'historia' => '/api/pacientes/'.$c['r']['patient'].'/historia-clinica',
            'consulta' => '/api/citas/'.$c['anterior'].'/consulta',
            'versiones_consulta' => '/api/citas/'.$c['anterior'].'/consulta/versiones',
            'versiones_antecedentes' => '/api/pacientes/'.$c['r']['patient'].'/historia-clinica/antecedentes/versiones',
        };
    }

    private function snapshot(): array
    {
        $datos = [];
        foreach (['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios',
            'bitacora', 'solicitudes_laboratorio', 'versiones_resultado', 'revisiones_resultado', 'documentos_laboratorio'] as $tabla) {
            $datos[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $datos;
    }

    public static function lecturas(): array
    {
        $casos = [];
        foreach (['Administrador', 'Médico', 'Paciente', 'Recepcionista'] as $rol) {
            foreach (['historia', 'consulta', 'versiones_consulta', 'versiones_antecedentes'] as $vista) {
                $casos[] = [$rol, $vista];
            }
        }

        return $casos;
    }

    #[DataProvider('lecturas')]
    public function test_four_roles_read_only_their_approved_projection(string $rol, string $vista): void
    {
        $c = $this->preparar($rol);
        $antes = $this->snapshot();
        $response = $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista));
        if ($rol === 'Recepcionista' || ($rol === 'Paciente' && str_starts_with($vista, 'versiones'))) {
            $response->assertForbidden();
        } else {
            $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('meta.per_page', 20);
            if ($vista === 'historia') {
                $response->assertJsonPath('data.antecedentes.alergias', 'ALERGIA-VIGENTE')->assertJsonPath('meta.total', 1);
                $this->assertStringNotContainsString('DIAGNOSTICO', $response->getContent());
            } elseif ($vista === 'consulta') {
                $response->assertJsonPath('data.consulta.version_vigente.diagnostico', 'DIAGNOSTICO-VIGENTE')
                    ->assertJsonPath('data.consulta.iniciada_en', null)->assertJsonPath('data.consulta.creada_en', '2026-10-01T12:00:00.123456Z')
                    ->assertJsonPath('data.consulta.version_vigente.numero_version', 2);
                if ($rol === 'Administrador') {
                    $response->assertJsonPath('data.edicion.acciones.registrar', false)
                        ->assertJsonPath('data.edicion.acciones.actualizar', true);
                } else {
                    $response->assertJsonMissingPath('data.edicion');
                }
            } else {
                $response->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.numero_version', 2)
                    ->assertJsonPath('data.0.id_autor', (string) $c['autor']->getKey())->assertJsonPath('data.0.motivo_cambio', 'CORRECCION-INTERNA');
            }
            if ($rol === 'Paciente') {
                foreach (['id_autor', 'motivo_cambio', 'CORRECCION-INTERNA', 'ANTERIOR', 'id_consulta_origen'] as $prohibido) {
                    $this->assertStringNotContainsString($prohibido, $response->getContent());
                }
            }
        }
        foreach (['password_hash', 'correo_acceso', 'RESERVA-PRIVADA', '70000000', 'fecha_nacimiento', 'ruta_privada'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $response->getContent());
        }
        $this->assertSame($antes, $this->snapshot());
        Mail::assertNothingSent();
    }

    public static function estadosRelacion(): array
    {
        return [['Programada', 200], ['Confirmada', 200], ['Atendida', 200], ['Cancelada', 404], ['Inasistencia', 404]];
    }

    #[DataProvider('estadosRelacion')]
    public function test_medical_scope_comes_from_own_appointment_even_future_and_professionally_inactive(string $estado, int $status): void
    {
        $c = $this->preparar('Médico');
        DB::table('citas')->where('id_cita', $c['propia'])->update(['estado' => $estado]);
        foreach (['historia', 'consulta', 'versiones_consulta', 'versiones_antecedentes'] as $vista) {
            $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista))->assertStatus($status);
        }
    }

    public static function ajenos(): array
    {
        return [['Médico', 'historia'], ['Médico', 'consulta'], ['Médico', 'versiones_consulta'], ['Médico', 'versiones_antecedentes'],
            ['Paciente', 'historia'], ['Paciente', 'consulta']];
    }

    #[DataProvider('ajenos')]
    public function test_foreign_and_absent_resources_have_same_neutral_404(string $rol, string $vista): void
    {
        $c = $this->preparar($rol);
        $otro = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $otro)->assertOk();
        $foreign = $this->navegador($cookies, 'GET', $this->ruta($c, $vista))->assertNotFound();
        $uri = str_starts_with($vista, 'versiones') && $vista === 'versiones_consulta' ? '/api/citas/999999/consulta/versiones'
            : ($vista === 'consulta' ? '/api/citas/999999/consulta' : '/api/pacientes/999999/historia-clinica'.($vista === 'versiones_antecedentes' ? '/antecedentes/versiones' : ''));
        $absent = $this->navegador($cookies, 'GET', $uri)->assertNotFound();
        $this->assertSame($foreign->json(), $absent->json());
    }

    public function test_patient_sees_current_incomplete_consultation_and_unknowns_remain_null(): void
    {
        $c = $this->preparar('Paciente');
        DB::table('versiones_consulta')->insert(['id_consulta' => $c['consulta'], 'numero_version' => 3,
            'id_autor' => $c['autor']->getKey(), 'registrada_en' => now(), 'tipo_consulta' => 'Enfermedad',
            'motivo_consulta' => 'Consulta en documentación', 'diagnostico' => null, 'motivo_cambio' => 'NO-MOSTRAR']);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'consulta'))->assertOk()
            ->assertJsonPath('data.consulta.version_vigente.numero_version', 3)->assertJsonPath('data.consulta.version_vigente.diagnostico', null)
            ->assertJsonPath('data.antecedentes.tipo_sangre', null)->assertDontSee('NO-MOSTRAR')->assertDontSee('DIAGNOSTICO');
    }

    public function test_no_clinical_record_is_created_by_opening_empty_appointment(): void
    {
        $c = $this->preparar();
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'GET', '/api/citas/'.$c['propia'].'/consulta')->assertOk()
            ->assertJsonPath('data.consulta', null)->assertJsonPath('data.estudios', [])->assertJsonPath('meta.total', 0);
        $this->navegador($c['cookies'], 'GET', '/api/citas/'.$c['propia'].'/consulta/versiones')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_patient_without_visits_has_empty_history_without_inventing_antecedents(): void
    {
        $c = $this->preparar();
        $paciente = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $paciente)->assertOk();
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$paciente->paciente->getKey().'/historia-clinica')->assertOk()
            ->assertJsonPath('data.antecedentes', null)->assertJsonPath('data.consultas', [])->assertJsonPath('meta.total', 0);
    }

    public static function paginados(): array
    {
        return [['historia'], ['consulta'], ['versiones_consulta'], ['versiones_antecedentes']];
    }

    #[DataProvider('paginados')]
    public function test_pagination_is_bounded_stable_and_without_links(string $vista): void
    {
        $c = $this->preparar();
        $response = $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista).'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('links');
        if (str_starts_with($vista, 'versiones')) {
            $response->assertJsonPath('data.0.numero_version', 1)->assertJsonPath('meta.total', 2);
        }
    }

    public static function invalidos(): array
    {
        return [['page=0'], ['page[]=1'], ['per_page=101'], ['per_page=0'], ['per_page=abc'], ['orderBy=diagnostico'],
            ['id_paciente=999'], ['id_objetivo=999'], ['rol=Administrador'], ['include=versiones'], ['page=2147483648']];
    }

    #[DataProvider('invalidos')]
    public function test_unapproved_parameters_never_expand_scope(string $query): void
    {
        $c = $this->preparar();
        foreach (['historia', 'consulta', 'versiones_consulta', 'versiones_antecedentes'] as $vista) {
            $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista).'?'.$query)->assertStatus(422);
        }
    }

    public static function idsInvalidos(): array
    {
        return [['0'], ['-1'], ['abc'], ['01'], ['9223372036854775808']];
    }

    #[DataProvider('idsInvalidos')]
    public function test_id_validation_preserves_bigint_bounds(string $id): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'GET', '/api/pacientes/'.$id.'/historia-clinica')->assertStatus(422);
        $this->navegador($c['cookies'], 'GET', '/api/citas/'.$id.'/consulta')->assertStatus(422);
    }

    public static function metodos(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('metodos')]
    public function test_writing_is_not_enabled_in_this_phase(string $metodo): void
    {
        $c = $this->preparar();
        $antes = $this->snapshot();
        foreach (['historia', 'consulta', 'versiones_consulta', 'versiones_antecedentes'] as $vista) {
            $this->navegador($c['cookies'], $metodo, $this->ruta($c, $vista), ['diagnostico' => 'NO-GUARDAR'])
                ->assertStatus(in_array($metodo, ['POST', 'PATCH'], true) && $vista === 'consulta' ? 422 : 405);
        }
        $this->assertSame($antes, $this->snapshot());
    }

    #[DataProvider('paginados')]
    public function test_head_and_get_need_no_csrf_and_reject_body(string $vista): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'HEAD', $this->ruta($c, $vista), csrf: false)->assertOk()->assertContent('');
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista), csrf: false)->assertOk();
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, $vista), ['diagnostico' => 'NO-GUARDAR'])->assertStatus(422);
    }

    public static function revocaciones(): array
    {
        return [['cuenta'], ['sesion'], ['restringida']];
    }

    #[DataProvider('revocaciones')]
    public function test_account_and_session_controls_remain_authoritative(string $tipo): void
    {
        $c = $this->preparar();
        match ($tipo) {
            'cuenta' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']),
            'sesion' => DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete(),
            'restringida' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['cambio_clave_obligatorio' => true]),
        };
        // Alterar la marca sin una credencial/sesión temporal legítima invalida la sesión anterior.
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertUnauthorized();
    }

    public function test_legitimate_restricted_session_is_denied_before_clinical_persistence(): void
    {
        $c = $this->preparar();
        $target = $this->usuarioAuth('Médico');
        $temporal = $this->navegador($c['cookies'], 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $temporal)->assertOk();
        foreach (['historia', 'consulta', 'versiones_consulta', 'versiones_antecedentes'] as $vista) {
            $this->navegador($restricted, 'GET', $this->ruta($c, $vista))->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        }
    }

    public function test_safe_server_error_never_exposes_clinical_values_or_sql(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(HistoriaClinicaRepository::class)->makePartial();
        $repo->shouldReceive('antecedentes')->once()->andThrow(new RuntimeException('SQL DIAGNOSTICO-SECRETO /ruta/privada'));
        $this->app->instance(HistoriaClinicaRepository::class, $repo);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertStatus(500)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('DIAGNOSTICO')->assertDontSee('SQL')->assertDontSee('/ruta');
    }

    public function test_relation_is_revalidated_before_returning_text(): void
    {
        $c = $this->preparar('Médico');
        $repo = Mockery::mock(HistoriaClinicaRepository::class)->makePartial();
        $repo->shouldReceive('antecedentes')->once()->andReturnUsing(function ($id, $interno) use ($c) {
            $version = (new HistoriaClinicaRepository)->antecedentes($id, $interno);
            DB::table('citas')->where('id_cita', $c['propia'])->update(['estado' => 'Cancelada']);

            return $version;
        });
        $this->app->instance(HistoriaClinicaRepository::class, $repo);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertNotFound()->assertDontSee('ALERGIA-VIGENTE');
    }

    public function test_antecedent_from_another_patient_is_not_exposed_or_replaced_by_older_snapshot(): void
    {
        $c = $this->preparar('Paciente');
        $otra = $this->usuarioAuth('Paciente');
        $r = $c['r'];
        $r['patient'] = $otra->paciente->getKey();
        $id = DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => '2026-12-01T12:00:00Z', 'fin_at' => '2026-12-01T12:15:00Z']), 'id_cita');
        $origen = DB::table('consultas_medicas')->insertGetId(['id_cita' => $id, 'codigo_consulta' => 'OTRA-FICTICIA'], 'id_consulta');
        DB::table('versiones_antecedente')->insert(['id_paciente' => $c['r']['patient'], 'id_consulta_origen' => $origen,
            'id_autor' => $c['autor']->getKey(), 'numero_version' => 3, 'registrada_en' => now(), 'alergias' => 'PACIENTE-AJENO']);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertOk()
            ->assertJsonPath('data.antecedentes', null)->assertDontSee('PACIENTE-AJENO')->assertDontSee('ALERGIA-VIGENTE');
    }

    private function laboratorio(array $c, string $estado, string $decision, bool $posterior, bool $otroMedico): void
    {
        $id = DB::table('solicitudes_laboratorio')->insertGetId(['id_consulta' => $c['consulta'],
            'estudio_solicitado' => 'ESTUDIO-FICTICIO', 'solicitada_en' => now()], 'id_solicitud');
        if ($estado === 'Pendiente') {
            return;
        }
        $doc = DB::table('documentos_laboratorio')->insertGetId(['cargado_por' => $c['autor']->getKey(),
            'ruta_privada' => 'NO-EXPONER/documento.pdf', 'nombre_original' => 'NO-EXPONER.pdf', 'tipo_mime' => 'application/pdf',
            'tamano_bytes' => 123, 'sha256' => str_repeat('a', 64), 'cargado_en' => now()], 'id_documento');
        $version = DB::table('versiones_resultado')->insertGetId(['id_solicitud' => $id, 'id_documento' => $doc,
            'id_autor' => $c['autor']->getKey(), 'numero_version' => 1, 'registrada_en' => now(), 'motivo_cambio' => 'NO-EXPONER'], 'id_version_resultado');
        if ($posterior) {
            DB::table('versiones_resultado')->insert(['id_solicitud' => $id, 'id_documento' => $doc,
                'id_autor' => $c['autor']->getKey(), 'numero_version' => 2, 'registrada_en' => now()]);
        }
        $revision = null;
        if ($estado === 'Disponible') {
            $revision = DB::table('revisiones_resultado')->insertGetId(['id_version_resultado' => $version,
                'id_medico' => $c['r']['doctors'][$otroMedico ? 0 : 1], 'decision' => $decision,
                'motivo' => 'NO-EXPONER', 'revisada_en' => now()], 'id_revision');
        }
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $id)->update([
            'estado_interno' => $estado, 'id_version_actual' => $version, 'id_revision_publicada' => $revision]);
    }

    public static function laboratorios(): array
    {
        $casos = [];
        foreach (['Administrador', 'Médico', 'Paciente'] as $rol) {
            foreach ([['Pendiente', 'Autorizar', false, false], ['En revisión', 'Autorizar', false, false],
                ['Disponible', 'Autorizar', false, false], ['Disponible', 'Devolver', false, false],
                ['Disponible', 'Autorizar', true, false], ['Disponible', 'Autorizar', false, true]] as $datos) {
                $casos[] = [$rol, ...$datos];
            }
        }

        return $casos;
    }

    #[DataProvider('laboratorios')]
    public function test_laboratory_is_metadata_only_and_patient_publication_is_checked(string $rol, string $estado, string $decision, bool $posterior, bool $otroMedico): void
    {
        $c = $this->preparar($rol);
        $this->laboratorio($c, $estado, $decision, $posterior, $otroMedico);
        $antes = $this->snapshot();
        $publicado = $estado === 'Disponible' && $decision === 'Autorizar' && ! $posterior && ! $otroMedico;
        $response = $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'consulta'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.estudios.0.estudio_solicitado', 'ESTUDIO-FICTICIO')
            ->assertJsonPath('data.estudios.0.estado', $rol === 'Paciente' ? ($publicado ? 'Disponible' : 'Pendiente') : $estado)
            ->assertDontSee('NO-EXPONER')->assertDontSee('sha256')->assertDontSee('id_documento')->assertDontSee('id_revision');
        if ($publicado) {
            $response->assertJsonPath('data.estudios.0.resultado.numero_version', 1);
        } else {
            $response->assertJsonPath('data.estudios.0.resultado', null);
        }
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_bigint_and_microseconds_are_preserved_in_clinical_detail(): void
    {
        $c = $this->preparar();
        DB::table('consultas_medicas')->insert(['id_consulta' => '9007199254740993', 'id_cita' => $c['propia'],
            'codigo_consulta' => 'CON-GRANDE', 'creada_en' => '2026-11-01T12:00:00.123456Z']);
        DB::table('versiones_consulta')->insert(['id_version_consulta' => '9007199254740995', 'id_consulta' => '9007199254740993',
            'id_autor' => $c['autor']->getKey(), 'numero_version' => 2147483647, 'registrada_en' => '2026-11-01T12:00:00.123457Z',
            'tipo_consulta' => 'Enfermedad', 'motivo_consulta' => 'Dato ficticio']);
        $this->navegador($c['cookies'], 'GET', '/api/citas/'.$c['propia'].'/consulta')->assertOk()
            ->assertJsonPath('data.consulta.id_consulta', '9007199254740993')
            ->assertJsonPath('data.consulta.version_vigente.id_version_consulta', '9007199254740995')
            ->assertJsonPath('data.consulta.version_vigente.numero_version', 2147483647)
            ->assertJsonPath('data.consulta.version_vigente.registrada_en', '2026-11-01T12:00:00.123457Z');
    }

    public function test_pagination_orders_consultations_by_appointment_then_id_not_registration_time(): void
    {
        $c = $this->preparar();
        $nueva = DB::table('consultas_medicas')->insertGetId(['id_cita' => $c['propia'],
            'codigo_consulta' => 'CON-MAS-RECIENTE', 'creada_en' => '2026-01-01T00:00:00Z'], 'id_consulta');
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia').'?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.consultas.0.id_consulta', (string) $nueva);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia').'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.consultas.0.id_consulta', $c['consulta']);
    }

    public function test_revoked_account_after_read_does_not_return_clinical_content(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(HistoriaClinicaRepository::class)->makePartial();
        $repo->shouldReceive('antecedentes')->once()->andReturnUsing(function ($id, $interno) use ($c) {
            $version = (new HistoriaClinicaRepository)->antecedentes($id, $interno);
            DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']);

            return $version;
        });
        $this->app->instance(HistoriaClinicaRepository::class, $repo);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertUnauthorized()->assertDontSee('ALERGIA');
    }

    public function test_dual_medical_patient_identity_is_rejected(): void
    {
        $c = $this->preparar('Médico');
        DB::table('pacientes')->insert(['id_usuario' => $c['actor']->getKey(), 'codigo_paciente' => 'DUAL-FICTICIO',
            'nombres' => 'Ficticio', 'apellidos' => 'Dual', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000']);
        $this->navegador($c['cookies'], 'GET', $this->ruta($c, 'historia'))->assertForbidden()->assertDontSee('ALERGIA');
    }
}
