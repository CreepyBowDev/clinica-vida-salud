<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\historia_clinica\ConsultaMedicaService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
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

class ActualizarConsultaTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'prueba_correccion_'.strtolower(Str::random(16));
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
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['estado' => $estado]), 'id_cita');
        $uri = '/api/citas/'.$id.'/consulta';
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $get = $this->navegador($cookies, 'GET', $uri)->assertOk();
        $post = $this->navegador($cookies, 'POST', $uri, ['originales' => $get->json('data.edicion.originales'),
            'atencion_real_verificada' => true, 'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'MOTIVO-ORIGINAL',
                'diagnostico' => 'DIAGNOSTICO-ORIGINAL'], 'antecedentes' => ['alergias' => 'ALERGIA-ORIGINAL', 'medicamentos' => 'MEDICAMENTO-ORIGINAL']])->assertCreated();
        $datos = ['originales' => $post->json('data.edicion.originales'), 'atencion_real_verificada' => true,
            'consulta' => ['diagnostico' => 'DIAGNOSTICO-CORREGIDO'], 'motivo_cambio' => 'MOTIVO-CORRECCION-PRIVADO'];

        return compact('r', 'actor', 'id', 'uri', 'cookies', 'datos', 'post');
    }

    private function snapshot(array $tablas = []): array
    {
        $salida = [];
        foreach ($tablas ?: ['citas', 'consultas_medicas', 'versiones_consulta', 'versiones_antecedente', 'cambios_cita', 'recordatorios', 'bitacora'] as $tabla) {
            $salida[$tabla] = DB::table($tabla)->orderByRaw('1')->get()->toJson();
        }

        return $salida;
    }

    public static function correcciones(): array
    {
        $casos = [];
        foreach (['Administrador', 'Médico'] as $rol) {
            foreach (['Programada', 'Confirmada', 'Atendida'] as $estado) {
                foreach (['consulta', 'antecedentes', 'ambos'] as $bloques) {
                    $casos[] = [$rol, $estado, $bloques];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('correcciones')]
    public function test_corrections_append_only_changed_snapshots_and_preserve_identity_and_appointment(string $rol, string $estado, string $bloques): void
    {
        $c = $this->preparar($rol, $estado);
        DB::table('recordatorios')->insert(['id_cita' => $c['id'], 'version_programacion' => 1, 'generado_en' => now(), 'leido_en' => now()]);
        $antes = $this->snapshot(['citas', 'consultas_medicas', 'cambios_cita', 'recordatorios']);
        $versionAnterior = DB::table('versiones_consulta')->get()->toJson();
        $antecedenteAnterior = DB::table('versiones_antecedente')->get()->toJson();
        if ($bloques === 'antecedentes') {
            unset($c['datos']['consulta']);
        }
        if ($bloques !== 'consulta') {
            $c['datos']['antecedentes'] = ['alergias' => 'ALERGIA-CORREGIDA', 'tipo_sangre' => null];
        }
        CarbonImmutable::setTestNow('2026-11-02T12:00:00.123456Z');
        $response = $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.modificado', true)
            ->assertJsonPath('data.consulta.id_consulta', $c['post']->json('data.consulta.id_consulta'))
            ->assertJsonPath('data.consulta.version_vigente.numero_version', $bloques === 'antecedentes' ? 1 : 2)
            ->assertJsonPath('data.antecedentes.numero_version', $bloques === 'consulta' ? 1 : 2)
            ->assertJsonPath('data.antecedentes.medicamentos', 'MEDICAMENTO-ORIGINAL');
        $this->assertSame($antes, $this->snapshot(['citas', 'consultas_medicas', 'cambios_cita', 'recordatorios']));
        $this->assertSame($versionAnterior, DB::table('versiones_consulta')->where('numero_version', 1)->get()->toJson());
        $this->assertSame($antecedenteAnterior, DB::table('versiones_antecedente')->where('numero_version', 1)->get()->toJson());
        if ($bloques !== 'antecedentes') {
            $response->assertJsonPath('data.consulta.version_vigente.id_autor', (string) $c['actor']->getKey())
                ->assertJsonPath('data.consulta.version_vigente.motivo_cambio', 'MOTIVO-CORRECCION-PRIVADO')
                ->assertJsonPath('data.consulta.version_vigente.registrada_en', '2026-11-02T12:00:00.123456Z');
        }
        $eventos = DB::table('bitacora')->where('tipo_evento', 'CONSULTA_ACTUALIZADA')->get();
        $this->assertCount($bloques === 'antecedentes' ? 0 : 1, $eventos);
        $this->assertSame($bloques === 'consulta' ? 1 : 2, DB::table('bitacora')->where('tipo_evento', 'ANTECEDENTES_ACTUALIZADOS')->count());
        foreach (['DIAGNOSTICO-CORREGIDO', 'ALERGIA-CORREGIDA', 'MOTIVO-CORRECCION-PRIVADO'] as $privado) {
            $this->assertStringNotContainsString($privado, DB::table('bitacora')->get()->toJson());
        }
        $this->navegador($c['cookies'], 'GET', $c['uri'].'/versiones')->assertOk()->assertJsonPath('meta.total', $bloques === 'antecedentes' ? 1 : 2);
        Mail::assertNothingSent();
    }

    public static function sinCambios(): array
    {
        return [['omitidos'], ['iguales'], ['vacios'], ['motivo_solo']];
    }

    #[DataProvider('sinCambios')]
    public function test_valid_noop_requires_no_reason_and_writes_no_version_or_audit(string $modo): void
    {
        $c = $this->preparar();
        unset($c['datos']['consulta'], $c['datos']['motivo_cambio']);
        if ($modo === 'iguales') {
            $c['datos']['consulta'] = ['diagnostico' => 'DIAGNOSTICO-ORIGINAL'];
            $c['datos']['antecedentes'] = ['alergias' => 'ALERGIA-ORIGINAL', 'tipo_sangre' => null];
        } elseif ($modo === 'vacios') {
            $c['datos']['consulta'] = [];
            $c['datos']['antecedentes'] = [];
        } elseif ($modo === 'motivo_solo') {
            $c['datos']['motivo_cambio'] = 'No cambia contenido';
        }
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk()->assertJsonPath('data.modificado', false);
        $this->assertSame($antes, $this->snapshot());
    }

    public static function motivos(): array
    {
        return [['omitido'], [null], [''], ['   '], [str_repeat('x', 2001)], [123], [['texto']]];
    }

    #[DataProvider('motivos')]
    public function test_effective_changes_require_safe_nonempty_reason(mixed $motivo): void
    {
        $c = $this->preparar();
        if ($motivo === 'omitido') {
            unset($c['datos']['motivo_cambio']);
        } else {
            $c['datos']['motivo_cambio'] = $motivo;
        }
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertUnprocessable();
        $this->assertSame($antes, $this->snapshot());
    }

    public static function invalidos(): array
    {
        return [['originales.id_consulta', null], ['originales.id_version_consulta', null], ['originales.id_consulta', '01'],
            ['originales.id_version_antecedente', '9223372036854775808'], ['consulta.tipo_consulta', null], ['consulta.motivo_consulta', null],
            ['consulta.tipo_consulta', 'Otro'], ['consulta.motivo_consulta', str_repeat('x', 4001)], ['consulta.diagnostico', str_repeat('x', 10001)],
            ['consulta.id_autor', '999'], ['consulta.numero_version', 9], ['antecedentes.id_paciente', '999'],
            ['antecedentes.alergias', ['no']], ['antecedentes.tipo_sangre', str_repeat('x', 17)],
            ['consulta', null], ['antecedentes', null], ['iniciada_en', '2026-11-01'], ['id_autor', '999'], ['estado', 'Atendida'],
            ['atencion_real_verificada', false], ['atencion_real_verificada', 'true'], ['atencion_real_verificada', 1]];
    }

    #[DataProvider('invalidos')]
    public function test_patch_is_partial_but_closed_and_strict(string $campo, mixed $valor): void
    {
        $c = $this->preparar();
        Arr::set($c['datos'], $campo, $valor);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertUnprocessable();
        $this->assertSame($antes, $this->snapshot());
    }

    public static function originales(): array
    {
        return [['version_programacion'], ['actualizada_en'], ['id_consulta'], ['id_version_consulta'], ['id_version_antecedente']];
    }

    #[DataProvider('originales')]
    public function test_stale_originals_always_reject_even_when_content_is_identical(string $campo): void
    {
        $c = $this->preparar();
        unset($c['datos']['consulta'], $c['datos']['motivo_cambio']);
        $c['datos']['originales'][$campo] = $campo === 'actualizada_en' ? '2020-01-01T00:00:00.000000Z' : '999';
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_null_clears_optional_content_without_erasing_versions(): void
    {
        $c = $this->preparar();
        $c['datos']['consulta'] = ['diagnostico' => null];
        $c['datos']['antecedentes'] = ['alergias' => null];
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk()->assertJsonPath('data.modificado', true)
            ->assertJsonPath('data.consulta.version_vigente.diagnostico', null)->assertJsonPath('data.consulta.version_vigente.motivo_consulta', 'MOTIVO-ORIGINAL')
            ->assertJsonPath('data.antecedentes.alergias', null)->assertJsonPath('data.antecedentes.medicamentos', 'MEDICAMENTO-ORIGINAL');
        $this->assertDatabaseCount('versiones_consulta', 2);
        $this->assertDatabaseCount('versiones_antecedente', 2);
    }

    public static function limites(): array
    {
        return [['consulta', true], ['antecedentes', true], ['consulta', false], ['antecedentes', false]];
    }

    #[DataProvider('limites')]
    public function test_version_limit_only_rejects_changed_block_and_noop_still_works(string $bloque, bool $cambia): void
    {
        $c = $this->preparar();
        $tabla = $bloque === 'consulta' ? 'versiones_consulta' : 'versiones_antecedente';
        $pk = $bloque === 'consulta' ? 'id_version_consulta' : 'id_version_antecedente';
        $row = (array) DB::table($tabla)->first();
        unset($row[$pk]);
        $row['numero_version'] = 2147483647;
        DB::table($tabla)->insert($row);
        $c['datos']['originales'] = $this->navegador($c['cookies'], 'GET', $c['uri'])->assertOk()->json('data.edicion.originales');
        unset($c['datos']['consulta']);
        if ($cambia) {
            $c['datos'][$bloque] = [$bloque === 'consulta' ? 'diagnostico' : 'alergias' => 'CAMBIO'];
        }
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertStatus($cambia ? 409 : 200);
        $this->assertSame($antes, $this->snapshot());
    }

    public static function fallos(): array
    {
        return [['agregarVersion'], ['agregarAntecedentes'], ['consultaActualizada'], ['antecedentesActualizados'], ['estudios']];
    }

    #[DataProvider('fallos')]
    public function test_failure_after_real_append_or_audit_or_final_read_rolls_back_all_blocks(string $metodo): void
    {
        $c = $this->preparar();
        $c['datos']['antecedentes'] = ['alergias' => 'CORREGIDA'];
        $clase = in_array($metodo, ['consultaActualizada', 'antecedentesActualizados'], true) ? BitacoraRepository::class
            : ($metodo === 'estudios' ? HistoriaClinicaRepository::class : ConsultaMedicaRepository::class);
        $real = new $clase;
        $repo = Mockery::mock($clase)->makePartial();
        $repo->shouldReceive($metodo)->once()->andReturnUsing(function (...$args) use ($real, $metodo) {
            $real->{$metodo}(...$args);
            throw new RuntimeException('SQL DIAGNOSTICO-SECRETO');
        });
        $this->app->instance($clase, $repo);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertStatus(500)->assertDontSee('SQL')->assertDontSee('DIAGNOSTICO-SECRETO');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function estadosProhibidos(): array
    {
        return [['Cancelada'], ['Inasistencia']];
    }

    #[DataProvider('estadosProhibidos')]
    public function test_noop_still_checks_state_and_real_attention(string $estado): void
    {
        $c = $this->preparar();
        DB::table('citas')->where('id_cita', $c['id'])->update(['estado' => $estado]);
        unset($c['datos']['consulta'], $c['datos']['motivo_cambio']);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_CITA_NO_PERMITIDA');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_patient_and_reception_cannot_correct_even_noop(string $rol): void
    {
        $c = $this->preparar();
        $otro = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $otro)->assertOk();
        unset($c['datos']['consulta']);
        $antes = $this->snapshot();
        $this->navegador($cookies, 'PATCH', $c['uri'], $c['datos'])->assertForbidden();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_read_relation_does_not_grant_correction_of_another_professionals_consultation(): void
    {
        $c = $this->preparar();
        $otro = $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $c['r']['doctors'][1])->update(['id_usuario' => $otro->getKey()]);
        DB::table('citas')->insert($this->citaRow($c['r'], ['id_medico' => $c['r']['doctors'][1], 'id_consultorio' => $c['r']['rooms'][1],
            'inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']));
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $otro)->assertOk();
        $this->navegador($cookies, 'GET', $c['uri'])->assertOk()->assertJsonMissingPath('data.edicion');
        $foreign = $this->navegador($cookies, 'PATCH', $c['uri'], $c['datos'])->assertNotFound();
        $absent = $this->navegador($cookies, 'PATCH', '/api/citas/999999/consulta', $c['datos'])->assertNotFound();
        $this->assertSame($foreign->json(), $absent->json());
    }

    public function test_patch_without_existing_consultation_is_404_and_does_not_create_one(): void
    {
        $c = $this->preparar();
        $id = DB::table('citas')->insertGetId($this->citaRow($c['r'], ['inicio_at' => '2026-11-02T12:00:00Z', 'fin_at' => '2026-11-02T12:15:00Z']), 'id_cita');
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', '/api/citas/'.$id.'/consulta', $c['datos'])->assertNotFound();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_repetition_requires_new_review_even_when_payload_matches_latest_content(): void
    {
        $c = $this->preparar();
        $patch = $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk()->assertJsonPath('data.modificado', true);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $c['datos']['originales'] = $patch->json('data.edicion.originales');
        unset($c['datos']['motivo_cambio']);
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk()->assertJsonPath('data.modificado', false);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_antecedents_updated_from_another_real_consultation_invalidate_originals_of_first(): void
    {
        $c = $this->preparar();
        $id = DB::table('citas')->insertGetId($this->citaRow($c['r'], ['inicio_at' => '2026-10-01T12:00:00Z', 'fin_at' => '2026-10-01T12:15:00Z']), 'id_cita');
        $uri = '/api/citas/'.$id.'/consulta';
        $originales = $this->navegador($c['cookies'], 'GET', $uri)->assertOk()->json('data.edicion.originales');
        $post = $this->navegador($c['cookies'], 'POST', $uri, ['originales' => $originales, 'atencion_real_verificada' => true,
            'consulta' => ['tipo_consulta' => 'Control', 'motivo_consulta' => 'Otra atención real']])->assertCreated();
        $this->navegador($c['cookies'], 'PATCH', $uri, ['originales' => $post->json('data.edicion.originales'),
            'atencion_real_verificada' => true, 'antecedentes' => ['alergias' => 'ANTECEDENTE-DE-OTRA-ATENCION'],
            'motivo_cambio' => 'Se completa en otra atención'])->assertOk()->assertJsonPath('data.antecedentes.numero_version', 2)
            ->assertJsonPath('data.antecedentes.id_consulta_origen', $post->json('data.consulta.id_consulta'));
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertConflict()->assertJsonPath('error.code', 'CLINICA_EDICION_OBSOLETA');
        $this->assertSame($antes, $this->snapshot());
    }

    public static function seguridad(): array
    {
        return [['cuenta', 401], ['sesion', 419], ['csrf', 419], ['origen', 403], ['query', 422]];
    }

    #[DataProvider('seguridad')]
    public function test_security_still_applies_to_correction_and_noop(string $modo, int $status): void
    {
        $c = $this->preparar();
        unset($c['datos']['consulta']);
        if ($modo === 'cuenta') {
            DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']);
        } elseif ($modo === 'sesion') {
            DB::table('sessions')->where('user_id', $c['actor']->getKey())->delete();
        }
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'].($modo === 'query' ? '?page=1' : ''), $c['datos'],
            headers: $modo === 'origen' ? ['Origin' => 'https://ajeno.example.test'] : [], csrf: $modo !== 'csrf')->assertStatus($status);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_deactivated_account_after_coordination_does_not_return_success_for_noop(): void
    {
        $c = $this->preparar();
        unset($c['datos']['consulta']);
        $plan = Mockery::mock(PlanificacionRepository::class)->makePartial();
        $plan->shouldReceive('coordinar')->once()->andReturnUsing(function () use ($c): void {
            DB::table('usuarios')->where('id_usuario', $c['actor']->getKey())->update(['estado_cuenta' => 'Desactivada']);
        });
        $this->app->instance(PlanificacionRepository::class, $plan);
        $antes = $this->snapshot();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertUnauthorized();
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_service_checks_declaration_even_for_noop(): void
    {
        $c = $this->preparar();
        unset($c['datos']['consulta']);
        $c['datos']['atencion_real_verificada'] = false;
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Comprueba que la atención real');
        $this->app->make(ConsultaMedicaService::class)->actualizar($c['id'], $c['datos'], []);
    }

    public function test_patient_reads_corrected_current_only_and_never_reason_or_edit_context(): void
    {
        $c = $this->preparar();
        $this->navegador($c['cookies'], 'PATCH', $c['uri'], $c['datos'])->assertOk();
        $paciente = $this->usuarioAuth('Paciente', vincular: false);
        DB::table('pacientes')->where('id_paciente', $c['r']['patient'])->update(['id_usuario' => $paciente->getKey()]);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $paciente)->assertOk();
        $this->navegador($cookies, 'GET', $c['uri'])->assertOk()->assertJsonPath('data.consulta.version_vigente.numero_version', 2)
            ->assertJsonPath('data.consulta.version_vigente.diagnostico', 'DIAGNOSTICO-CORREGIDO')
            ->assertDontSee('DIAGNOSTICO-ORIGINAL')->assertDontSee('MOTIVO-CORRECCION-PRIVADO')->assertDontSee('id_autor')
            ->assertJsonMissingPath('data.edicion')->assertJsonMissingPath('data.modificado');
        $this->navegador($cookies, 'GET', $c['uri'].'/versiones')->assertForbidden();
    }

    public function test_audit_resource_does_not_expose_injected_clinical_content_for_new_event(): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['id_evento' => 1, 'tipo_evento' => 'CONSULTA_ACTUALIZADA', 'version_formato' => 1,
            'ocurrido_en' => now(), 'registrado_en' => now(), 'referencias' => ['id_consulta' => '9007199254740993', 'motivo_cambio' => 'SECRETO'],
            'cambios_seguros' => ['version_anterior' => 1, 'version_nueva' => 2, 'diagnostico' => 'SECRETO'],
            'metadatos_seguros' => ['antecedentes' => 'SECRETO']]);
        $salida = (new BitacoraDetalleResource($evento))->resolve(Request::create('/api/bitacora/1'));
        $this->assertSame('9007199254740993', $salida['referencias']->id_consulta);
        $this->assertSame(2, $salida['cambios_seguros']->version_nueva);
        $this->assertStringNotContainsString('SECRETO', json_encode($salida));
    }
}
