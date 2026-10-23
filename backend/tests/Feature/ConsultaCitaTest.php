<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\ConsultaCitaService;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\presentation\dtos\agenda\CitaDetalleResource;
use Clinica\shared\errors\AppError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ConsultaCitaTest extends TestCase
{
    use AutenticacionFixtures, CitasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T12:20:00Z');
    }

    private function contexto(string $rol = 'Recepcionista', ?string $motivo = 'MOTIVO_PRIVADO', array $extra = []): array
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        if ($rol === 'Médico') {
            $r['doctors'][0] = $actor->medico->getKey();
            DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']]);
        } elseif ($rol === 'Paciente') {
            $r['patient'] = $actor->paciente->getKey();
        }
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['documento_identidad' => 'CI-PRIVADO',
            'telefono' => 'TEL-PRIVADO', 'fecha_nacimiento' => '1980-06-07', 'correo_contacto' => 'privado@example.test']);
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['motivo_breve' => $motivo,
            'id_propuesta_reserva' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', ...$extra]), 'id_cita');

        return [$cookies, $r, $actor, $id];
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['citas', 'pacientes', 'medicos', 'cambios_cita', 'recordatorios', 'consultas_medicas', 'bitacora'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }

    public static function matriz(): array
    {
        $cases = [];
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $cases[] = [$rol, 'MOTIVO_PRIVADO'];
            $cases[] = [$rol, null];
        }

        return $cases;
    }

    #[DataProvider('matriz')]
    public function test_role_matrix_is_enforced_in_sql_and_response_with_missing_fields_not_null(string $rol, ?string $motivo): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto($rol, $motivo);
        $before = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "citas"')) {
                $queries[] = $query->sql;
            }
        });
        $response = $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $data = $response->json('data');
        $common = ['id_cita', 'codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos', 'estado', 'modalidad',
            'sede_atencion', 'consultorio_atencion', 'creada_en', 'medico', 'especialidad', 'paciente'];
        $this->assertEqualsCanonicalizing([...$common, ...($rol === 'Administrador' ? [] : ['motivo_breve'])], array_keys($data));
        if ($rol === 'Administrador') {
            $this->assertArrayNotHasKey('motivo_breve', $data);
        } else {
            $this->assertArrayHasKey('motivo_breve', $data);
            $this->assertSame($motivo, $data['motivo_breve']);
        }
        $patientKeys = ['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'];
        if ($rol === 'Recepcionista') {
            $patientKeys = [...$patientKeys, 'documento_identidad', 'telefono'];
            $this->assertSame('CI-PRIVADO', $data['paciente']['documento_identidad']);
            $this->assertSame('TEL-PRIVADO', $data['paciente']['telefono']);
        } elseif ($rol === 'Médico') {
            $patientKeys[] = 'fecha_nacimiento';
            $this->assertSame('1980-06-07', $data['paciente']['fecha_nacimiento']);
        }
        $this->assertEqualsCanonicalizing($patientKeys, array_keys($data['paciente']));
        $this->assertEqualsCanonicalizing(['id_medico', 'codigo_medico', 'nombres', 'apellidos'], array_keys($data['medico']));
        $this->assertSame($id, $data['id_cita']);
        $this->assertSame((string) $r['patient'], $data['paciente']['id_paciente']);
        $this->assertSame('2026-11-01T12:00:00.000000Z', $data['inicio_at']);
        foreach (['id_propuesta', 'aaaaaaaa-bbbb', 'creada_por', 'password', 'correo_contacto', 'privado@example.test',
            'registro_profesional', 'diagnostico', 'antecedentes', 'consulta_medica', 'laboratorio'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
        $this->assertCount(1, $queries);
        $this->assertSame($rol !== 'Administrador', str_contains($queries[0], '"citas"."motivo_breve"'));
        $this->assertSame($rol === 'Recepcionista', str_contains($queries[0], '"p"."documento_identidad"'));
        $this->assertSame($rol === 'Recepcionista', str_contains($queries[0], '"p"."telefono"'));
        $this->assertSame($rol === 'Médico', str_contains($queries[0], '"p"."fecha_nacimiento"'));
        $this->assertSame($before, $this->snapshot());
    }

    public static function titulares(): array
    {
        return [['Médico'], ['Paciente']];
    }

    #[DataProvider('titulares')]
    public function test_other_and_nonexistent_appointment_are_the_same_404_and_scoped_in_sql(string $rol): void
    {
        [$cookies, $r] = $this->contexto($rol);
        $extra = ['id_propuesta_reserva' => null, 'estado' => 'Cancelada', 'motivo_breve' => 'MOTIVO_AJENO'];
        if ($rol === 'Médico') {
            $extra['id_medico'] = $r['doctors'][1];
            $extra['id_consultorio'] = $r['rooms'][1];
        } else {
            $otro = $this->usuarioAuth('Paciente');
            $extra['id_paciente'] = $otro->paciente->getKey();
        }
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, $extra), 'id_cita');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "citas"')) {
                $queries[] = $query->sql;
            }
        });
        $ajena = $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertNotFound()->json();
        $ausente = $this->navegador($cookies, 'GET', '/api/citas/9223372036854775807')->assertNotFound()->json();
        $this->assertSame($ajena, $ausente);
        foreach ($queries as $sql) {
            $this->assertStringContainsString($rol === 'Médico' ? '"citas"."id_medico" = ?' : '"citas"."id_paciente" = ?', $sql);
        }
        $this->assertStringNotContainsString('MOTIVO_AJENO', json_encode($ajena));
    }

    public function test_direct_service_call_does_not_bypass_ownership(): void
    {
        [$cookies, $r] = $this->contexto('Paciente');
        $otro = $this->usuarioAuth('Paciente');
        $id = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['id_paciente' => $otro->paciente->getKey(), 'estado' => 'Cancelada']), 'id_cita');
        $this->navegador($cookies, 'GET', '/api/mis-citas')->assertOk();
        try {
            $this->app->make(ConsultaCitaService::class)->consultar($id);
            $this->fail('La cita ajena no debe estar disponible.');
        } catch (AppError $error) {
            $this->assertSame('RECURSO_NO_ENCONTRADO', $error->codigo);
        }
    }

    public function test_reception_without_patient_ci_or_account_preserves_null_for_permitted_ci(): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['documento_identidad' => null, 'activo' => false]);
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.paciente.documento_identidad', null)
            ->assertJsonPath('data.paciente.telefono', 'TEL-PRIVADO');
    }

    public static function estados(): array
    {
        return array_map(fn ($state) => [$state], ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia']);
    }

    #[DataProvider('estados')]
    public function test_all_states_and_old_inactive_resources_are_read_without_modifying_history(string $estado): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto('Paciente', '0', ['estado' => $estado,
            'inicio_at' => '2000-01-01T12:00:00Z', 'fin_at' => '2000-01-01T12:23:00Z', 'duracion_minutos' => 23]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('especialidades')->where('id_especialidad', $r['specialty'])->update(['activa' => false]);
        DB::table('medico_especialidad')->where('id_medico', $r['doctors'][0])->update(['habilitada' => false]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'NOMBRE_NUEVO', 'activo' => false]);
        $before = $this->snapshot();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.estado', $estado)
            ->assertJsonPath('data.motivo_breve', '0')->assertJsonPath('data.duracion_minutos', 23)
            ->assertJsonPath('data.consultorio_atencion', 'PRUEBA — Consultorio ficticio');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_bigint_route_and_response_are_exact_without_replacing_post_reservation(): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto('Administrador', extra: ['id_cita' => '9223372036854775807']);
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.id_cita', '9223372036854775807');
        $this->navegador($cookies, 'POST', '/api/citas')->assertForbidden(); // Sigue siendo la reserva, restringida por rol.
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id)->assertStatus(405);
        $this->navegador($cookies, 'DELETE', '/api/citas/'.$id)->assertStatus(405);
    }

    public static function invalidos(): array
    {
        return [['0', ''], ['01', ''], ['-1', ''], ['9223372036854775808', ''], ['texto', ''],
            ['1', '?rol=Recepcionista'], ['1', '?id_objetivo=17'], ['1', '?id_paciente=17'], ['1', '?id_medico=17'],
            ['1', '?include=motivo_breve'], ['1', '?page=1'], ['1', '?estado=Programada']];
    }

    #[DataProvider('invalidos')]
    public function test_route_validation_and_closed_query_cannot_influence_privileges(string $id, string $query): void
    {
        [$cookies] = $this->contexto('Administrador');
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR');
    }

    public function test_nonempty_body_is_rejected_and_guest_origin_expiry_and_head_are_protected(): void
    {
        $cookies = [];
        $this->navegador($cookies, 'GET', '/api/citas/1')->assertUnauthorized();
        [$cookies, $r, $actor, $id] = $this->contexto();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id, ['rol' => 'Administrador'])->assertUnprocessable();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id, [], ['Origin' => 'https://untrusted.example.test'])->assertForbidden();
        $this->navegador($cookies, 'HEAD', '/api/citas/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('sessions')->where('user_id', $actor->getKey())->delete();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertUnauthorized();
    }

    public static function revocaciones(): array
    {
        return [['estado'], ['rol'], ['vinculo'], ['sesion']];
    }

    #[DataProvider('revocaciones')]
    public function test_service_revalidates_actor_before_materializing_detail(string $change): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto('Paciente');
        $original = $this->app->make(UsuarioRepository::class);
        $mock = Mockery::mock(UsuarioRepository::class)->makePartial();
        $mock->shouldReceive('porId')->withArgs(fn ($id, $lock = false) => $lock === true)->once()->andReturnUsing(function ($user, $lock) use ($original, $change) {
            if ($change === 'estado') {
                DB::table('usuarios')->where('id_usuario', $user)->update(['estado_cuenta' => 'Desactivada']);
            } elseif ($change === 'rol') {
                $rol = DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol');
                DB::table('usuarios')->where('id_usuario', $user)->update(['id_rol' => $rol]);
            } elseif ($change === 'vinculo') {
                DB::table('pacientes')->where('id_usuario', $user)->update(['id_usuario' => null]);
            } else {
                DB::table('sessions')->where('user_id', $user)->delete();
            }

            return $original->porId($user, $lock);
        });
        $this->app->instance(UsuarioRepository::class, $mock);
        $response = $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertUnauthorized();
        $this->assertStringNotContainsString('MOTIVO_PRIVADO', $response->getContent());
    }

    public function test_restricted_session_cannot_use_detail(): void
    {
        [$cookies, $r, $admin, $id] = $this->contexto('Administrador');
        $target = $this->usuarioAuth('Recepcionista');
        $receipt = $this->navegador($cookies, 'POST', '/api/usuarios/'.$target->getKey().'/credencial-temporal', ['identidad_verificada' => true])->assertCreated()->json('data');
        $restricted = $this->csrfAuth();
        $this->loginAuth($restricted, $target, $receipt['credencial_temporal'])->assertOk();
        $this->navegador($restricted, 'GET', '/api/citas/'.$id)->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public function test_resource_does_not_serialize_extra_fields_even_if_injected_into_row(): void
    {
        [$cookies, $r, $actor, $id] = $this->contexto('Administrador');
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk();
        $result = $this->app->make(ConsultaCitaService::class)->consultar($id);
        $result['cita'] += ['motivo_breve' => 'NO_SALIR', 'paciente_documento_identidad' => 'NO_SALIR', 'paciente_telefono' => 'NO_SALIR',
            'paciente_fecha_nacimiento' => 'NO_SALIR', 'diagnostico' => 'NO_SALIR', 'id_propuesta_reserva' => 'NO_SALIR'];
        $data = (new CitaDetalleResource($result))->toArray(Request::create('/api/citas/'.$id));
        $this->assertArrayNotHasKey('motivo_breve', $data);
        $this->assertStringNotContainsString('NO_SALIR', json_encode($data));
    }
}
