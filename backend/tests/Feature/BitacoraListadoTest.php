<?php

namespace Tests\Feature;

use Clinica\business\services\bitacora\BitacoraService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class BitacoraListadoTest extends TestCase
{
    use AutenticacionFixtures;

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

        return [$cookies, $actor];
    }

    private function evento(array $datos = []): string
    {
        $evento = new Bitacora;
        $evento->forceFill(array_replace([
            'tipo_evento' => 'PRUEBA_LISTADO', 'tipo_actor' => 'SISTEMA', 'modulo' => 'PRUEBA',
            'operacion' => 'CONSULTAR', 'resultado' => 'EXITO',
            'ocurrido_en' => '2026-10-21 14:00:00.123456+00', 'registrado_en' => '2026-10-21 14:01:00.654321+00',
            'correlacion' => (string) Str::uuid(),
        ], $datos))->saveOrFail();

        return (string) $evento->getKey();
    }

    private function listar(array &$cookies, array $filtros = []): TestResponse
    {
        return $this->navegador($cookies, 'GET', '/api/bitacora?'.http_build_query($filtros));
    }

    public function test_list_is_explicit_private_read_only_and_preserves_bigint_and_stored_dates(): void
    {
        [$cookies, $actor] = $this->administrador();
        $id = $this->evento(['id_evento' => '9007199254740993', 'tipo_evento' => 'MEDICO_ACTUALIZADO',
            'tipo_actor' => 'USUARIO', 'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => 'Recepcionista',
            'id_usuario_objetivo' => $actor->getKey(), 'rol_objetivo' => 'Médico', 'direccion_ip' => '2001:db8::1',
            'causa_codigo' => 'ACCESO_DENEGADO', 'referencias' => ['id_medico' => '9007199254740995', 'token' => 'secreto-ficticio'],
            'cambios_seguros' => ['password' => 'secreto-ficticio'], 'metadatos_seguros' => ['diagnostico' => 'secreto-ficticio']]);
        $antes = DB::table('bitacora')->orderBy('id_evento')->get()->toJson();
        $r = $this->listar($cookies, ['modulo' => 'PRUEBA'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 20)->assertJsonPath('data.0.id_evento', $id)
            // La migración existente declara TIMESTAMPTZ(0): representar lo persistido, no inventar precisión.
            ->assertJsonPath('data.0.ocurrido_en', Bitacora::query()->findOrFail($id)->ocurrido_en->utc()->format('Y-m-d\TH:i:s.u\Z'))
            ->assertJsonPath('data.0.registrado_en', Bitacora::query()->findOrFail($id)->registrado_en->utc()->format('Y-m-d\TH:i:s.u\Z'))
            ->assertJsonPath('data.0.rol_responsable', 'Recepcionista')->assertJsonPath('data.0.rol_objetivo', 'Médico')
            ->assertJsonPath('data.0.id_usuario_responsable', (string) $actor->getKey())
            ->assertJsonPath('data.0.referencias.id_medico', '9007199254740995');
        $this->assertSame(['id_evento', 'tipo_evento', 'tipo_actor', 'id_usuario_responsable', 'rol_responsable',
            'id_usuario_objetivo', 'rol_objetivo', 'modulo', 'operacion', 'resultado', 'causa_codigo', 'ocurrido_en',
            'registrado_en', 'direccion_ip', 'entidad_referida', 'registro_referido', 'referencias'], array_keys($r->json('data.0')));
        $this->assertStringNotContainsString('secreto-ficticio', $r->getContent());
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $r->json('data.0.ocurrido_en'));
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $r->json('data.0.registrado_en'));
        $this->assertSame($antes, DB::table('bitacora')->orderBy('id_evento')->get()->toJson());
    }

    public function test_fixed_insertion_order_ties_pagination_and_validated_links(): void
    {
        [$cookies] = $this->administrador();
        $a = $this->evento(['ocurrido_en' => '2027-01-01 00:00:00+00']);
        $b = $this->evento(['ocurrido_en' => '2025-01-01 00:00:00+00']);
        $c = $this->evento(['registrado_en' => '2026-10-21 14:02:00+00']);
        $r = $this->listar($cookies, ['modulo' => 'PRUEBA', 'per_page' => 1])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.id_evento', $c);
        parse_str(parse_url($r->json('links.next'), PHP_URL_QUERY), $query);
        $this->assertEquals(['modulo' => 'PRUEBA', 'per_page' => 1, 'page' => 2], $query);
        $this->listar($cookies, $query)->assertOk()->assertJsonPath('data.0.id_evento', $b);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'per_page' => 1, 'page' => 3])->assertJsonPath('data.0.id_evento', $a);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'per_page' => 1, 'page' => 4])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 3);
        $this->listar($cookies, ['modulo' => 'NO_EXISTE'])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_each_scalar_filter_and_combination_use_exact_and_semantics(): void
    {
        [$cookies, $actor] = $this->administrador();
        $objetivo = $this->usuarioAuth();
        $id = $this->evento(['tipo_actor' => 'USUARIO', 'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => 'Administrador',
            'id_usuario_objetivo' => $objetivo->getKey(), 'rol_objetivo' => 'Administrador', 'tipo_evento' => 'TIPO_%_FICTICIO',
            'modulo' => 'Modulo_%', 'operacion' => 'Operacion_%', 'resultado' => 'DENEGADO', 'direccion_ip' => '192.0.2.33']);
        $this->evento();
        $filtros = ['id_usuario_responsable' => (string) $actor->getKey(), 'id_usuario_objetivo' => (string) $objetivo->getKey(),
            'tipo_actor' => 'USUARIO', 'tipo_evento' => 'TIPO_%_FICTICIO', 'modulo' => 'Modulo_%',
            'operacion' => 'Operacion_%', 'resultado' => 'DENEGADO', 'direccion_ip' => '192.0.2.33'];
        foreach ($filtros as $campo => $valor) {
            $r = $this->listar($cookies, [$campo => $valor])->assertOk();
            $this->assertContains($id, array_column($r->json('data'), 'id_evento'));
        }
        $this->listar($cookies, $filtros)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_evento', $id);
        foreach (['modulo' => 'modulo_%', 'operacion' => '%', 'tipo_evento' => 'TIPO_', 'id_usuario_objetivo' => '9223372036854775807'] as $campo => $valor) {
            $this->listar($cookies, array_replace($filtros, [$campo => $valor]))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->listar($cookies, ['modulo' => '  Modulo_%  '])->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_local_dates_are_half_open_utc_bounds_independent_of_insertion_and_connection_timezone(): void
    {
        [$cookies] = $this->administrador();
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tokyo'");
        $this->evento(['ocurrido_en' => '2026-10-21 03:59:59+00']);
        $inicio = $this->evento(['ocurrido_en' => '2026-10-21 04:00:00.000000+00']);
        $fin = $this->evento(['ocurrido_en' => '2026-10-22 03:59:59+00']);
        $this->evento(['ocurrido_en' => '2026-10-22 04:00:00.000000+00']);
        $r = $this->listar($cookies, ['modulo' => 'PRUEBA', 'fecha_desde' => '2026-10-21', 'fecha_hasta' => '2026-10-21'])
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$fin, $inicio], array_column($r->json('data'), 'id_evento'));
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'fecha_desde' => '2026-10-21'])->assertOk()->assertJsonPath('meta.total', 3);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'fecha_hasta' => '2026-10-21'])->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_ipv6_uses_inet_equality_and_anonymous_and_system_are_distinct(): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['direccion_ip' => '2001:db8::1']);
        $this->evento(['tipo_actor' => 'ANONIMO', 'direccion_ip' => '192.0.2.1']);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'direccion_ip' => '2001:0db8:0000:0000:0000:0000:0000:0001'])
            ->assertOk()->assertJsonPath('data.0.id_evento', $id)->assertJsonPath('data.0.direccion_ip', '2001:db8::1');
        foreach (['SISTEMA', 'ANONIMO'] as $tipo) {
            $this->listar($cookies, ['modulo' => 'PRUEBA', 'tipo_actor' => $tipo])->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id_usuario_responsable', null)->assertJsonPath('data.0.rol_responsable', null);
        }
    }

    #[DataProvider('recursos')]
    public function test_resource_filter_covers_historical_locations_and_entity_only(string $entidad, array $datos, string $registro): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento($datos);
        $this->evento(['entidad_referida' => $entidad, 'registro_referido' => '999']);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => $entidad, 'registro_referido' => $registro])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_evento', $id);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => $entidad])->assertOk()->assertJsonPath('meta.total', 2);
        $this->listar($cookies, ['modulo' => 'OTRO', 'entidad_referida' => $entidad, 'registro_referido' => $registro])
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public static function recursos(): array
    {
        return [
            'fisico paciente' => ['Paciente', ['entidad_referida' => 'Paciente', 'registro_referido' => '7'], '7'],
            'fisico usuario' => ['Usuario', ['entidad_referida' => 'Usuario', 'registro_referido' => '7'], '7'],
            'fisico medico' => ['Medico', ['entidad_referida' => 'Medico', 'registro_referido' => '7'], '7'],
            'fisico especialidad' => ['Especialidad', ['entidad_referida' => 'Especialidad', 'registro_referido' => '7'], '7'],
            'referencia paciente' => ['Paciente', ['referencias' => ['id_paciente' => '7']], '7'],
            'referencia medico numerica' => ['Medico', ['referencias' => ['id_medico' => 7]], '7'],
            'referencia especialidad bigint' => ['Especialidad', ['referencias' => ['id_especialidad' => '9007199254740993']], '9007199254740993'],
            'activacion' => ['Paciente', ['tipo_evento' => 'ACTIVACION_PACIENTE_EMITIDA', 'cambios_seguros' => ['id_paciente' => '7']], '7'],
            'vinculacion iniciada' => ['Paciente', ['tipo_evento' => 'VINCULACION_PACIENTE_INICIADA', 'cambios_seguros' => ['id_paciente' => '7']], '7'],
            'vinculacion completada' => ['Paciente', ['tipo_evento' => 'PACIENTE_CUENTA_VINCULADA', 'cambios_seguros' => ['id_paciente' => '7']], '7'],
            'cuenta prueba' => ['CuentaPrueba', ['entidad_referida' => 'CuentaPrueba', 'registro_referido' => 'DEMO-ADMIN'], 'DEMO-ADMIN'],
            'demostracion' => ['ConjuntoDemostracion', ['entidad_referida' => 'ConjuntoDemostracion', 'registro_referido' => 'DEMO-VS-V1'], 'DEMO-VS-V1'],
        ];
    }

    public function test_user_resource_is_target_not_responsible_and_branches_do_not_mix_entities(): void
    {
        [$cookies, $actor] = $this->administrador();
        $objetivo = $this->usuarioAuth();
        $id = $this->evento(['id_usuario_objetivo' => $objetivo->getKey(), 'rol_objetivo' => 'Administrador',
            'entidad_referida' => 'Medico', 'registro_referido' => (string) $actor->getKey()]);
        $this->evento(['tipo_actor' => 'USUARIO', 'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => 'Administrador']);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => 'Usuario', 'registro_referido' => (string) $objetivo->getKey()])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_evento', $id);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => 'Usuario', 'registro_referido' => (string) $actor->getKey()])
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_historical_references_neither_match_nor_break_queries_or_leak_in_projection(): void
    {
        [$cookies] = $this->administrador();
        foreach ([true, false, null, [], (object) ['id' => 7], '0', '-1', '7.5', '9223372036854775808', str_repeat('9', 100), '7 OR 1=1'] as $valor) {
            $this->evento(['tipo_evento' => 'MEDICO_ACTUALIZADO', 'referencias' => ['id_medico' => $valor]]);
        }
        $this->evento(['cambios_seguros' => ['id_paciente' => '7']]);
        $this->evento(['referencias' => ['id_especialidad' => '8'], 'entidad_referida' => 'Medico', 'registro_referido' => '7']);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => 'Paciente'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => 'Especialidad', 'registro_referido' => '7'])
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'entidad_referida' => 'Medico'])->assertOk()->assertJsonPath('meta.total', 1);
        $r = $this->listar($cookies, ['modulo' => 'PRUEBA', 'tipo_evento' => 'MEDICO_ACTUALIZADO'])->assertOk();
        foreach ($r->json('data') as $fila) {
            $this->assertSame([], $fila['referencias']);
        }
        $this->assertStringContainsString('"referencias":{}', $r->getContent());
    }

    public function test_unknown_formats_causes_and_physical_resources_are_projected_conservatively(): void
    {
        [$cookies] = $this->administrador();
        $id = $this->evento(['tipo_evento' => 'MEDICO_ACTUALIZADO', 'version_formato' => 2, 'referencias' => ['id_medico' => '7'],
            'causa_codigo' => 'SECRETO_FICTICIO', 'entidad_referida' => 'RutaPrivada', 'registro_referido' => 'secreto-ficticio']);
        $r = $this->listar($cookies, ['modulo' => 'PRUEBA'])->assertOk()->assertJsonPath('data.0.id_evento', $id)
            ->assertJsonPath('data.0.causa_codigo', null)->assertJsonPath('data.0.entidad_referida', null)
            ->assertJsonPath('data.0.registro_referido', null);
        $this->assertStringContainsString('"referencias":{}', $r->getContent());
        $this->assertStringNotContainsString('secreto-ficticio', $r->getContent());
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_invalid_filters_are_safe_422(array $filtros): void
    {
        [$cookies] = $this->administrador();
        $this->listar($cookies, $filtros)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDACION_ERROR')
            ->assertJsonStructure(['error' => ['details']])->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function filtrosInvalidos(): array
    {
        return array_map(fn ($filtro) => [$filtro], [
            ['fecha_desde' => '2026-02-30'], ['fecha_desde' => '0000-01-01'], ['fecha_hasta' => '21-10-2026'],
            ['fecha_desde' => '2026-10-22', 'fecha_hasta' => '2026-10-21'], ['fecha_desde' => '2026-10-21T00:00:00Z'],
            ['fecha_hasta' => ['2026-10-21']], ['id_usuario_responsable' => '0'], ['id_usuario_objetivo' => '9223372036854775808'],
            ['id_usuario_objetivo' => '1 OR 1=1'], ['tipo_actor' => 'Administrador'], ['resultado' => 'OK'], ['resultado' => ['EXITO']],
            ['modulo' => ''], ['modulo' => "VALOR\nINTERNO"], ['modulo' => str_repeat('x', 41)], ['operacion' => str_repeat('x', 61)],
            ['tipo_evento' => str_repeat('x', 81)], ['direccion_ip' => '192.0.2.1/24'], ['direccion_ip' => 'invalida'],
            ['entidad_referida' => 'ConsultaMedica'], ['entidad_referida' => ['Paciente']], ['registro_referido' => '7'],
            ['entidad_referida' => 'Paciente', 'registro_referido' => '0'], ['entidad_referida' => 'Paciente', 'registro_referido' => '9223372036854775808'],
            ['entidad_referida' => 'Paciente', 'registro_referido' => ['7']], ['entidad_referida' => 'CuentaPrueba', 'registro_referido' => '../privado'],
            ['entidad_referida' => 'Paciente', 'registro_referido' => ''], ['page' => 0], ['page' => 1000001],
            ['per_page' => 101], ['per_page' => 0], ['sort' => 'registrado_en'], ['order' => 'asc'], ['desconocido' => 'valor'],
        ]);
    }

    #[DataProvider('otrosRoles')]
    public function test_only_normal_administrator_can_list_even_with_invalid_filters(string $rol): void
    {
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->listar($cookies, ['per_page' => 0])->assertForbidden()->assertJsonPath('error.code', 'ACCESO_DENEGADO');
    }

    public static function otrosRoles(): array
    {
        return [['Recepcionista'], ['Médico'], ['Paciente']];
    }

    public function test_missing_revoked_and_expired_sessions_do_not_reach_list(): void
    {
        $cookies = $this->csrfAuth();
        $this->listar($cookies, ['per_page' => 0])->assertUnauthorized();
        [$cookies, $actor] = $this->administrador();
        DB::table('usuarios')->where('id_usuario', $actor->getKey())->update(['estado_cuenta' => 'Desactivada']);
        $this->listar($cookies)->assertUnauthorized();
        [$cookies, $actor] = $this->administrador();
        DB::table('sessions')->where('user_id', $actor->getKey())->update(['last_activity' => now()->subDays(2)->timestamp]);
        $this->listar($cookies)->assertUnauthorized();
    }

    public function test_service_also_requires_administrator_when_called_directly(): void
    {
        $actor = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('No tienes permiso');
        $this->app->make(BitacoraService::class)->listar([]);
    }

    public function test_restricted_temporary_session_cannot_list_and_normal_session_is_revoked(): void
    {
        [$admin] = $this->administrador();
        $objetivo = $this->usuarioAuth();
        $normal = $this->csrfAuth();
        $this->loginAuth($normal, $objetivo)->assertOk();
        $secreto = $this->navegador($admin, 'POST', '/api/usuarios/'.$objetivo->getKey().'/credencial-temporal',
            ['identidad_verificada' => true])->assertCreated()->json('data.credencial_temporal');
        $this->listar($normal)->assertUnauthorized();
        $restringida = $this->csrfAuth();
        $this->loginAuth($restringida, $objetivo, $secreto)->assertOk()->assertJsonPath('data.acceso', 'cambio_clave');
        $this->listar($restringida, ['per_page' => 0])->assertForbidden()->assertJsonPath('error.code', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO');
    }

    public function test_pagination_accepts_maximum_and_defaults_to_twenty(): void
    {
        [$cookies] = $this->administrador();
        for ($i = 0; $i < 21; $i++) {
            $this->evento();
        }
        $this->listar($cookies, ['modulo' => 'PRUEBA'])->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->listar($cookies, ['modulo' => 'PRUEBA', 'per_page' => 100])->assertOk()->assertJsonCount(21, 'data')->assertJsonPath('meta.per_page', 100);
    }

    public function test_unexpected_query_error_is_safe_and_uses_central_contract(): void
    {
        [$cookies] = $this->administrador();
        $repository = \Mockery::mock(BitacoraRepository::class);
        $repository->shouldReceive('listar')->once()->andThrow(new \RuntimeException('SQL secreto-ficticio /ruta/privada'));
        $this->app->instance(BitacoraRepository::class, $repository);
        $r = $this->listar($cookies)->assertInternalServerError()->assertExactJson([
            'success' => false, 'error' => ['code' => 'ERROR_INTERNO', 'message' => 'No se pudo completar la operación.'],
        ]);
        $this->assertStringNotContainsString('secreto-ficticio', $r->getContent());
    }
}
