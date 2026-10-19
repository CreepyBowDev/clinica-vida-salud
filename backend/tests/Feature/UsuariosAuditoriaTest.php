<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\Bitacora;
use Clinica\data\models\Rol;
use Clinica\data\models\TokenAcceso;
use Clinica\data\models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsuariosAuditoriaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_new_tables_have_exactly_the_dictionary_columns_and_start_empty(): void
    {
        $columns = [
            'usuarios' => ['id_usuario', 'id_rol', 'correo_acceso', 'password_hash', 'estado_cuenta', 'correo_verificado_en', 'cambio_clave_obligatorio', 'intentos_fallidos', 'bloqueado_hasta', 'creado_en', 'actualizado_en', 'nombres_personal', 'apellidos_personal'],
            'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
            'tokens_acceso' => ['id_token', 'id_usuario', 'proposito', 'token_hash', 'emitido_en', 'expira_en', 'consumido_en', 'invalidado_en'],
            'bitacora' => ['id_evento', 'tipo_evento', 'tipo_actor', 'id_usuario_responsable', 'rol_responsable', 'id_usuario_objetivo', 'rol_objetivo', 'modulo', 'operacion', 'resultado', 'causa_codigo', 'ocurrido_en', 'registrado_en', 'direccion_ip', 'procedencia_ip', 'entidad_referida', 'registro_referido', 'correlacion', 'id_evento_relacionado', 'version_formato', 'cambios_seguros', 'metadatos_seguros', 'referencias'],
        ];

        foreach ($columns as $table => $expected) {
            $this->assertSame($expected, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
        }

        $this->assertSame(count(glob(base_path('src/data/database/migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_eloquent_provider_uses_the_approved_identifier_and_password_column(): void
    {
        $user = $this->createUser('  PERSONAL@EXAMPLE.TEST  ');
        $provider = Auth::createUserProvider('usuarios');
        $loaded = $provider->retrieveById($user->getKey());

        $this->assertInstanceOf(Usuario::class, $loaded);
        $this->assertSame('id_usuario', $loaded->getAuthIdentifierName());
        $this->assertSame('password_hash', $loaded->getAuthPasswordName());
        $this->assertSame('personal@example.test', $loaded->correo_acceso);
        $this->assertTrue($provider->validateCredentials($loaded, ['password' => 'Frase ficticia de prueba 123']));
        $this->assertFalse($provider->validateCredentials($loaded, ['password' => 'Otra frase incorrecta']));
        $this->assertSame('', $loaded->getRememberTokenName());
        $this->assertArrayNotHasKey('password_hash', $loaded->toArray());
        $this->assertArrayNotHasKey('intentos_fallidos', $loaded->toArray());
        $this->assertInstanceOf(CarbonImmutable::class, $loaded->creado_en);
        $this->assertSame($user->rol->getKey(), $loaded->rol->getKey());
        $this->assertTrue($user->rol->usuarios->contains($user));
    }

    public function test_pending_invitation_can_have_no_password_and_active_account_requires_verification(): void
    {
        $row = $this->userRow('invitacion@example.test');
        unset($row['password_hash'], $row['estado_cuenta'], $row['correo_verificado_en']);
        $id = DB::table('usuarios')->insertGetId($row, 'id_usuario');
        $user = Usuario::query()->findOrFail($id);

        $this->assertSame('Pendiente', $user->estado_cuenta);
        $this->assertNull($user->getAuthPassword());
        $this->assertNull($user->correo_verificado_en);
        $this->assertSame(0, $user->intentos_fallidos);
        $this->assertFalse($user->cambio_clave_obligatorio);
        $this->assertNull($user->bloqueado_hasta);

        $this->assertRejected(
            fn () => DB::table('usuarios')->where('id_usuario', $id)->update(['estado_cuenta' => 'Activa']),
            '23514',
        );
    }

    public function test_mass_assignment_cannot_promote_or_activate_an_account_or_issue_a_token(): void
    {
        $user = new Usuario([
            'correo_acceso' => '  PACIENTE@EXAMPLE.TEST  ',
            'id_usuario' => 500,
            'id_rol' => 500,
            'estado_cuenta' => 'Activa',
            'password_hash' => 'valor no aceptado',
            'correo_verificado_en' => now(),
            'intentos_fallidos' => 99,
        ]);

        $this->assertSame('paciente@example.test', $user->correo_acceso);
        $this->assertNull($user->getKey());
        $this->assertNull($user->id_rol);
        $this->assertNull($user->password_hash);
        $this->assertNull($user->correo_verificado_en);
        $this->assertSame('Pendiente', $user->estado_cuenta);
        $this->assertSame(0, $user->intentos_fallidos);
        $this->assertSame([], (new TokenAcceso)->getFillable());
        $this->assertSame([], (new Bitacora)->getFillable());
    }

    public function test_user_token_and_audit_relationships_preserve_distinct_actors(): void
    {
        $actor = $this->createUser('responsable@example.test');
        $target = $this->createUser('objetivo@example.test');
        $token = new TokenAcceso;
        $token->forceFill($this->tokenRow($target->getKey()));
        $token->save();
        $token->refresh();

        $this->assertSame($target->getKey(), $token->usuario->getKey());
        $this->assertSame($token->getKey(), $target->tokensAcceso->sole()->getKey());
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertInstanceOf(CarbonImmutable::class, $token->expira_en);
        $this->assertNull($token->consumido_en);
        $this->assertNull($token->invalidado_en);

        $previous = $this->insertEvent();
        $event = new Bitacora;
        $event->forceFill($this->eventRow([
            'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(),
            'rol_responsable' => 'Recepcionista',
            'id_usuario_objetivo' => $target->getKey(),
            'rol_objetivo' => 'Recepcionista',
            'id_evento_relacionado' => $previous,
            'direccion_ip' => '2001:db8::1',
            'procedencia_ip' => 'DIRECTA',
            'metadatos_seguros' => (object) ['referencia_prueba' => 1],
        ]));
        $event->save();
        $event->refresh();

        $this->assertSame($actor->getKey(), $event->responsable->getKey());
        $this->assertSame($target->getKey(), $event->objetivo->getKey());
        $this->assertSame($event->getKey(), $actor->eventosComoResponsable->sole()->getKey());
        $this->assertSame($event->getKey(), $target->eventosComoObjetivo->sole()->getKey());
        $this->assertSame($previous, $event->eventoRelacionado->getKey());
        $this->assertSame($event->getKey(), $event->eventoRelacionado->eventosPosteriores->sole()->getKey());
        $this->assertSame('2001:db8::1', $event->direccion_ip);
        $this->assertSame(1, $event->version_formato);
        $this->assertSame(1, $event->metadatos_seguros->referencia_prueba);
        $this->assertInstanceOf(CarbonImmutable::class, $event->registrado_en);
    }

    public function test_token_hash_is_unique_across_accounts_and_purposes(): void
    {
        $first = $this->createUser('token-uno@example.test');
        $second = $this->createUser('token-dos@example.test');
        $row = $this->tokenRow($first->getKey());
        DB::table('tokens_acceso')->insert($row);

        $this->assertRejected(fn () => DB::table('tokens_acceso')->insert(array_replace($row, [
            'id_usuario' => $second->getKey(),
            'proposito' => 'RecuperacionClave',
            'expira_en' => '2026-10-19T13:00:00Z',
        ])), '23505', 'tokens_acceso_hash_uq');

        $this->assertRejected(fn () => DB::table('tokens_acceso')->insert(array_replace($row, [
            'id_usuario' => $second->getKey(),
            'proposito' => 'CredencialTemporal',
        ])), '23505', 'tokens_acceso_hash_uq');
    }

    public function test_temporary_credential_migration_preserves_existing_tokens_and_needs_no_new_columns(): void
    {
        $migration = require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php');
        $user = $this->createUser('ampliacion-token@example.test');
        $columns = Schema::getColumnListing('tokens_acceso');

        DB::table('tokens_acceso')->insert([
            array_replace($this->tokenRow($user->getKey()), ['consumido_en' => null]),
            array_replace($this->tokenRow($user->getKey()), [
                'proposito' => 'RecuperacionClave',
                'expira_en' => '2026-10-19T13:00:00Z',
                'consumido_en' => '2026-10-19T12:30:00Z',
            ]),
        ]);
        $before = DB::table('tokens_acceso')->orderBy('id_token')->get()->toJson();

        $migration->down();
        $this->assertRejected(fn () => DB::table('tokens_acceso')->insert(array_replace(
            $this->tokenRow($user->getKey()),
            ['proposito' => 'CredencialTemporal'],
        )), '23514', 'tokens_acceso_proposito_ck');

        $migration->up();
        $this->assertSame($columns, Schema::getColumnListing('tokens_acceso'));
        $this->assertSame($before, DB::table('tokens_acceso')->orderBy('id_token')->get()->toJson());

        $token = new TokenAcceso;
        $token->forceFill(array_replace($this->tokenRow($user->getKey()), [
            'proposito' => 'CredencialTemporal',
            // Instante de prueba, no define la vigencia funcional del procedimiento.
            'expira_en' => '2026-10-19T12:17:00Z',
        ]));
        $token->save();
        $token->refresh();

        $this->assertSame('CredencialTemporal', $token->proposito);
        $this->assertSame($user->getKey(), $token->usuario->getKey());
        $this->assertInstanceOf(CarbonImmutable::class, $token->expira_en);
        $this->assertSame('2026-10-19T12:17:00+00:00', $token->expira_en->toIso8601String());
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertNull($token->consumido_en);
        $this->assertNull($token->invalidado_en);
    }

    #[DataProvider('temporaryCredentialHistory')]
    public function test_downgrade_rejects_existing_temporary_credentials_without_losing_history(array $history): void
    {
        $migration = require base_path('src/data/database/migrations/2026_10_19_000029_allow_temporary_credentials_in_tokens_acceso.php');
        $user = $this->createUser('historia-token@example.test');
        DB::table('tokens_acceso')->insert(array_replace($this->tokenRow($user->getKey()), [
            'proposito' => 'CredencialTemporal',
        ], $history));
        $before = DB::table('tokens_acceso')->orderBy('id_token')->get()->toJson();

        $this->assertRejected(fn () => $migration->down(), '23514', 'tokens_acceso_proposito_ck');
        $this->assertSame($before, DB::table('tokens_acceso')->orderBy('id_token')->get()->toJson());

        DB::table('tokens_acceso')->insert(array_replace($this->tokenRow($user->getKey()), [
            'proposito' => 'CredencialTemporal',
        ]));
        $this->assertSame(2, DB::table('tokens_acceso')->where('proposito', 'CredencialTemporal')->count());
    }

    public static function temporaryCredentialHistory(): array
    {
        return [
            'sin consumir' => [[]],
            'consumida' => [['consumido_en' => '2026-10-19T12:30:00Z']],
            'invalidada' => [['invalidado_en' => '2026-10-19T12:30:00Z']],
        ];
    }

    public function test_audit_events_cannot_be_modified_deleted_or_truncated_even_through_sql(): void
    {
        $id = $this->insertEvent();
        $this->assertRejected(fn () => DB::table('bitacora')->where('id_evento', $id)->update(['resultado' => 'ERROR']), '23514', 'bitacora_inmutable_ck');
        $this->assertRejected(fn () => DB::table('bitacora')->where('id_evento', $id)->delete(), '23514', 'bitacora_inmutable_ck');
        $this->assertRejected(fn () => DB::statement('TRUNCATE TABLE bitacora'), '23514', 'bitacora_inmutable_ck');

        $event = Bitacora::query()->findOrFail($id);
        $event->resultado = 'ERROR';
        $this->assertRejected(fn () => $event->save(), '23514', 'bitacora_inmutable_ck');
        $this->assertSame('EXITO', $event->fresh()->resultado);
    }

    public function test_audit_links_require_a_preexisting_event_and_cannot_form_a_cycle(): void
    {
        $this->assertRejected(fn () => $this->insertEvent(['id_evento' => 9001, 'id_evento_relacionado' => 9001]), '23514', 'bitacora_evento_no_auto_ck');
        $this->assertRejected(fn () => DB::table('bitacora')->insert([
            $this->eventRow(['id_evento' => 9001, 'id_evento_relacionado' => 9002]),
            $this->eventRow(['id_evento' => 9002, 'id_evento_relacionado' => 9001]),
        ]), '23503', 'bitacora_evento_relacionado_fk');

        $first = $this->insertEvent();
        $second = $this->insertEvent(['id_evento_relacionado' => $first]);
        $this->assertRejected(fn () => DB::table('bitacora')->where('id_evento', $first)->update(['id_evento_relacionado' => $second]), '23514', 'bitacora_inmutable_ck');
    }

    public function test_audit_correlation_is_not_unique_and_anonymous_target_is_not_the_actor(): void
    {
        $user = $this->createUser('objetivo-anonimo@example.test');
        $row = $this->eventRow([
            'tipo_actor' => 'ANONIMO',
            'id_usuario_objetivo' => $user->getKey(),
            'rol_objetivo' => 'Recepcionista',
            'resultado' => 'FALLO',
        ]);
        DB::table('bitacora')->insert([$row, $row]);

        $this->assertSame(2, Bitacora::query()->where('correlacion', $row['correlacion'])->count());
        $this->assertNull(Bitacora::query()->firstOrFail()->responsable);
        $this->assertSame($user->getKey(), Bitacora::query()->firstOrFail()->objetivo->getKey());
    }

    public function test_foreign_keys_restrict_deleting_or_rekeying_referenced_users_and_roles(): void
    {
        $user = $this->createUser('referenciado@example.test');
        DB::table('tokens_acceso')->insert($this->tokenRow($user->getKey()));
        $this->assertRejected(fn () => $user->delete(), '23001', 'tokens_acceso_usuario_fk');
        $this->assertRejected(fn () => DB::table('usuarios')->where('id_usuario', $user->getKey())->update(['id_usuario' => 9000]), '23001', 'tokens_acceso_usuario_fk');
        $this->assertRejected(fn () => $user->rol->delete(), '23001', 'usuarios_rol_fk');

        $other = $this->createUser('auditado@example.test');
        $this->insertEvent(['tipo_actor' => 'USUARIO', 'id_usuario_responsable' => $other->getKey(), 'rol_responsable' => 'Recepcionista']);
        $this->assertRejected(fn () => $other->delete(), '23001', 'bitacora_responsable_fk');
    }

    public function test_native_database_sessions_support_anonymous_and_authenticated_encrypted_payloads(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $manager = $this->app->make('session');
        $manager->forgetDrivers();
        $anonymous = $manager->driver();
        $anonymous->start();
        $anonymous->put('prueba', 'contenido ficticio');
        $anonymous->save();

        $row = DB::table('sessions')->where('id', $anonymous->getId())->first();
        $this->assertNull($row->user_id);
        $this->assertStringNotContainsString('contenido ficticio', base64_decode($row->payload));

        $manager->forgetDrivers();
        $restored = $manager->driver();
        $restored->setId($anonymous->getId());
        $restored->start();
        $this->assertSame('contenido ficticio', $restored->get('prueba'));

        $user = $this->createUser('sesion@example.test');
        Auth::guard('web')->setUser($user);
        $restored->save();
        $this->assertSame($user->getKey(), DB::table('sessions')->where('id', $restored->getId())->value('user_id'));

        $this->assertRejected(fn () => $user->delete(), '23001', 'sessions_usuario_fk');
        $restored->getHandler()->destroy($restored->getId());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_session_expiration_and_deletion_by_user_use_the_native_store(): void
    {
        $user = $this->createUser('varias-sesiones@example.test');
        config(['session.driver' => 'database']);
        $manager = $this->app->make('session');
        Auth::guard('web')->setUser($user);
        $ids = [];

        for ($i = 0; $i < 2; $i++) {
            $manager->forgetDrivers();
            $store = $manager->driver();
            $store->start();
            $store->put('prueba', 'sesion ficticia');
            $store->save();
            $ids[] = $store->getId();
        }

        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->getKey())->count());
        DB::table('sessions')->where('id', $ids[0])->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]);
        $this->assertSame('', $store->getHandler()->read($ids[0]));

        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $this->assertSame('', $store->getHandler()->read($ids[1]));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_account_token_session_and_audit_writes(
        string $table,
        array $values,
        string $state,
        ?string $constraint,
    ): void {
        $user = $this->createUser('existente@example.test');
        $defaults = match ($table) {
            'usuarios' => $this->userRow('nuevo@example.test'),
            'tokens_acceso' => $this->tokenRow($user->getKey()),
            'sessions' => ['id' => Str::random(40), 'payload' => '', 'last_activity' => now()->timestamp],
            'bitacora' => $this->eventRow(),
        };

        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($defaults, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'correo duplicado normalizado' => ['usuarios', ['correo_acceso' => ' EXISTENTE@EXAMPLE.TEST '], '23505', 'usuarios_correo_normalizado_uq'],
            'correo vacio' => ['usuarios', ['correo_acceso' => '  '], '23514', 'usuarios_correo_ck'],
            'rol ajeno' => ['usuarios', ['id_rol' => 9000], '23503', 'usuarios_rol_fk'],
            'estado no aprobado' => ['usuarios', ['estado_cuenta' => 'Suspendida'], '23514', 'usuarios_estado_ck'],
            'activa sin verificacion' => ['usuarios', ['correo_verificado_en' => null], '23514', 'usuarios_activa_verificada_ck'],
            'activa sin hash' => ['usuarios', ['password_hash' => null], '23514', null],
            'contador negativo' => ['usuarios', ['intentos_fallidos' => -1], '23514', 'usuarios_intentos_ck'],
            'nombre vacio' => ['usuarios', ['nombres_personal' => '  '], '23514', 'usuarios_nombres_ck'],
            'sesion ajena' => ['sessions', ['user_id' => 9000], '23503', 'sessions_usuario_fk'],
            'sesion sin payload' => ['sessions', ['payload' => null], '23502', null],
            'token ajeno' => ['tokens_acceso', ['id_usuario' => 9000], '23503', 'tokens_acceso_usuario_fk'],
            'proposito de token no aprobado' => ['tokens_acceso', ['proposito' => 'Login'], '23514', 'tokens_acceso_proposito_ck'],
            'token vacio' => ['tokens_acceso', ['token_hash' => '  '], '23514', 'tokens_acceso_hash_ck'],
            'vigencia de token invalida' => ['tokens_acceso', ['expira_en' => '2026-10-19T11:00:00Z'], '23514', 'tokens_acceso_vigencia_ck'],
            'credencial temporal sin vencimiento' => ['tokens_acceso', ['proposito' => 'CredencialTemporal', 'expira_en' => null], '23502', null],
            'credencial temporal con vigencia invalida' => ['tokens_acceso', ['proposito' => 'CredencialTemporal', 'expira_en' => '2026-10-19T12:00:00Z'], '23514', 'tokens_acceso_vigencia_ck'],
            'actor sin identidad' => ['bitacora', ['tipo_actor' => 'USUARIO', 'rol_responsable' => 'Recepcionista'], '23514', 'bitacora_actor_ck'],
            'rol sin objetivo' => ['bitacora', ['rol_objetivo' => 'Paciente'], '23514', 'bitacora_objetivo_ck'],
            'resultado invalido' => ['bitacora', ['resultado' => 'PENDIENTE'], '23514', 'bitacora_resultado_ck'],
            'version invalida' => ['bitacora', ['version_formato' => 0], '23514', 'bitacora_version_ck'],
            'recurso sin entidad' => ['bitacora', ['registro_referido' => '1'], '23514', 'bitacora_recurso_ck'],
            'evento anterior ajeno' => ['bitacora', ['id_evento_relacionado' => 9000], '23503', 'bitacora_evento_relacionado_fk'],
            'json no objeto' => ['bitacora', ['metadatos_seguros' => '[]'], '23514', 'bitacora_metadatos_objeto_ck'],
            'ip invalida' => ['bitacora', ['direccion_ip' => 'ip-invalida'], '22P02', null],
            'uuid invalido' => ['bitacora', ['correlacion' => 'uuid-invalido'], '22P02', null],
        ];
    }

    private function createUser(string $email): Usuario
    {
        $user = new Usuario;
        $user->forceFill($this->userRow($email));
        $user->save();

        return $user->refresh();
    }

    private function userRow(string $email): array
    {
        return [
            'id_rol' => Rol::query()->where('nombre', 'Recepcionista')->value('id_rol'),
            'correo_acceso' => $email,
            'password_hash' => Hash::make('Frase ficticia de prueba 123'),
            'estado_cuenta' => 'Activa',
            'correo_verificado_en' => '2026-10-19T12:00:00Z',
            'nombres_personal' => 'Personal ficticio',
            'apellidos_personal' => 'De prueba',
        ];
    }

    private function tokenRow(int $userId): array
    {
        return [
            'id_usuario' => $userId,
            'proposito' => 'InvitacionPersonal',
            'token_hash' => hash('sha256', Str::random(64)),
            'emitido_en' => '2026-10-19T12:00:00Z',
            'expira_en' => '2026-10-20T12:00:00Z',
        ];
    }

    private function eventRow(array $values = []): array
    {
        return array_replace([
            'tipo_evento' => 'INFRAESTRUCTURA_PRUEBA',
            'tipo_actor' => 'SISTEMA',
            'modulo' => 'Pruebas',
            'operacion' => 'Verificar',
            'resultado' => 'EXITO',
            'ocurrido_en' => '2026-10-19T12:00:00Z',
            'correlacion' => (string) Str::uuid(),
        ], $values);
    }

    private function insertEvent(array $values = []): int
    {
        return DB::table('bitacora')->insertGetId($this->eventRow($values), 'id_evento');
    }

    private function assertRejected(callable $write, string $state, ?string $constraint = null): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL aceptó una escritura que debía rechazar.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);

            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->errorInfo[2]);
            }
        }
    }
}
