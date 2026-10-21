<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Medico;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class MedicosAdministrativosTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
    }

    private function administrador(): array
    {
        $actor = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();

        return $cookies;
    }

    private function datos(array $extra = []): array
    {
        $id = DB::table('especialidades')->value('id_especialidad');
        $id ??= DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad Ficticia'], 'id_especialidad');

        return array_replace(['registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Ana', 'apellidos' => 'Ficticia',
            'telefono' => '70000000', 'correo_contacto' => 'CONTACTO@example.test', 'especialidades' => [(string) $id]], $extra);
    }

    private function crear(array &$cookies, array $extra = []): array
    {
        return $this->navegador($cookies, 'POST', '/api/medicos', $this->datos($extra))->assertCreated()->json('data');
    }

    private function editar(array &$cookies, array $ficha, array $datos): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/medicos/'.$ficha['id_medico'],
            ['actualizado_en_original' => $ficha['actualizado_en'], ...$datos]);
    }

    private function estado(array &$cookies, array $ficha, bool $activo): TestResponse
    {
        return $this->navegador($cookies, 'PATCH', '/api/medicos/'.$ficha['id_medico'].'/estado',
            ['actualizado_en_original' => $ficha['actualizado_en'], 'activo' => $activo]);
    }

    private function especialidades(array &$cookies, array $ficha, array $ids): TestResponse
    {
        return $this->navegador($cookies, 'PUT', '/api/medicos/'.$ficha['id_medico'].'/especialidades',
            ['actualizado_en_original' => $ficha['actualizado_en'], 'especialidades' => $ids]);
    }

    public function test_specialty_replacement_keeps_historical_pairs_and_rehabilitation_reuses_same_identity(): void
    {
        $cookies = $this->administrador();
        $a = DB::table('especialidades')->insertGetId(['nombre' => 'Primera Ficticia'], 'id_especialidad');
        $b = DB::table('especialidades')->insertGetId(['nombre' => 'Segunda Ficticia', 'activa' => false], 'id_especialidad');
        $c = DB::table('especialidades')->insertGetId(['nombre' => 'Tercera Ficticia'], 'id_especialidad');
        $f = $this->crear($cookies, ['especialidades' => [(string) $a, (string) $b]]);
        $r = $this->especialidades($cookies, $f, [(string) $c, (string) $b])->assertOk()->json('data');
        $this->assertNotSame($f['actualizado_en'], $r['actualizado_en']);
        $this->assertSame([(string) $a, (string) $b, (string) $c], array_column($r['especialidades'], 'id_especialidad'));
        $this->assertSame([false, true, true], array_column($r['especialidades'], 'habilitada'));
        $this->assertFalse($r['especialidades'][1]['activa']);
        $this->navegador($cookies, 'GET', '/api/medicos?id_especialidad='.$a)->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($cookies, 'GET', '/api/medicos?id_especialidad='.$b)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.especialidades');
        $this->especialidades($cookies, $r, [(string) $b, (string) $c])->assertOk()->assertExactJson(['data' => $r]);
        $rehabilitado = $this->especialidades($cookies, $r, [(string) $a])->assertOk()->json('data');
        $this->assertSame([true, false, false], array_column($rehabilitado['especialidades'], 'habilitada'));
        $this->assertSame(3, DB::table('medico_especialidad')->where('id_medico', $f['id_medico'])->count());
        $this->assertSame(2, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
        $evento = DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->orderBy('id_evento')->first();
        $this->assertEquals(['campos' => ['especialidades'], 'habilitaciones' => ['habilitadas' => [(string) $c], 'retiradas' => [(string) $a]]],
            json_decode($evento->cambios_seguros, true));
        $this->especialidades($cookies, $f, [(string) $a])->assertConflict()->assertJsonPath('error.code', 'MEDICO_EDICION_OBSOLETA');
        $this->editar($cookies, $r, ['nombres' => 'Obsoleto'])->assertConflict();
        $this->estado($cookies, $r, false)->assertConflict();
    }

    public static function citasParaRetiro(): array
    {
        return [['Programada', 0, true], ['Confirmada', 0, true], ['Programada', 1, true], ['Confirmada', 1, true],
            ['Atendida', 1, false], ['Cancelada', 1, false], ['Inasistencia', 1, false],
            ['Programada', -1, false], ['Confirmada', -1, false]];
    }

    #[DataProvider('citasParaRetiro')]
    public function test_withdrawal_uses_exact_server_cutoff_and_only_future_active_states(string $estado, int $minutos, bool $bloqueado): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $cookies = $this->administrador();
        $recursos = $this->citaResources();
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Otra Ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $recursos['doctors'][0], 'id_especialidad' => $otra]);
        $inicio = CarbonImmutable::now('UTC')->addMinutes($minutos);
        DB::table('citas')->insert($this->citaRow($recursos, ['estado' => $estado, 'inicio_at' => $inicio->format('Y-m-d H:i:s.uP'),
            'fin_at' => $inicio->addMinutes(15)->format('Y-m-d H:i:s.uP')]));
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$recursos['doctors'][0])->assertOk()->json('data');
        $citas = DB::table('citas')->get()->toJson();
        $r = $this->especialidades($cookies, $f, [(string) $otra]);
        if ($bloqueado) {
            $r->assertConflict()->assertJsonPath('error.code', 'MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS');
            $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
            $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
        } else {
            $r->assertOk()->assertJsonPath('data.especialidades.0.habilitada', false);
        }
        $this->assertSame($citas, DB::table('citas')->get()->toJson());
        $this->assertSame(3, DB::table('medico_especialidad')->count());
    }

    public function test_future_appointment_on_another_pair_does_not_block_and_history_remains_intact(): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Otra Ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $otra]);
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0])->assertOk()->json('data');
        $this->especialidades($cookies, $f, [(string) $r['specialty']])->assertOk();
        $this->assertFalse(DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->where('id_especialidad', $otra)->value('habilitada'));
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertTrue(DB::table('medico_especialidad')->where('id_medico', $r['doctors'][1])->value('habilitada'));
    }

    public function test_last_enabled_specialty_cannot_be_removed_even_with_inactive_professional_or_catalog(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $f = $this->estado($cookies, $f, false)->assertOk()->json('data');
        DB::table('especialidades')->update(['activa' => false]);
        $this->especialidades($cookies, $f, [])->assertConflict()->assertJsonPath('error.code', 'MEDICO_SIN_ESPECIALIDADES_HABILITADAS');
        $this->assertTrue(DB::table('medico_especialidad')->value('habilitada'));
        $this->assertFalse(DB::table('medicos')->value('activo'));
    }

    public static function listasEspecialidadesInvalidas(): array
    {
        return [[['especialidades' => null]], [['especialidades' => ['0']]], [['especialidades' => ['9223372036854775808']]],
            [['especialidades' => ['9223372036854775807']]], [['especialidades' => ['no-id']]],
            [['especialidades' => ['clave' => '1']]], [['especialidades' => [['id' => '1']]]],
            [['activo' => false]], [['id_usuario' => '1']], [['actualizado_en_original' => null]]];
    }

    #[DataProvider('listasEspecialidadesInvalidas')]
    public function test_specialty_replacement_rejects_bad_fields_without_changes(array $extra): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->navegador($cookies, 'PUT', '/api/medicos/'.$f['id_medico'].'/especialidades',
            ['actualizado_en_original' => $f['actualizado_en'], 'especialidades' => [$f['especialidades'][0]['id_especialidad']], ...$extra])->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
    }

    public function test_specialty_replacement_rejects_duplicate_ids_and_rolls_back_all_changes_on_audit_failure(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $id = $f['especialidades'][0]['id_especialidad'];
        $this->especialidades($cookies, $f, [$id, (int) $id])->assertUnprocessable();
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Otra Ficticia'], 'id_especialidad');
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('medicoModificado')->once()->andThrow(new RuntimeException('Fallo privado simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->especialidades($cookies, $f, [(string) $otra])->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO');
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
        $this->assertSame(1, DB::table('medico_especialidad')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESPECIALIDADES_CAMBIADAS')->count());
    }

    public function test_pivot_migration_preserves_composite_identity_existing_rows_and_appointment_foreign_keys(): void
    {
        $migration = require base_path('src/data/database/migrations/2026_10_21_000032_add_habilitada_to_medico_especialidad.php');
        $migration->down();
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        $pares = DB::table('medico_especialidad')->get()->map(fn ($p) => (array) $p)->all();
        $citas = DB::table('citas')->get()->toJson();
        $migration->up();
        foreach (DB::table('medico_especialidad')->get() as $i => $par) {
            $this->assertTrue($par->habilitada);
            unset($par->habilitada);
            $this->assertSame($pares[$i], (array) $par);
        }
        $this->assertSame($citas, DB::table('citas')->get()->toJson());
        $columna = DB::selectOne("SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'medico_especialidad' AND column_name = 'habilitada'");
        $this->assertSame('boolean', $columna->data_type);
        $this->assertSame('NO', $columna->is_nullable);
        $this->assertSame('true', $columna->column_default);
    }

    public function test_professional_state_preserves_account_multiple_sessions_and_existing_obligations(): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        $usuario = $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $usuario->getKey()]);
        DB::table('citas')->insert($this->citaRow($r));
        $idCita = DB::table('citas')->value('id_cita');
        $consulta = DB::table('consultas_medicas')->insertGetId(['codigo_consulta' => 'CONS-FICTICIA', 'id_cita' => $idCita], 'id_consulta');
        DB::table('solicitudes_laboratorio')->insert(['id_consulta' => $consulta, 'estudio_solicitado' => 'Estudio ficticio', 'solicitada_en' => now()]);
        $dispositivos = [];
        for ($i = 0; $i < 2; $i++) {
            $dispositivos[$i] = $this->csrfAuth();
            $this->loginAuth($dispositivos[$i], $usuario)->assertOk();
        }
        $cuenta = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole();
        $sesiones = DB::table('sessions')->where('user_id', $usuario->getKey())->get()->toJson();
        $antes = [];
        foreach (['citas', 'consultas_medicas', 'solicitudes_laboratorio', 'medico_especialidad', 'horarios_atencion'] as $tabla) {
            $antes[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0])->assertOk()->json('data');
        $inactivo = $this->estado($cookies, $f, false)->assertOk()->assertJsonPath('data.activo', false)->json('data');
        $this->assertNotSame($f['actualizado_en'], $inactivo['actualizado_en']);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Recepcionista'])
            ->assertConflict()->assertJsonPath('error.code', 'MEDICO_OBLIGACIONES_NO_RESUELTAS');
        $this->assertSame($sesiones, DB::table('sessions')->where('user_id', $usuario->getKey())->get()->toJson());
        foreach ($dispositivos as &$dispositivo) {
            $this->navegador($dispositivo, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('data.rol', 'Médico');
        }
        $activo = $this->estado($cookies, $inactivo, true)->assertOk()->assertJsonPath('data.activo', true)->json('data');
        $this->estado($cookies, $activo, true)->assertOk()->assertExactJson(['data' => $activo]);
        $this->assertEquals($cuenta, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole());
        foreach ($antes as $tabla => $datos) {
            $this->assertSame($datos, DB::table($tabla)->get()->toJson());
        }
        $eventos = DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESTADO_CAMBIADO')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame(['campos' => ['activo']], json_decode($eventos[0]->cambios_seguros, true));
    }

    public function test_state_and_data_share_version_and_reject_stale_noops(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $inactivo = $this->estado($cookies, $f, false)->assertOk()->json('data');
        $this->estado($cookies, $f, false)->assertConflict()->assertJsonPath('error.code', 'MEDICO_EDICION_OBSOLETA');
        $this->editar($cookies, $f, ['nombres' => 'Obsoleto'])->assertConflict();
        $actual = $this->editar($cookies, $inactivo, ['nombres' => 'Actual'])->assertOk()->json('data');
        $this->estado($cookies, $inactivo, true)->assertConflict();
        $this->estado($cookies, $actual, false)->assertOk()->assertExactJson(['data' => $actual]);
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESTADO_CAMBIADO')->count());
    }

    public static function estadosCuenta(): array
    {
        return [['Activa'], ['Desactivada'], ['Pendiente']];
    }

    #[DataProvider('estadosCuenta')]
    public function test_professional_reactivation_never_activates_account_or_removes_login_block(string $estado): void
    {
        $cookies = $this->administrador();
        $usuario = $this->usuarioAuth('Médico', ['estado_cuenta' => $estado, 'intentos_fallidos' => 3,
            'bloqueado_hasta' => CarbonImmutable::now('UTC')->addMinutes(10)->format('Y-m-d H:i:s.uP')]);
        $cuenta = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole();
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$usuario->medico->getKey())->assertOk()->json('data');
        $this->estado($cookies, $f, true)->assertOk()->assertJsonPath('data.activo', true);
        $this->assertEquals($cuenta, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole());
        $otro = $this->csrfAuth();
        $this->assertNotSame(200, $this->loginAuth($otro, $usuario)->status());
    }

    public function test_cu03_role_exit_requires_inactivity_and_return_is_explicit_before_professional_reactivation(): void
    {
        $cookies = $this->administrador();
        $this->usuarioAuth('Recepcionista');
        $usuario = $this->usuarioAuth('Médico');
        $id = $usuario->medico->getKey();
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$id)->assertOk()->json('data');
        $f = $this->estado($cookies, $f, true)->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Recepcionista'])
            ->assertConflict()->assertJsonPath('error.code', 'MEDICO_OBLIGACIONES_NO_RESUELTAS');
        $f = $this->estado($cookies, $f, false)->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Recepcionista'])->assertOk();
        $this->estado($cookies, $f, true)->assertConflict()->assertJsonPath('error.code', 'MEDICO_VINCULO_INCOMPATIBLE');
        $this->navegador($cookies, 'GET', '/api/medicos/'.$id)->assertExactJson(['data' => $f]);
        $this->assertSame('Recepcionista', $usuario->fresh()->rol->nombre);
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/rol', ['rol' => 'Médico', 'id_medico' => (string) $id])->assertOk();
        $this->estado($cookies, $f, true)->assertOk()->assertJsonPath('data.activo', true);
        $this->assertSame((string) $id, (string) $usuario->fresh()->medico->getKey());
    }

    public function test_cu03_account_deactivation_still_revokes_access_without_changing_professional_state(): void
    {
        $cookies = $this->administrador();
        $usuario = $this->usuarioAuth('Médico');
        $medicoCookies = $this->csrfAuth();
        $this->loginAuth($medicoCookies, $usuario)->assertOk();
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$usuario->medico->getKey())->assertOk()->json('data');
        $f = $this->estado($cookies, $f, true)->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/usuarios/'.$usuario->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->navegador($cookies, 'GET', '/api/medicos/'.$usuario->medico->getKey())->assertExactJson(['data' => $f]);
        $this->navegador($medicoCookies, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
    }

    public static function estadosInvalidos(): array
    {
        return [[['activo' => null]], [['activo' => 'false']], [['activo' => []]], [['activo' => 2]],
            [['estado_cuenta' => 'Activa']], [['especialidades' => []]], [['id_usuario' => '1']],
            [['actualizado_en_original' => null]], [['actualizado_en_original' => '2026-10-21T12:00:00Z']]];
    }

    #[DataProvider('estadosInvalidos')]
    public function test_state_rejects_invalid_payloads_and_unexpected_fields(array $datos): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->navegador($cookies, 'PATCH', '/api/medicos/'.$f['id_medico'].'/estado',
            ['activo' => false, 'actualizado_en_original' => $f['actualizado_en'], ...$datos])->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
    }

    public function test_state_audit_failure_rolls_back_version_and_activity(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('medicoModificado')->once()->andThrow(new RuntimeException('Fallo privado simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->estado($cookies, $f, false)->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Fallo privado');
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ESTADO_CAMBIADO')->count());
    }

    public function test_creation_has_multiple_initial_specialties_without_automatic_account_room_or_schedule(): void
    {
        $cookies = $this->administrador();
        $a = DB::table('especialidades')->insertGetId(['nombre' => 'Primera Ficticia'], 'id_especialidad');
        $b = DB::table('especialidades')->insertGetId(['nombre' => 'Segunda Ficticia', 'activa' => false], 'id_especialidad');
        $usuarios = DB::table('usuarios')->count();
        $f = $this->crear($cookies, ['especialidades' => [(string) $b, (string) $a]]);
        $this->assertIsString($f['id_medico']);
        $this->assertMatchesRegularExpression('/^MED-[a-f0-9]{32}$/D', $f['codigo_medico']);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/D', $f['actualizado_en']);
        $this->assertSame('contacto@example.test', $f['correo_contacto']);
        $this->assertFalse($f['cuenta_vinculada']);
        $this->assertTrue($f['activo']);
        $this->assertSame([(string) $a, (string) $b], array_column($f['especialidades'], 'id_especialidad'));
        $this->assertFalse($f['especialidades'][1]['activa']);
        $row = DB::table('medicos')->where('id_medico', $f['id_medico'])->sole();
        $this->assertNull($row->id_usuario);
        $this->assertNull($row->id_consultorio);
        $this->assertSame($usuarios, DB::table('usuarios')->count());
        foreach (['horarios_atencion', 'consultorios', 'citas', 'tokens_acceso'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count());
        }
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertOk()->assertExactJson(['data' => $f])
            ->assertHeader('Cache-Control', 'no-store, private');
        $evento = DB::table('bitacora')->where('tipo_evento', 'MEDICO_CREADO')->sole();
        $this->assertSame('MEDICOS', $evento->modulo);
        $this->assertSame(['id_medico' => $f['id_medico']], json_decode($evento->referencias, true));
        $this->assertStringNotContainsString('contacto@example.test', json_encode($evento));
        $this->assertSame(2, DB::table('medico_especialidad')->count());
    }

    public function test_professional_registration_and_contact_are_not_unique_identifiers(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies);
        $b = $this->crear($cookies);
        $this->assertNotSame($a['id_medico'], $b['id_medico']);
        $this->assertNotSame($a['codigo_medico'], $b['codigo_medico']);
        $this->assertSame($a['registro_profesional'], $b['registro_profesional']);
        $this->assertSame($a['correo_contacto'], $b['correo_contacto']);
    }

    public function test_name_and_specialty_filters_are_literal_combined_paginated_and_minimal(): void
    {
        $cookies = $this->administrador();
        $a = $this->crear($cookies, ['nombres' => 'Ana %']);
        $b = $this->crear($cookies, ['nombres' => 'Ana _']);
        DB::table('medicos')->where('id_medico', $a['id_medico'])->update(['activo' => false]);
        $id = $a['especialidades'][0]['id_especialidad'];
        $this->navegador($cookies, 'GET', '/api/medicos?nombre=ana&per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id_medico', $b['id_medico'])->assertJsonMissingPath('data.0.registro_profesional')
            ->assertJsonMissingPath('data.0.correo_contacto')->assertJsonMissingPath('data.0.actualizado_en');
        $this->navegador($cookies, 'GET', '/api/medicos?nombre=ANA&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id_medico', $a['id_medico']);
        $this->navegador($cookies, 'GET', '/api/medicos?nombre=%25&activo=0&id_especialidad='.$id)->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/medicos?nombre=_%20ficticia&activo=1')->assertOk()->assertJsonCount(1, 'data');
        $this->navegador($cookies, 'GET', '/api/medicos?id_especialidad=9223372036854775807')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($cookies, 'GET', '/api/medicos?nombre=inexistente')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_edit_preserves_account_credentials_relations_appointments_and_inactive_status(): void
    {
        $cookies = $this->administrador();
        $r = $this->citaResources();
        DB::table('citas')->insert($this->citaRow($r));
        $usuario = $this->usuarioAuth('Médico', vincular: false);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['id_usuario' => $usuario->getKey(), 'activo' => false]);
        $f = $this->navegador($cookies, 'GET', '/api/medicos/'.$r['doctors'][0])->assertOk()->json('data');
        $cuenta = DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole();
        $citas = DB::table('citas')->get()->toJson();
        $pares = DB::table('medico_especialidad')->get()->toJson();
        $resultado = $this->editar($cookies, $f, ['registro_profesional' => 'REG-CORREGIDO', 'nombres' => 'Nombre Corregido',
            'apellidos' => 'Apellido Corregido', 'telefono' => '71111111', 'correo_contacto' => ' NUEVO@example.test '])->assertOk()
            ->assertJsonPath('data.correo_contacto', 'nuevo@example.test')->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.cuenta_vinculada', true)->assertJsonPath('data.codigo_medico', $f['codigo_medico'])->json('data');
        $this->assertNotSame($f['actualizado_en'], $resultado['actualizado_en']);
        $this->assertEquals($cuenta, DB::table('usuarios')->where('id_usuario', $usuario->getKey())->sole());
        $this->assertSame($citas, DB::table('citas')->get()->toJson());
        $this->assertSame($pares, DB::table('medico_especialidad')->get()->toJson());
        $this->assertSame($r['rooms'][0], DB::table('medicos')->where('id_medico', $f['id_medico'])->value('id_consultorio'));
        foreach (['id_usuario', 'usuario', 'password_hash', 'correo_acceso', 'id_consultorio', 'citas'] as $campo) {
            $this->assertArrayNotHasKey($campo, $resultado);
        }
        $evento = DB::table('bitacora')->where('tipo_evento', 'MEDICO_ACTUALIZADO')->sole();
        $this->assertStringNotContainsString('nuevo@example.test', json_encode($evento));
    }

    public function test_stale_even_noop_rejected_current_noop_preserves_event_and_monotonic_version(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->editar($cookies, $f, [])->assertOk()->assertExactJson(['data' => $f]);
        $this->editar($cookies, $f, ['correo_contacto' => 'CONTACTO@example.test'])->assertOk()->assertExactJson(['data' => $f]);
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ACTUALIZADO')->count());
        $futuro = CarbonImmutable::now('UTC')->addDay()->setMicrosecond(999999);
        DB::table('medicos')->where('id_medico', $f['id_medico'])->update(['actualizado_en' => $futuro->format('Y-m-d H:i:s.uP')]);
        $this->editar($cookies, $f, [])->assertConflict()->assertJsonPath('error.code', 'MEDICO_EDICION_OBSOLETA');
        $f['actualizado_en'] = $futuro->format('Y-m-d\TH:i:s.u\Z');
        $this->editar($cookies, $f, ['telefono' => '71111111'])->assertOk()
            ->assertJsonPath('data.actualizado_en', $futuro->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z'));
        $this->editar($cookies, $f, ['telefono' => '71111111'])->assertConflict();
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'MEDICO_ACTUALIZADO')->count());
    }

    public static function invalidos(): array
    {
        return [
            [['nombres' => ' '], 'nombres'], [['nombres' => ['Objeto']], 'nombres'],
            [['apellidos' => str_repeat('a', 121)], 'apellidos'], [['registro_profesional' => str_repeat('a', 81)], 'registro_profesional'],
            [['telefono' => str_repeat('1', 41)], 'telefono'], [['correo_contacto' => 'invalido'], 'correo_contacto'],
            [['nombres' => "Nom\0bre"], 'nombres'], [['especialidades' => []], 'especialidades'],
            [['especialidades' => ['abc']], 'especialidades.0'], [['especialidades' => ['0']], 'especialidades.0'],
            [['especialidades' => ['9223372036854775808']], 'especialidades.0'],
            [['especialidades' => ['clave' => '1']], 'especialidades'],
            [['especialidades' => [['id_especialidad' => '1']]], 'especialidades.0'],
            [['especialidades' => null], 'especialidades'], [['id_usuario' => '1'], 'id_usuario'],
            [['codigo_medico' => 'MED-CLIENTE'], 'codigo_medico'], [['activo' => false], 'activo'],
            [['id_consultorio' => '1'], 'id_consultorio'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_rejects_bad_creation_without_partial_rows(array $extra, string $campo): void
    {
        $cookies = $this->administrador();
        $this->navegador($cookies, 'POST', '/api/medicos', $this->datos($extra))->assertUnprocessable()
            ->assertJsonValidationErrors($campo, 'error.details');
        $this->assertSame(0, DB::table('medicos')->count());
        $this->assertSame(0, DB::table('medico_especialidad')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CREADO')->count());
    }

    public function test_rejects_duplicate_and_nonexistent_specialties_atomically(): void
    {
        $cookies = $this->administrador();
        $datos = $this->datos();
        $id = $datos['especialidades'][0];
        foreach ([[$id, $id], [$id, (int) $id], [$id, '9223372036854775807']] as $ids) {
            $this->navegador($cookies, 'POST', '/api/medicos', [...$datos, 'especialidades' => $ids])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('medicos')->count());
        $this->assertSame(0, DB::table('medico_especialidad')->count());
    }

    public function test_update_requires_version_and_rejects_control_fields_and_invalid_filters(): void
    {
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        foreach (['activo' => false, 'especialidades' => [], 'id_usuario' => '1', 'id_consultorio' => '1', 'codigo_medico' => 'Ajeno',
            'id_medico' => '1', 'nombres' => null, 'telefono' => '', 'correo_contacto' => 'invalido'] as $campo => $valor) {
            $this->editar($cookies, $f, [$campo => $valor])->assertUnprocessable()->assertJsonValidationErrors($campo, 'error.details');
        }
        foreach ([null, '2026-10-21T12:00:00Z', '2026-02-30T12:00:00.000000Z'] as $version) {
            $this->editar($cookies, $f, ['actualizado_en_original' => $version])->assertUnprocessable();
        }
        foreach (['per_page=101', 'page=0', 'activo=false', 'orden=nombre', 'nombre[]=objeto', 'id_especialidad=0', 'id_especialidad=9223372036854775808'] as $q) {
            $this->navegador($cookies, 'GET', '/api/medicos?'.$q)->assertUnprocessable();
        }
        $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
    }

    public static function roles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    #[DataProvider('roles')]
    public function test_all_operations_deny_other_roles(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        foreach ([['GET', ''], ['POST', ''], ['GET', '/1'], ['PATCH', '/1'], ['PATCH', '/1/estado'], ['PUT', '/1/especialidades']] as [$metodo, $sufijo]) {
            $this->navegador($cookies, $metodo, '/api/medicos'.$sufijo)->assertForbidden();
        }
    }

    public function test_missing_anonymous_csrf_origin_and_revoked_sessions(): void
    {
        $cookies = $this->csrfAuth();
        $this->navegador($cookies, 'GET', '/api/medicos')->assertUnauthorized();
        $cookies = $this->administrador();
        $f = $this->crear($cookies);
        $this->navegador($cookies, 'POST', '/api/medicos', $this->datos(), csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'PATCH', '/api/medicos/'.$f['id_medico'].'/estado',
            ['activo' => false, 'actualizado_en_original' => $f['actualizado_en']], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'PUT', '/api/medicos/'.$f['id_medico'].'/especialidades',
            ['especialidades' => [], 'actualizado_en_original' => $f['actualizado_en']], csrf: false)->assertStatus(419);
        $this->navegador($cookies, 'GET', '/api/medicos', headers: ['Origin' => 'https://ajeno.example.test'])->assertForbidden();
        $this->navegador($cookies, 'GET', '/api/medicos/9223372036854775807')->assertNotFound();
        $this->navegador($cookies, 'GET', '/api/medicos/9223372036854775808')->assertUnprocessable();
        $this->editar($cookies, [...$f, 'id_medico' => '9223372036854775807'], [])->assertNotFound();
        $this->navegador($cookies, 'DELETE', '/api/medicos/'.$f['id_medico'])->assertStatus(405);
        DB::table('usuarios')->update(['estado_cuenta' => 'Desactivada']);
        $this->navegador($cookies, 'GET', '/api/medicos')->assertUnauthorized();
        $this->estado($cookies, $f, false)->assertUnauthorized();
        $this->especialidades($cookies, $f, [])->assertUnauthorized();
    }

    public function test_restricted_administrator_cannot_access_medical_administration(): void
    {
        $cookies = $this->administrador();
        $actor = $this->usuarioAuth();
        $secreto = $this->navegador($cookies, 'POST', '/api/usuarios/'.$actor->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor, $secreto)->assertOk();
        $this->navegador($cookies, 'GET', '/api/medicos')->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
        $this->navegador($cookies, 'POST', '/api/medicos', $this->datos())->assertForbidden();
        $this->navegador($cookies, 'PATCH', '/api/medicos/1/estado', ['activo' => false])->assertForbidden();
        $this->navegador($cookies, 'PUT', '/api/medicos/1/especialidades', ['especialidades' => []])->assertForbidden();
    }

    public static function escrituras(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('escrituras')]
    public function test_audit_failure_rolls_back_doctor_initial_pairs_and_version(bool $editar): void
    {
        $cookies = $this->administrador();
        $f = $editar ? $this->crear($cookies) : null;
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('medicoModificado')->once()->andThrow(new RuntimeException('Detalle privado simulado'));
        $this->app->instance(BitacoraRepository::class, $mock);
        $r = $editar ? $this->editar($cookies, $f, ['nombres' => 'No confirmar'])
            : $this->navegador($cookies, 'POST', '/api/medicos', $this->datos());
        $r->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INTERNO')->assertDontSee('Detalle privado');
        $this->assertSame($editar ? 1 : 0, DB::table('medicos')->count());
        $this->assertSame($editar ? 1 : 0, DB::table('medico_especialidad')->count());
        if ($editar) {
            $this->navegador($cookies, 'GET', '/api/medicos/'.$f['id_medico'])->assertExactJson(['data' => $f]);
        }
    }

    public function test_migration_initializes_existing_professional_preserving_all_values(): void
    {
        $migration = require base_path('src/data/database/migrations/2026_10_21_000031_add_actualizado_en_to_medicos.php');
        $migration->down();
        $r = $this->citaResources();
        $antes = DB::table('medicos')->get()->map(fn ($m) => (array) $m)->all();
        $migration->up();
        foreach ($antes as $original) {
            $row = DB::table('medicos')->where('id_medico', $original['id_medico'])->sole();
            $this->assertNotNull($row->actualizado_en);
            unset($row->actualizado_en);
            $this->assertSame($original, (array) $row);
        }
        $columna = DB::selectOne("SELECT data_type, datetime_precision, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'medicos' AND column_name = 'actualizado_en'");
        $this->assertSame('timestamp with time zone', $columna->data_type);
        $this->assertSame(6, $columna->datetime_precision);
        $this->assertSame('NO', $columna->is_nullable);
        $this->assertSame('CURRENT_TIMESTAMP', $columna->column_default);
        $this->assertInstanceOf(CarbonImmutable::class, Medico::query()->findOrFail($r['doctors'][0])->actualizado_en);
    }

    public static function conflictosSql(): array
    {
        return [['codigo', 409, 'MEDICO_CONFLICTO_UNICIDAD'], ['pk', 500, 'ERROR_INTERNO']];
    }

    #[DataProvider('conflictosSql')]
    public function test_only_known_code_constraint_is_translated_to_conflict(string $conflicto, int $status, string $codigo): void
    {
        $cookies = $this->administrador();
        $mock = Mockery::mock(BitacoraRepository::class)->makePartial();
        $mock->shouldReceive('medicoModificado')->once()->andReturnUsing(function (Medico $medico) use ($conflicto): void {
            $fila = $medico->getAttributes();
            if ($conflicto === 'codigo') {
                unset($fila['id_medico']);
            } else {
                $fila['codigo_medico'] = 'MED-OTRO-FICTICIO';
            }
            DB::table('medicos')->insert($fila);
        });
        $this->app->instance(BitacoraRepository::class, $mock);
        $this->navegador($cookies, 'POST', '/api/medicos', $this->datos())->assertStatus($status)->assertJsonPath('error.code', $codigo)
            ->assertDontSee('SQLSTATE')->assertDontSee('medicos_codigo_uq');
        $this->assertSame(0, DB::table('medicos')->count());
        $this->assertSame(0, DB::table('medico_especialidad')->count());
        $this->assertSame(0, DB::table('bitacora')->where('tipo_evento', 'MEDICO_CREADO')->count());
    }
}
