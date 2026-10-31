<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\historia_clinica\ConsultaMedicaService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class RegistrarConsultaTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_alta_clinica_'.strtolower(Str::random(16));
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
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado]), 'id_cita');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $uri = '/api/citas/'.$id.'/consulta';
        $originales = ['version_programacion' => 1, 'actualizada_en' => CarbonImmutable::parse(DB::table('citas')->where('id_cita', $id)->value('actualizada_en'))
            ->utc()->format('Y-m-d\TH:i:s.u\Z'), 'id_consulta' => null, 'id_version_consulta' => null, 'id_version_antecedente' => null];
        $datos = ['originales' => $originales, 'atencion_real_verificada' => true,
            'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'MOTIVO-FICTICIO-PRIVADO',
                'diagnostico' => 'DIAGNOSTICO-FICTICIO-PRIVADO']];

        return compact('r', 'actor', 'id', 'cookies', 'uri', 'datos');
    }

    private function snapshot(array $tablas = []): array
    {
        $datos = [];
        foreach ($tablas ?: ['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita',
            'recordatorios', 'bitacora'] as $tabla) {
            $datos[$tabla] = DB::table($tabla)->get()->toJson();
        }

        return $datos;
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
    public function test_real_attention_creates_one_consultation_and_first_snapshot_without_changing_appointment(string $rol, string $estado): void
    {
        $c = $this->preparar($rol, $estado);
        DB::table('recordatorios')->insert(['id_cita' => $c['id'], 'version_programacion' => 1,
            'generado_en' => now()->subHour(), 'leido_en' => now()->subMinute()]);
        $antes = $this->snapshot(['citas', 'cambios_cita', 'recordatorios']);
        $get = $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->assertJsonPath('data.consulta', null)
            ->assertJsonPath('data.edicion.acciones.registrar', true)->assertJsonPath('data.edicion.acciones.actualizar', false);
        $c['datos']['originales'] = $get->json('data.edicion.originales');
        $response = $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.cita.estado', $estado)
            ->assertJsonPath('data.consulta.iniciada_en', null)->assertJsonPath('data.consulta.creada_en', '2026-11-01T12:00:00.000000Z')
            ->assertJsonPath('data.consulta.version_vigente.numero_version', 1)->assertJsonPath('data.consulta.version_vigente.motivo_cambio', null)
            ->assertJsonPath('data.consulta.version_vigente.id_autor', (string) $c['actor']->getKey())
            ->assertJsonPath('data.edicion.acciones.registrar', false)->assertJsonPath('data.edicion.acciones.actualizar', true);
        $this->assertSame($antes, $this->snapshot(['citas', 'cambios_cita', 'recordatorios']));
        $this->assertDatabaseCount('consultas_medicas', 1);
        $this->assertDatabaseCount('versiones_consulta', 1);
        $this->assertDatabaseCount('versiones_antecedente', 0);
        $this->assertMatchesRegularExpression('/^CON-[a-f0-9-]{36}$/', $response->json('data.consulta.codigo_consulta'));
        $evento = Bitacora::query()->where('tipo_evento', 'CONSULTA_REGISTRADA')->sole();
        $this->assertSame((string) $c['actor']->getKey(), (string) $evento->id_usuario_responsable);
        $this->assertSame((string) $c['r']['doctors'][0], $evento->referencias->id_medico);
        foreach (['MOTIVO-FICTICIO-PRIVADO', 'DIAGNOSTICO-FICTICIO-PRIVADO', 'atencion_real_verificada'] as $privado) {
            $this->assertStringNotContainsString($privado, DB::table('bitacora')->get()->toJson());
        }
        $read = $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk();
        $this->assertSame($response->json('data.consulta'), $read->json('data.consulta'));
        Mail::assertNothingSent();
    }

    public static function tiempos(): array
    {
        return [['2026-11-01T11:59:59.999999Z', 409], ['2026-11-01T12:00:00.000000Z', 201],
            ['2026-11-01T12:00:00.000001Z', 201], ['2026-11-01T12:15:00.000000Z', 201], ['2026-11-02T12:00:00Z', 201]];
    }

    #[DataProvider('tiempos')]
    public function test_server_time_allows_registration_from_exact_start_without_same_day_or_end_requirement(string $hora, int $status): void
    {
        $c = $this->preparar();
        CarbonImmutable::setTestNow($hora);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus($status);
        $this->assertDatabaseCount('consultas_medicas', $status === 201 ? 1 : 0);
    }

    public static function estadosInvalidos(): array
    {
        return [['Administrador', 'Cancelada'], ['Administrador', 'Inasistencia'], ['Médico', 'Cancelada'], ['Médico', 'Inasistencia']];
    }

    #[DataProvider('estadosInvalidos')]
    public function test_canceled_and_absent_appointments_reject_registration(string $rol, string $estado): void
    {
        $c = $this->preparar($rol, $estado);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_CITA_NO_PERMITIDA');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function declaraciones(): array
    {
        return [[false], [null], [0], [1], ['true'], ['1'], [[]], [['true']], ['omitida']];
    }

    #[DataProvider('declaraciones')]
    public function test_declaration_is_required_and_strictly_true(mixed $valor): void
    {
        $c = $this->preparar();
        if ($valor === 'omitida') {
            unset($c['datos']['atencion_real_verificada']);
        } else {
            $c['datos']['atencion_real_verificada'] = $valor;
        }
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertUnprocessable();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public static function entradasInvalidas(): array
    {
        return [['id_paciente', '999'], ['id_medico', '999'], ['id_autor', '999'], ['estado', 'Atendida'], ['iniciada_en', '2026-11-01'],
            ['motivo_cambio', 'No autorizado en alta'], ['originales.id_autor', '1'], ['originales.actualizada_en', '2026-11-01T12:00:00Z'],
            ['originales.version_programacion', 0], ['originales.version_programacion', 2147483648], ['originales.id_consulta', '01'],
            ['originales.id_version_consulta', '9223372036854775808'], ['consulta.id_autor', '1'], ['consulta.numero_version', 2],
            ['consulta.tipo_consulta', 'Otro'], ['consulta.motivo_consulta', null], ['consulta.motivo_consulta', '   '],
            ['consulta.motivo_consulta', 123], ['consulta.motivo_consulta', str_repeat('x', 4001)],
            ['consulta.diagnostico', str_repeat('x', 10001)], ['consulta.sintomas', ['no']],
            ['antecedentes.tipo_sangre', str_repeat('x', 17)], ['antecedentes.alergias', str_repeat('x', 10001)],
            ['antecedentes.id_paciente', '999'], ['antecedentes', null], ['consulta', null], ['originales', null]];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_input_is_closed_and_clinical_limits_are_not_silently_truncated(string $campo, mixed $valor): void
    {
        $c = $this->preparar();
        Arr::set($c['datos'], $campo, $valor);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertUnprocessable();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public static function originalesRequeridos(): array
    {
        return [['version_programacion'], ['actualizada_en'], ['id_consulta'], ['id_version_consulta'], ['id_version_antecedente']];
    }

    #[DataProvider('originalesRequeridos')]
    public function test_all_originals_must_be_explicit_including_nulls(string $campo): void
    {
        $c = $this->preparar();
        unset($c['datos']['originales'][$campo]);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertUnprocessable();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    #[DataProvider('originalesRequeridos')]
    public function test_stale_originals_reject_without_partial_writes(string $campo): void
    {
        $c = $this->preparar();
        $c['datos']['originales'][$campo] = $campo === 'actualizada_en' ? '2020-01-01T00:00:00.000000Z' : '999';
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_repeating_post_with_old_or_current_originals_never_creates_another_consultation(): void
    {
        $c = $this->preparar();
        $post = $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated();
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $c['datos']['originales'] = $post->json('data.edicion.originales');
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_CONSULTA_EXISTENTE');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function rolesProhibidos(): array
    {
        return [['Recepcionista'], ['Paciente']];
    }

    #[DataProvider('rolesProhibidos')]
    public function test_reception_and_patient_cannot_write_even_own_appointment(string $rol): void
    {
        $c = $this->preparar($rol);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertForbidden();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public function test_medical_read_relation_is_not_permission_to_write_another_doctors_appointment(): void
    {
        $c = $this->preparar('Médico');
        $otro = (string) DB::table('citas')->insertGetId($this->citaRow($c['r'], ['id_medico' => $c['r']['doctors'][1],
            'id_consultorio' => $c['r']['rooms'][1], 'inicio_at' => '2026-10-01T12:00:00Z', 'fin_at' => '2026-10-01T12:15:00Z']), 'id_cita');
        $this->navegador($c['cookies'], 'GET', '/api/citas/'.$otro.'/consulta')->assertOk()->assertJsonMissingPath('data.edicion');
        $foreign = $this->navegador($c['cookies'], 'POST', '/api/citas/'.$otro.'/consulta', $c['datos'])->assertNotFound();
        $absent = $this->navegador($c['cookies'], 'POST', '/api/citas/999999/consulta', $c['datos'])->assertNotFound();
        $this->assertSame($foreign->json(), $absent->json());
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public function test_initial_antecedents_are_atomic_with_consultation_and_audited_without_content(): void
    {
        $c = $this->preparar();
        $c['datos']['antecedentes'] = ['tipo_sangre' => null, 'alergias' => 'ALERGIA-FICTICIA-PRIVADA'];
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated()
            ->assertJsonPath('data.antecedentes.alergias', 'ALERGIA-FICTICIA-PRIVADA')->assertJsonPath('data.antecedentes.numero_version', 1)
            ->assertJsonPath('data.antecedentes.tipo_sangre', null)->assertJsonPath('data.antecedentes.motivo_cambio', null);
        $this->assertDatabaseCount('versiones_antecedente', 1);
        $evento = Bitacora::query()->where('tipo_evento', 'ANTECEDENTES_ACTUALIZADOS')->sole();
        $this->assertSame(1, $evento->cambios_seguros->version_nueva);
        $this->assertStringNotContainsString('ALERGIA-FICTICIA-PRIVADA', DB::table('bitacora')->get()->toJson());
        foreach (['Paciente', 'Medico', 'Cita', 'ConsultaMedica', 'VersionAntecedente'] as $entidad) {
            $id = match ($entidad) {
                'Paciente' => $c['r']['patient'], 'Medico' => $c['r']['doctors'][0], 'Cita' => $c['id'],
                'ConsultaMedica' => $evento->referencias->id_consulta, default => $evento->referencias->id_version_antecedente,
            };
            $this->navegador($c['cookies'], 'GET', '/api/bitacora?entidad_referida='.$entidad.'&registro_referido='.$id)
                ->assertOk()->assertJsonPath('meta.total', $entidad === 'VersionAntecedente' ? 1 : 2);
        }
    }

    public function test_unknown_antecedents_do_not_invent_a_version(): void
    {
        $c = $this->preparar();
        $c['datos']['antecedentes'] = ['alergias' => null, 'tipo_sangre' => null];
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated()->assertJsonPath('data.antecedentes', null);
        $this->assertDatabaseCount('versiones_antecedente', 0);
    }

    private function antecedentePrevio(array $c, int $numero = 1): string
    {
        $cita = DB::table('citas')->insertGetId($this->citaRow($c['r'], ['inicio_at' => '2026-10-01T12:00:00Z',
            'fin_at' => '2026-10-01T12:15:00Z', 'estado' => 'Atendida']), 'id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['id_cita' => $cita, 'codigo_consulta' => 'ANTERIOR-FICTICIA'], 'id_consulta');

        return (string) DB::table('versiones_antecedente')->insertGetId(['id_paciente' => $c['r']['patient'],
            'id_consulta_origen' => $consulta, 'numero_version' => $numero, 'id_autor' => $c['actor']->getKey(),
            'registrada_en' => now(), 'alergias' => 'ANTECEDENTE-PREVIO', 'medicamentos' => 'MEDICAMENTO-PREVIO'], 'id_version_antecedente');
    }

    public static function cambiosAntecedentes(): array
    {
        return [['omitido', false], ['igual', false], ['nuevo', true], ['borrar', true]];
    }

    #[DataProvider('cambiosAntecedentes')]
    public function test_prior_antecedents_are_preserved_and_only_effective_changes_append_snapshot(string $modo, bool $cambia): void
    {
        $c = $this->preparar();
        $anterior = $this->antecedentePrevio($c);
        $previo = DB::table('versiones_antecedente')->get()->toJson();
        $c['datos']['originales']['id_version_antecedente'] = $anterior;
        if ($modo !== 'omitido') {
            $c['datos']['antecedentes'] = ['alergias' => match ($modo) {
                'igual' => 'ANTECEDENTE-PREVIO', 'nuevo' => 'ANTECEDENTE-NUEVO', default => null,
            }];
        }
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated()
            ->assertJsonPath('data.antecedentes.numero_version', $cambia ? 2 : 1)
            ->assertJsonPath('data.antecedentes.medicamentos', 'MEDICAMENTO-PREVIO');
        $this->assertSame($previo, DB::table('versiones_antecedente')->where('id_version_antecedente', $anterior)->get()->toJson());
        $this->assertDatabaseCount('versiones_antecedente', $cambia ? 2 : 1);
    }

    public function test_changed_antecedents_from_another_attention_require_review_again(): void
    {
        $c = $this->preparar();
        $this->antecedentePrevio($c);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_version_limit_rejects_effective_antecedent_change_before_creating_consultation(): void
    {
        $c = $this->preparar();
        $c['datos']['originales']['id_version_antecedente'] = $this->antecedentePrevio($c, 2147483647);
        $c['datos']['antecedentes'] = ['alergias' => 'NUEVA'];
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_LIMITE_VERSION');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function fallos(): array
    {
        return [['crear'], ['agregarVersion'], ['agregarAntecedentes'], ['consultaRegistrada'], ['antecedentesActualizados'], ['estudios']];
    }

    #[DataProvider('fallos')]
    public function test_failures_after_real_writes_or_final_read_roll_back_everything(string $metodo): void
    {
        $c = $this->preparar();
        $c['datos']['antecedentes'] = ['alergias' => 'PRIVADO'];
        $clase = in_array($metodo, ['consultaRegistrada', 'antecedentesActualizados'], true) ? BitacoraRepository::class
            : ($metodo === 'estudios' ? HistoriaClinicaRepository::class : ConsultaMedicaRepository::class);
        $real = new $clase;
        $repo = Mockery::mock($clase)->makePartial();
        $repo->shouldReceive($metodo)->once()->andReturnUsing(function (...$args) use ($real, $metodo) {
            $real->{$metodo}(...$args);
            throw new RuntimeException('SQL CLINICA-SECRETA /ruta/privada');
        });
        $this->app->instance($clase, $repo);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertStatus(500)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('CLINICA-SECRETA')->assertDontSee('SQL')->assertDontSee('/ruta');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function seguridad(): array
    {
        return [['anonimo', 401], ['cuenta', 401], ['sesion', 419], ['sesion_csrf_nuevo', 401], ['csrf', 419], ['origen', 403], ['query', 422]];
    }

    #[DataProvider('seguridad')]
    public function test_session_origin_and_csrf_are_required(string $modo, int $status): void
    {
        $c = $this->preparar();
        if ($modo === 'anonimo') {
            $c['cookies'] = $this->csrfAuth();
        } elseif ($modo === 'cuenta') {
            DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']);
        } elseif (in_array($modo, ['sesion', 'sesion_csrf_nuevo'], true)) {
            DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();
            if ($modo === 'sesion_csrf_nuevo') {
                $c['cookies'] = $this->csrfAuth();
            }
        }
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'].($modo === 'query' ? '?page=1' : ''), $c['datos'],
            headers: $modo === 'origen' ? ['Origin' => 'https://ajeno.example.test'] : [], csrf: $modo !== 'csrf')->assertStatus($status);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_restricted_session_cannot_create_a_consultation(): void
    {
        $c = $this->preparar();
        $target = $this->usuarioAuth('Médico');
        $temporal = $this->navegador($c['cookies'], 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $target, $temporal)->assertOk();
        $this->navegador($cookies, 'POST', $c['uri'], $c['datos'])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public function test_service_itself_rejects_patient_and_reception(): void
    {
        $c = $this->preparar('Recepcionista');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No tienes permiso');
        $this->app->make(ConsultaMedicaService::class)->registrar($c['id'], $c['datos'], []);
    }

    public function test_service_itself_requires_strict_declaration(): void
    {
        $c = $this->preparar();
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Comprueba que la atención real');
        $this->app->make(ConsultaMedicaService::class)->registrar($c['id'], [...$c['datos'], 'atencion_real_verificada' => 1], []);
    }

    public function test_dual_medical_identity_is_rejected(): void
    {
        $c = $this->preparar('Médico');
        DB::table('pacientes')->insert(['id_usuario' => $c['actor']->getKey(), 'codigo_paciente' => 'DUAL-FICTICIO',
            'nombres' => 'Ficticio', 'apellidos' => 'Dual', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000']);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertForbidden();
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public function test_professional_patient_and_catalog_inactivity_do_not_erase_existing_assignment(): void
    {
        $c = $this->preparar('Médico');
        DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['activo' => false]);
        DB::table('especialidades')->where('id_especialidad', $c['r']['specialty'])->update(['activa' => false]);
        DB::table('consultorios')->where('id_consultorio', $c['r']['rooms'][0])->update(['activo' => false]);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated();
    }

    public static function revocacionesTrasEspera(): array
    {
        return [['cuenta'], ['rol'], ['sesion'], ['vinculo']];
    }

    #[DataProvider('revocacionesTrasEspera')]
    public function test_security_is_revalidated_after_coordination(string $modo): void
    {
        $c = $this->preparar('Médico');
        $rol = $this->usuarioAuth('Recepcionista')->id_rol;
        $plan = Mockery::mock(PlanificacionRepository::class)->makePartial();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($c, $modo, $rol): void {
            match ($modo) {
                'cuenta' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']),
                'rol' => DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['id_rol' => $rol]),
                'sesion' => DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete(),
                'vinculo' => DB::table('medicos')->where('id_medico', $c['r']['doctors'][0])->update(['id_usuario' => null]),
            };
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertUnauthorized();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_scope_is_applied_again_under_appointment_lock(): void
    {
        $c = $this->preparar('Médico');
        $medico = (string) $c['r']['doctors'][0];
        $repo = Mockery::mock(CitaRepository::class)->makePartial();
        $repo->shouldReceive('porIdParaAsistencia')->with($c['id'], $medico, true)->once()->andReturnUsing(function () use ($c, $medico) {
            DB::table('citas')->where('id_cita', $c['id'])->update(['id_medico' => $c['r']['doctors'][1]]);

            return (new CitaRepository)->porIdParaAsistencia($c['id'], $medico, true);
        });
        $this->app->instance(CitaRepository::class, $repo);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertNotFound();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_clock_is_fresh_after_last_revalidation(): void
    {
        $c = $this->preparar();
        $repo = Mockery::mock(UsuarioRepository::class)->makePartial();
        $reads = 0;
        $repo->shouldReceive('porId')->with($c['actor']->getKey(), true)->andReturnUsing(function ($id) use (&$reads) {
            if (++$reads === 3) {
                CarbonImmutable::setTestNow('2026-11-01T11:59:59.999999Z');
            }

            return (new UsuarioRepository)->porId($id, true);
        });
        $this->app->instance(UsuarioRepository::class, $repo);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_ATENCION_ANTICIPADA');
        $this->assertSame(3, $reads);
        $this->assertDatabaseCount('consultas_medicas', 0);
    }

    public function test_patient_reads_new_incomplete_current_version_without_edit_context_or_audit(): void
    {
        $c = $this->preparar();
        $paciente = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['id_usuario' => $paciente->getKey()]);
        unset($c['datos']['consulta']['diagnostico']);
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $paciente)->assertOk();
        $this->navegador($cookies, 'GET', $c['uri'])->assertOk()->assertJsonPath('data.consulta.version_vigente.diagnostico', null)
            ->assertJsonPath('data.consulta.version_vigente.motivo_consulta', 'MOTIVO-FICTICIO-PRIVADO')
            ->assertJsonMissingPath('data.edicion')->assertDontSee('id_autor')->assertDontSee('motivo_cambio');
    }

    public function test_consultation_blocks_later_arrival_and_absence_but_cu19_remains_independent(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'POST', $c['uri'], $c['datos'])->assertCreated();
        $datos = ['version_programacion_original' => 1, 'actualizada_en_original' => $c['datos']['originales']['actualizada_en']];
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$c['id'].'/llegada', [...$datos, 'presencia_verificada' => true])
            ->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        CarbonImmutable::setTestNow('2026-11-01T12:15:00Z');
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$c['id'].'/inasistencia', [...$datos, 'ausencia_verificada' => true])
            ->assertConflict()->assertJsonPath('error.code', 'CITA_CON_ATENCION_DOCUMENTADA');
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$c['id'].'/atencion', [...$datos, 'atencion_realizada' => true])
            ->assertOk()->assertJsonPath('data.estado', 'Atendida');
        $this->assertDatabaseCount('consultas_medicas', 1);
        $this->assertDatabaseCount('versiones_consulta', 1);
    }

    public function test_bigint_ids_and_microsecond_originals_are_not_rounded(): void
    {
        $c = $this->preparar();
        DB::table('citas')->where('id_cita', $c['id'])->update(['id_cita' => '9007199254740993',
            'actualizada_en' => '2026-11-01T11:00:00.123456Z', 'version_programacion' => 2147483647]);
        $uri = '/api/citas/9007199254740993/consulta';
        $c['datos']['originales'] = $this->navegador($c['cookies'], 'GET', $uri)->assertOk()->json('data.edicion.originales');
        $this->navegador($c['cookies'], 'POST', $uri, $c['datos'])->assertCreated()->assertJsonPath('data.cita.id_cita', '9007199254740993')
            ->assertJsonPath('data.cita.actualizada_en', '2026-11-01T11:00:00.123456Z')->assertJsonPath('data.cita.version_programacion', 2147483647);
    }

    public function test_audit_projection_rejects_injected_clinical_values(): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['id_evento' => 1, 'tipo_evento' => 'CONSULTA_REGISTRADA', 'version_formato' => 1,
            'ocurrido_en' => now(), 'registrado_en' => now(), 'entidad_referida' => 'ConsultaMedica', 'registro_referido' => '1',
            'referencias' => ['id_consulta' => '1', 'id_medico' => '-1', 'diagnostico' => 'SECRETO'],
            'cambios_seguros' => ['version_anterior' => 0, 'version_nueva' => 1, 'diagnostico' => 'SECRETO', 'motivo_cambio' => 'SECRETO'],
            'metadatos_seguros' => ['alergias' => 'SECRETO']]);
        $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
        $this->assertSame('1', $salida['referencias']->id_consulta);
        $this->assertObjectNotHasProperty('id_medico', $salida['referencias']);
        $this->assertSame(1, $salida['cambios_seguros']->version_nueva);
        $this->assertStringNotContainsString('SECRETO', json_encode($salida));
    }
}
