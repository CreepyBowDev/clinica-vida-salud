<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\SuscripcionPush;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class SuscripcionesPushTest extends TestCase
{
    use AutenticacionFixtures { navegador as private navegadorBase; }
    use CitasFixtures;

    private function navegador(array &$cookies, string $metodo, string $uri, array $datos = [], array $headers = [], bool $csrf = true): TestResponse
    {
        // El kernel se reutiliza en tests; una respuesta anterior no es cookie de otro navegador.
        Cookie::unqueue(config('push.cookie'), '/');

        return $this->navegadorBase($cookies, $metodo, $uri, $datos, $headers, $csrf);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        $schema = 'prueba_push_'.strtolower(Str::random(16));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        CarbonImmutable::setTestNow('2026-10-31T12:00:00Z');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'push.habilitado' => true,
            'push.vapid_publica' => $this->publicKey()]);
        $this->app['env'] = 'local';
    }

    private function publicKey(): string
    {
        // Punto generador público P-256; no clave privada ni dependencia del openssl.cnf local.
        return $this->base64url(hex2bin('046b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c2964fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5'));
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function payload(string $path = 'ficticio'): array
    {
        return ['permiso_otorgado' => true, 'suscripcion' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$path,
            'expirationTime' => null, 'keys' => ['p256dh' => $this->publicKey(), 'auth' => $this->base64url(random_bytes(16))]]];
    }

    private function paciente(): array
    {
        $u = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $u)->assertOk();

        return [$u, $cookies];
    }

    public function test_opt_in_is_encrypted_cookie_bound_idempotent_and_metadata_only(): void
    {
        [$u, $c] = $this->paciente();
        $payload = $this->payload();
        $r = $this->navegador($c, 'PUT', '/api/push/suscripcion', $payload)->assertCreated()
            ->assertJsonPath('data.activa', true)->assertJsonPath('data.actual', true)->assertJsonPath('data.version', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        $cookie = collect($r->headers->getCookies())->first(fn ($v) => $v->getName() === config('push.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertStringNotContainsString('ficticio', $cookie->getValue());
        $raw = DB::table('suscripciones_push')->sole();
        $this->assertStringNotContainsString($payload['suscripcion']['endpoint'], $raw->datos_cifrados);
        $this->assertStringNotContainsString($payload['suscripcion']['keys']['auth'], $raw->datos_cifrados);
        $this->assertSame($payload['suscripcion'], SuscripcionPush::query()->sole()->datos_cifrados);
        $this->assertArrayNotHasKey('datos_cifrados', SuscripcionPush::query()->sole()->toArray());
        $before = DB::table('suscripciones_push')->sole();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $payload)->assertOk()->assertExactJson($r->json());
        $this->assertEquals($before, DB::table('suscripciones_push')->sole());
        $list = $this->navegador($c, 'GET', '/api/push/suscripciones?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(['id_suscripcion', 'actual', 'activa', 'version', 'autorizada_en', 'revocada_en'], array_keys($list->json('data.0')));
        $this->assertSame((string) $u->getKey(), (string) $raw->id_usuario);
        $this->assertSame(0, DB::table('preferencias_paciente')->count());
        $this->assertSame(0, DB::table('recordatorios')->count());
        $this->assertSame(0, DB::table('envios_recordatorio_push')->count());
        $audit = DB::table('bitacora')->where('tipo_evento', 'PUSH_AUTORIZADO')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString($payload['suscripcion']['endpoint'], $audit->toJson());
        $this->assertStringNotContainsString($payload['suscripcion']['keys']['auth'], $audit->toJson());
        Http::assertNothingSent();
    }

    public function test_configuration_disabled_does_not_prevent_listing_or_revocation(): void
    {
        [, $c] = $this->paciente();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated();
        config(['push.habilitado' => false, 'push.vapid_publica' => 'PRIVADO-NO-EXPONER']);
        $this->navegador($c, 'GET', '/api/push/configuracion')->assertExactJson(['data' => ['habilitado' => false, 'clave_publica_vapid' => null]]);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertStatus(503)->assertJsonPath('error.code', 'PUSH_NO_CONFIGURADO');
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertOk();
        $this->navegador($c, 'DELETE', '/api/push/suscripcion')->assertNoContent();
        $this->navegador($c, 'DELETE', '/api/push/suscripcion')->assertNoContent();
        $this->assertNotNull(DB::table('suscripciones_push')->sole()->revocada_en);
    }

    #[DataProvider('rolesAjenos')]
    public function test_roles_cannot_manage_patient_devices(string $rol): void
    {
        $u = $this->usuarioAuth($rol);
        $c = $this->csrfAuth();
        $this->loginAuth($c, $u)->assertOk();
        foreach (['GET /api/push/configuracion', 'GET /api/push/suscripciones', 'PUT /api/push/suscripcion',
            'DELETE /api/push/suscripcion', 'DELETE /api/push/suscripciones/1'] as $route) {
            [$method, $uri] = explode(' ', $route);
            $this->navegador($c, $method, $uri, $method === 'PUT' ? $this->payload() : [])->assertForbidden();
        }
        $this->assertSame(0, DB::table('suscripciones_push')->count());
    }

    public static function rolesAjenos(): array
    {
        return [['Administrador'], ['Recepcionista'], ['Médico']];
    }

    #[DataProvider('endpointsInvalidos')]
    public function test_endpoint_validation_blocks_ssrf_without_network(string $endpoint): void
    {
        [, $c] = $this->paciente();
        $p = $this->payload();
        $p['suscripcion']['endpoint'] = $endpoint;
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertUnprocessable();
        $this->assertArrayNotHasKey(config('push.cookie'), $c);
        $this->assertSame(0, DB::table('suscripciones_push')->count());
        Http::assertNothingSent();
    }

    public static function endpointsInvalidos(): array
    {
        return array_map(fn ($s) => [$s], ['http://fcm.googleapis.com/fcm/send/a', 'https://localhost/a', 'https://127.0.0.1/a',
            'https://[::1]/a', 'https://169.254.169.254/a', 'https://fcm.googleapis.com.evil.test/a', 'https://evil.test@fcm.googleapis.com/a',
            'https://fcm.googleapis.com:8443/a', 'https://fcm.googleapis.com/a#x', 'https://fcm.googleapis.com./a', 'https://fcm.googleapis.com/',
            'https://fcm.googleapis.com\\@evil.test/a', 'https://fcm.googleapis.com/a b']);
    }

    #[DataProvider('entradasInvalidas')]
    public function test_strict_input_keys_and_expiration(string $case): void
    {
        [, $c] = $this->paciente();
        $p = $this->payload();
        match ($case) {
            'flag' => $p['permiso_otorgado'] = 'true',
            'numero' => $p['permiso_otorgado'] = 1,
            'extra' => $p['id_usuario'] = 999,
            'nested' => $p['suscripcion']['id_paciente'] = 999,
            'keyextra' => $p['suscripcion']['keys']['privada'] = 'secreto',
            'badkey' => $p['suscripcion']['keys']['p256dh'] = str_repeat('A', 87),
            'badauth' => $p['suscripcion']['keys']['auth'] = str_repeat('=', 22),
            'expired' => $p['suscripcion']['expirationTime'] = now()->subMinute()->getTimestampMs(),
        };
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertStatus($case === 'expired' ? 409 : 422);
        $this->assertSame(0, DB::table('suscripciones_push')->count());
    }

    public static function entradasInvalidas(): array
    {
        return array_map(fn ($s) => [$s], ['flag', 'numero', 'extra', 'nested', 'keyextra', 'badkey', 'badauth', 'expired']);
    }

    public function test_session_csrf_origin_queries_bodies_and_head_are_enforced(): void
    {
        $anon = $this->csrfAuth();
        $this->navegador($anon, 'GET', '/api/push/configuracion')->assertUnauthorized();
        [, $c] = $this->paciente();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload(), csrf: false)->assertStatus(419);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload(), ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->navegador($c, 'PUT', '/api/push/suscripcion?x=1', $this->payload())->assertUnprocessable();
        $this->navegador($c, 'GET', '/api/push/suscripciones?id_usuario=1')->assertUnprocessable();
        $this->navegador($c, 'GET', '/api/push/configuracion', ['x' => 'y'])->assertUnprocessable();
        $this->navegador($c, 'DELETE', '/api/push/suscripcion?page=1')->assertUnprocessable();
        $this->navegador($c, 'GET', '/api/push/suscripciones?per_page=101')->assertUnprocessable();
        $this->navegador($c, 'HEAD', '/api/push/configuracion')->assertOk()->assertContent('');
        $this->assertSame(0, DB::table('suscripciones_push')->count());
    }

    public function test_logout_only_revokes_this_browser_and_new_login_requires_consent(): void
    {
        [$u, $c] = $this->paciente();
        $p = $this->payload('uno');
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated();
        $other = $this->csrfAuth();
        $this->loginAuth($other, $u)->assertOk();
        $this->navegador($other, 'PUT', '/api/push/suscripcion', $this->payload('dos'))->assertCreated();
        $this->navegador($c, 'POST', '/api/auth/logout')->assertNoContent();
        $this->assertSame(1, DB::table('suscripciones_push')->whereNull('revocada_en')->count());
        $this->loginAuth($c, $u)->assertOk();
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertJsonPath('data.1.activa', false)->assertJsonPath('data.1.actual', true);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.activa', true);
    }

    public function test_expired_session_is_not_voluntary_logout_and_changing_account_revokes_without_takeover(): void
    {
        [$u, $c] = $this->paciente();
        $p = $this->payload();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated();
        DB::table('sessions')->where('user_id', $u->getKey())->delete();
        $this->navegador($c, 'GET', '/api/push/configuracion')->assertUnauthorized();
        $this->assertNull(DB::table('suscripciones_push')->sole()->revocada_en);
        $v = $this->usuarioAuth('Paciente');
        $this->loginAuth($c, $v)->assertOk();
        $this->assertNotNull(DB::table('suscripciones_push')->sole()->revocada_en);
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertJsonPath('meta.total', 0);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame((string) $v->getKey(), (string) DB::table('suscripciones_push')->sole()->id_usuario);
    }

    public function test_other_cookie_or_unknown_installation_cannot_steal_endpoint_or_revoke_others(): void
    {
        [$u, $c] = $this->paciente();
        $p = $this->payload();
        $id = $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated()->json('data.id_suscripcion');
        [, $other] = $this->paciente();
        $this->navegador($other, 'PUT', '/api/push/suscripcion', $p)->assertConflict();
        $this->navegador($other, 'DELETE', '/api/push/suscripciones/'.$id)->assertNotFound();
        $this->navegador($other, 'DELETE', '/api/push/suscripcion')->assertNoContent();
        $this->navegador($other, 'GET', '/api/push/suscripciones')->assertJsonPath('meta.total', 0);
        unset($c[config('push.cookie')]);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertConflict();
        $this->assertNull(DB::table('suscripciones_push')->sole()->revocada_en);
        $this->assertSame((string) $u->getKey(), (string) DB::table('suscripciones_push')->sole()->id_usuario);
    }

    public function test_remote_revocation_and_renewal_are_explicit_and_versioned(): void
    {
        [, $c] = $this->paciente();
        $id = $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated()->json('data.id_suscripcion');
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload('renovado'))->assertOk()->assertJsonPath('data.version', 2);
        $this->navegador($c, 'DELETE', '/api/push/suscripciones/'.$id)->assertNoContent();
        $this->navegador($c, 'DELETE', '/api/push/suscripciones/'.$id)->assertNoContent();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload('renovado'))->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_disabling_account_revokes_devices_and_reactivation_does_not_restore_them(): void
    {
        [$u, $c] = $this->paciente();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated();
        $admin = $this->usuarioAuth('Administrador');
        $a = $this->csrfAuth();
        $this->loginAuth($a, $admin)->assertOk();
        $this->navegador($a, 'PATCH', '/api/usuarios/'.$u->getKey().'/estado', ['estado_cuenta' => 'Desactivada'])->assertOk();
        $this->assertNotNull(DB::table('suscripciones_push')->sole()->revocada_en);
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertUnauthorized();
        $this->navegador($a, 'PATCH', '/api/usuarios/'.$u->getKey().'/estado', ['estado_cuenta' => 'Activa'])->assertOk();
        $this->loginAuth($c, $u)->assertOk();
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertJsonPath('data.0.activa', false);
    }

    public function test_failed_save_rolls_back_and_does_not_issue_installation_cookie(): void
    {
        [, $c] = $this->paciente();
        $repository = Mockery::mock(SuscripcionPushRepository::class)->makePartial();
        $repository->shouldReceive('guardar')->andReturnUsing(function ($s): void {
            $s->saveOrFail();
            throw new RuntimeException('PRIVADO');
        });
        $this->app->instance(SuscripcionPushRepository::class, $repository);
        $r = $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertStatus(500);
        $this->assertStringNotContainsString('PRIVADO', $r->getContent());
        $this->assertSame(0, DB::table('suscripciones_push')->count());
        $this->assertArrayNotHasKey(config('push.cookie'), $c);
    }

    public function test_audit_failure_rolls_back_subscription_and_never_queues_cookie(): void
    {
        [, $c] = $this->paciente();
        $audit = Mockery::mock(BitacoraRepository::class)->makePartial();
        $audit->shouldReceive('suscripcionPushModificada')->andThrow(new RuntimeException('PRIVADO'));
        $this->app->instance(BitacoraRepository::class, $audit);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertStatus(500);
        $this->assertSame(0, DB::table('suscripciones_push')->count());
        $this->assertArrayNotHasKey(config('push.cookie'), $c);
    }

    public function test_cookie_tampering_or_expiry_cannot_adopt_an_existing_endpoint(): void
    {
        [, $c] = $this->paciente();
        $p = $this->payload();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated();
        $original = $c[config('push.cookie')];
        $c[config('push.cookie')] = 'fabricada-sin-firma';
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertConflict();
        $c[config('push.cookie')] = $original;
        CarbonImmutable::setTestNow(now()->addDays(181));
        // Cookie de instalación caducada aunque se vuelva a autenticar con la cookie antigua presente.
        $u = Usuario::query()->sole();
        $this->navegador($c, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->loginAuth($c, $u)->assertOk();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertConflict();
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertJsonPath('data.0.activa', false)->assertJsonPath('data.0.actual', false);
        $this->assertSame(1, DB::table('suscripciones_push')->sole()->version_autorizacion);
    }

    public function test_reordered_json_is_idempotent_and_transport_expiration_is_honored(): void
    {
        [, $c] = $this->paciente();
        $p = $this->payload();
        $p['suscripcion']['expirationTime'] = now()->addHour()->getTimestampMs();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated();
        $p['suscripcion'] = array_reverse($p['suscripcion'], true);
        $p['suscripcion']['keys'] = array_reverse($p['suscripcion']['keys'], true);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertOk()->assertJsonPath('data.version', 1);
        CarbonImmutable::setTestNow(now()->addHours(2));
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertJsonPath('data.0.activa', false);
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertConflict();
    }

    public function test_rate_limit_returns_wait_without_extra_writes(): void
    {
        [, $c] = $this->paciente();
        for ($i = 0; $i < 60; $i++) {
            $this->navegador($c, 'GET', '/api/push/configuracion')->assertOk();
        }
        $this->navegador($c, 'GET', '/api/push/configuracion')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, DB::table('suscripciones_push')->count());
    }

    public function test_password_change_revokes_push_and_keeps_current_session_without_reauthorizing(): void
    {
        [, $c] = $this->paciente();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated();
        $password = 'Nueva frase ficticia segura 456!';
        $this->navegador($c, 'PUT', '/api/auth/password', ['current_password' => self::PASSWORD,
            'password' => $password, 'password_confirmation' => $password])->assertNoContent();
        $this->navegador($c, 'GET', '/api/push/suscripciones')->assertOk()->assertJsonPath('data.0.activa', false);
        $this->assertNotNull(DB::table('suscripciones_push')->sole()->revocada_en);
    }

    public function test_production_cookie_is_secure_and_get_does_not_create_installation(): void
    {
        [, $c] = $this->paciente();
        $this->navegador($c, 'GET', '/api/push/configuracion')->assertOk();
        $this->assertArrayNotHasKey(config('push.cookie'), $c);
        $this->app['env'] = 'production';
        $r = $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated();
        $cookie = collect($r->headers->getCookies())->first(fn ($v) => $v->getName() === config('push.cookie'));
        $this->assertTrue($cookie->isSecure());
    }

    private function envio(string $suscripcion, string $usuario, string $paciente): array
    {
        $r = $this->citaResources();
        $cita = DB::table('citas')->insertGetId($this->citaRow($r), 'id_cita');
        $recordatorio = DB::table('recordatorios')->insertGetId(['id_cita' => $cita, 'version_programacion' => 1,
            'generado_en' => now()], 'id_recordatorio');

        return ['id_recordatorio' => $recordatorio, 'id_suscripcion' => $suscripcion, 'id_usuario' => $usuario,
            'id_paciente' => $paciente, 'version_autorizacion' => 1, 'estado' => 'Pendiente', 'intentos' => 0,
            'proximo_intento_en' => now(), 'creado_en' => now(), 'actualizado_en' => now()];
    }

    public function test_revocation_discards_outbox_but_preserves_original_recipient_on_reassignment(): void
    {
        [$u, $c] = $this->paciente();
        $p = $this->payload();
        $id = $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertCreated()->json('data.id_suscripcion');
        $row = $this->envio($id, (string) $u->getKey(), (string) $u->paciente->getKey());
        DB::table('envios_recordatorio_push')->insert($row);
        $this->navegador($c, 'POST', '/api/auth/logout')->assertNoContent();
        $e = DB::table('envios_recordatorio_push')->sole();
        $this->assertSame('Descartado', $e->estado);
        $v = $this->usuarioAuth('Paciente');
        $this->loginAuth($c, $v)->assertOk();
        $this->navegador($c, 'PUT', '/api/push/suscripcion', $p)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertEquals($e, DB::table('envios_recordatorio_push')->sole());
        $this->assertSame((string) $u->getKey(), (string) $e->id_usuario);
        $this->assertSame(1, $e->version_autorizacion);
    }

    public function test_sql_uniqueness_foreign_keys_and_state_constraints_are_effective(): void
    {
        [$u, $c] = $this->paciente();
        $id = $this->navegador($c, 'PUT', '/api/push/suscripcion', $this->payload())->assertCreated()->json('data.id_suscripcion');
        $row = $this->envio($id, (string) $u->getKey(), (string) $u->paciente->getKey());
        DB::table('envios_recordatorio_push')->insert($row);
        foreach ([['id_suscripcion' => 999999], ['id_usuario' => 999999], ['id_paciente' => 999999], ['id_recordatorio' => 999999],
            ['estado' => 'Entregado'], ['estado' => 'Procesando'], ['intentos' => 4], ['version_autorizacion' => 0], []] as $changes) {
            try {
                DB::transaction(fn () => $changes === [] ? DB::table('envios_recordatorio_push')->insert($row)
                    : DB::table('envios_recordatorio_push')->update($changes));
                $this->fail('SQL debía rechazar el envío inválido.');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23503', '23505', '23514']);
            }
        }
        $s = (array) DB::table('suscripciones_push')->sole();
        unset($s['id_suscripcion']);
        foreach ([['instalacion_hash' => str_repeat('1', 64)], ['endpoint_hash' => str_repeat('2', 64)], ['version_autorizacion' => 0], ['huella_cuenta' => 'invalido']] as $changes) {
            try {
                DB::transaction(fn () => DB::table('suscripciones_push')->insert(array_replace($s, $changes)));
                $this->fail('SQL debía rechazar la suscripción inválida.');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23505', '23514']);
            }
        }
        foreach (['suscripciones_push', 'envios_recordatorio_push'] as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertSame('restrict', $fk['on_delete']);
                $this->assertSame('restrict', $fk['on_update']);
            }
        }
    }

    public function test_migration_down_up_preserves_all_previous_tables(): void
    {
        $before = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename NOT IN ('suscripciones_push','envios_recordatorio_push') ORDER BY tablename");
        $sub = require base_path('src/data/database/migrations/2026_10_31_000038_create_suscripciones_push_table.php');
        $outbox = require base_path('src/data/database/migrations/2026_10_31_000039_create_envios_recordatorio_push_table.php');
        $outbox->down();
        $sub->down();
        $this->assertFalse(Schema::hasTable('suscripciones_push'));
        $sub->up();
        $outbox->up();
        $this->assertEquals($before, DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename NOT IN ('suscripciones_push','envios_recordatorio_push') ORDER BY tablename"));
        $this->assertSame(0, DB::table('suscripciones_push')->count());
    }
}
