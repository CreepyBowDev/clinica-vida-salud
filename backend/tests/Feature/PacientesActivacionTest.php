<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\database\seeders\DatabaseSeeder;
use Clinica\data\models\CodigoActivacionPaciente;
use Clinica\data\models\ContactoEmergencia;
use Clinica\data\models\Paciente;
use Clinica\data\models\PreferenciaPaciente;
use Clinica\data\models\RegistroPacientePendiente;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\models\VerificacionCorreo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PacientesActivacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_preserves_exact_dictionary_columns_and_seeders_do_not_create_patients(): void
    {
        $tables = [
            'pacientes' => ['id_paciente', 'id_usuario', 'codigo_paciente', 'documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion', 'activo', 'creado_en', 'actualizado_en'],
            'contactos_emergencia' => ['id_contacto', 'id_paciente', 'nombre', 'parentesco', 'telefono'],
            'preferencias_paciente' => ['id_preferencia', 'id_paciente', 'recordatorios_habilitados', 'preferencias_privacidad', 'actualizado_en'],
            'codigos_activacion_paciente' => ['id_codigo_activacion', 'id_paciente', 'id_recepcionista', 'codigo_hash', 'emitido_en', 'expira_en', 'intentos_fallidos', 'consumido_en', 'invalidado_en'],
            'registros_paciente_pendientes' => ['id_registro_pendiente', 'id_paciente', 'id_codigo_activacion', 'tipo_registro', 'documento_identidad', 'correo_propuesto', 'password_hash_temporal', 'datos_registro', 'iniciado_en', 'expira_en', 'completado_en', 'invalidado_en'],
            'verificaciones_correo' => ['id_verificacion', 'id_registro_pendiente', 'id_usuario', 'proposito', 'correo_destino', 'codigo_hash', 'enviado_en', 'expira_en', 'intentos_fallidos', 'consumido_en', 'invalidado_en'],
        ];

        foreach ($tables as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame(0, DB::table($table)->count());
            $primary = DB::selectOne('SELECT conname FROM pg_constraint WHERE conrelid = CAST(? AS regclass) AND contype = ?', [$table, 'p']);
            $this->assertSame($table.'_pk', $primary->conname);
        }

        $this->assertSame(56, array_sum(array_map('count', $tables)));
    }

    public function test_patients_allow_multiple_missing_documents_and_accounts_but_reject_duplicates_when_present(): void
    {
        $one = $this->patient();
        $two = $this->patient();
        $this->assertNull($one->documento_identidad);
        $this->assertNull($two->id_usuario);
        $this->assertTrue($one->activo);
        $this->assertNull($one->correo_contacto);
        $this->assertNull($one->sexo);
        $this->assertNull($one->direccion);
        $this->assertNull($one->contactoEmergencia);
        $this->assertNull($one->preferencia);

        $user = $this->user('Paciente');
        $one->id_usuario = $user->getKey();
        $one->documento_identidad = '  00123-A  ';
        $one->save();
        $this->assertSame('00123-A', $one->fresh()->documento_identidad);
        $this->assertRejected(fn () => DB::table('pacientes')->where('id_paciente', $two->getKey())->update(['id_usuario' => $user->getKey()]), '23505', 'pacientes_usuario_uq');
        $this->assertRejected(fn () => DB::table('pacientes')->where('id_paciente', $two->getKey())->update(['documento_identidad' => ' 00123-A ']), '23505', 'pacientes_documento_uq');
        $this->assertRejected(fn () => DB::table('pacientes')->where('id_paciente', $two->getKey())->update(['codigo_paciente' => $one->codigo_paciente]), '23505', 'pacientes_codigo_uq');

        $this->assertSame($user->getKey(), $one->usuario->getKey());
        $this->assertSame($one->getKey(), $user->paciente->getKey());
        $two->documento_identidad = '123-A';
        $two->save();
        $this->assertSame(2, Paciente::query()->count());
    }

    public function test_contact_and_preference_are_optional_singletons_and_preserve_explicit_choices(): void
    {
        $patient = $this->patient();
        $contact = $patient->contactoEmergencia()->create(['nombre' => 'Contacto ficticio', 'parentesco' => 'Persona de confianza', 'telefono' => '70000001']);
        $preference = new PreferenciaPaciente;
        $preference->forceFill(['id_paciente' => $patient->getKey(), 'recordatorios_habilitados' => false, 'preferencias_privacidad' => (object) []]);
        $preference->save();

        $this->assertSame($patient->getKey(), $contact->paciente->getKey());
        $this->assertSame($contact->getKey(), $patient->contactoEmergencia->getKey());
        $this->assertSame($preference->getKey(), $patient->preferencia->getKey());
        $this->assertSame($patient->getKey(), $preference->paciente->getKey());
        $this->assertFalse($preference->fresh()->recordatorios_habilitados);
        $this->assertEquals((object) [], $preference->fresh()->preferencias_privacidad);
        $this->assertInstanceOf(CarbonImmutable::class, $preference->fresh()->actualizado_en);
        $this->assertRejected(fn () => DB::table('contactos_emergencia')->insert(['id_paciente' => $patient->getKey(), 'nombre' => 'Otro contacto', 'parentesco' => 'Familiar', 'telefono' => '70000002']), '23505', 'contactos_emergencia_paciente_uq');
        $this->assertRejected(fn () => DB::table('preferencias_paciente')->insert(['id_paciente' => $patient->getKey(), 'recordatorios_habilitados' => true, 'preferencias_privacidad' => '{}']), '23505', 'preferencias_paciente_paciente_uq');
        $this->assertRejected(fn () => $patient->delete(), '23001');
    }

    public function test_patient_date_and_timestamps_preserve_date_timezone_and_microseconds(): void
    {
        $date = CarbonImmutable::parse('2026-10-19T10:23:45.123456-04:00');
        $patient = $this->patient(['creado_en' => $date, 'actualizado_en' => $date]);
        $this->assertSame('1990-03-25', $patient->fecha_nacimiento->format('Y-m-d'));
        $this->assertSame('1990-03-25', $patient->toArray()['fecha_nacimiento']);
        $this->assertSame($date->format('U.u'), $patient->creado_en->format('U.u'));

        CarbonImmutable::setTestNow($date->addMinute());
        try {
            $patient->telefono = '70000003';
            $patient->save();
            $this->assertSame($date->addMinute()->format('U.u'), $patient->fresh()->actualizado_en->format('U.u'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_reception_code_hash_is_unique_and_fifth_error_remains_representable(): void
    {
        $patient = $this->patient();
        $issuer = $this->user('Recepcionista');
        $row = array_replace($this->codeRow($patient->getKey(), $issuer->getKey()), ['intentos_fallidos' => 5]);
        DB::table('codigos_activacion_paciente')->insert($row);
        $this->assertSame(5, CodigoActivacionPaciente::query()->firstOrFail()->intentos_fallidos);
        $this->assertRejected(fn () => DB::table('codigos_activacion_paciente')->insert($row), '23505', 'codigos_activacion_hash_uq');
    }

    public function test_pending_process_and_codes_keep_secrets_hidden_and_real_relationships(): void
    {
        $patient = $this->patient();
        $issuer = $this->user('Recepcionista');
        $code = new CodigoActivacionPaciente;
        $code->forceFill($this->codeRow($patient->getKey(), $issuer->getKey()));
        $code->save();
        $pending = new RegistroPacientePendiente;
        $pending->forceFill(array_replace($this->pendingRow(), ['tipo_registro' => 'Vinculacion', 'id_paciente' => $patient->getKey(), 'id_codigo_activacion' => $code->getKey(), 'datos_registro' => null, 'documento_identidad' => ' 00123-A ', 'correo_propuesto' => ' TITULAR@EXAMPLE.TEST ']));
        $pending->save();
        $verification = new VerificacionCorreo;
        $verification->forceFill(array_replace($this->verificationRow($pending->getKey()), ['correo_destino' => ' TITULAR@EXAMPLE.TEST ']));
        $verification->save();

        $this->assertSame($patient->getKey(), $code->paciente->getKey());
        $this->assertSame($issuer->getKey(), $code->recepcionista->getKey());
        $this->assertSame($code->getKey(), $issuer->codigosActivacionEmitidos->sole()->getKey());
        $this->assertSame($code->getKey(), $patient->codigosActivacion->sole()->getKey());
        $this->assertSame($patient->getKey(), $pending->paciente->getKey());
        $this->assertSame($code->getKey(), $pending->codigoActivacion->getKey());
        $this->assertSame($pending->getKey(), $code->registrosPendientes->sole()->getKey());
        $this->assertSame($pending->getKey(), $patient->registrosPendientes->sole()->getKey());
        $this->assertSame($pending->getKey(), $verification->registroPendiente->getKey());
        $this->assertSame($verification->getKey(), $pending->verificacionesCorreo->sole()->getKey());
        $this->assertNull($verification->usuario);
        $this->assertSame('titular@example.test', $pending->fresh()->correo_propuesto);
        $this->assertSame('00123-A', $pending->fresh()->documento_identidad);
        $this->assertSame('titular@example.test', $verification->fresh()->correo_destino);
        foreach ([$code, $verification] as $model) {
            $this->assertArrayNotHasKey('codigo_hash', $model->toArray());
            $this->assertArrayNotHasKey('intentos_fallidos', $model->toArray());
            $this->assertSame(0, $model->fresh()->intentos_fallidos);
            $this->assertNull($model->consumido_en);
            $this->assertNull($model->invalidado_en);
            $this->assertInstanceOf(CarbonImmutable::class, $model->fresh()->expira_en);
        }
        $this->assertArrayNotHasKey('password_hash_temporal', $pending->toArray());
        $this->assertArrayNotHasKey('datos_registro', $pending->toArray());
        $this->assertRejected(fn () => $code->delete(), '23001', 'registros_pendientes_codigo_fk');
        $this->assertRejected(fn () => $pending->delete(), '23001', 'verificaciones_correo_registro_fk');
        $this->assertRejected(fn () => $issuer->delete(), '23001', 'codigos_activacion_recepcionista_fk');
    }

    public function test_email_change_targets_only_existing_user_and_does_not_change_credentials(): void
    {
        $user = $this->user('Paciente');
        $row = array_replace($this->verificationRow(null), ['id_usuario' => $user->getKey(), 'proposito' => 'CambioCorreo']);
        DB::table('verificaciones_correo')->insert([$row, $row]);
        $verification = VerificacionCorreo::query()->firstOrFail();
        $this->assertSame($user->getKey(), $verification->usuario->getKey());
        $this->assertNull($verification->registroPendiente);
        $this->assertSame(2, $user->verificacionesCorreo->count());
        $this->assertSame($user->correo_acceso, $user->fresh()->correo_acceso);
        $this->assertRejected(fn () => $user->delete(), '23001', 'verificaciones_correo_usuario_fk');
    }

    public function test_mass_assignment_cannot_change_links_ids_activity_or_security_fields(): void
    {
        $patient = new Paciente(['id_paciente' => 1, 'id_usuario' => 1, 'codigo_paciente' => 'forzado', 'activo' => false, 'nombres' => 'Nombre ficticio']);
        $this->assertNull($patient->getKey());
        $this->assertNull($patient->id_usuario);
        $this->assertNull($patient->codigo_paciente);
        $this->assertTrue($patient->activo);
        $this->assertSame('Nombre ficticio', $patient->nombres);
        $contact = new ContactoEmergencia(['id_paciente' => 1, 'nombre' => 'Contacto ficticio']);
        $this->assertNull($contact->id_paciente);
        foreach ([new PreferenciaPaciente, new CodigoActivacionPaciente, new RegistroPacientePendiente, new VerificacionCorreo] as $model) {
            $this->assertTrue($model->isGuarded('id_paciente'));
            $this->assertSame([], $model->getFillable());
        }
    }

    public function test_open_process_exclusivity_is_normalized_and_expiration_requires_explicit_closure(): void
    {
        $row = $this->pendingRow(['correo_propuesto' => ' RESERVA@EXAMPLE.TEST ', 'documento_identidad' => ' 00123-A ']);
        $id = DB::table('registros_paciente_pendientes')->insertGetId($row, 'id_registro_pendiente');
        $this->assertRejected(fn () => DB::table('registros_paciente_pendientes')->insert($this->pendingRow(['correo_propuesto' => 'reserva@example.test'])), '23505', 'registros_pendientes_correo_abierto_uq');
        $this->assertRejected(fn () => DB::table('registros_paciente_pendientes')->insert($this->pendingRow(['documento_identidad' => '00123-A'])), '23505', 'registros_pendientes_documento_abierto_uq');

        DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['iniciado_en' => '2000-01-01T12:00:00Z', 'expira_en' => '2000-01-02T12:00:00Z']);
        $this->assertRejected(fn () => DB::table('registros_paciente_pendientes')->insert($this->pendingRow(['correo_propuesto' => 'reserva@example.test'])), '23505', 'registros_pendientes_correo_abierto_uq');

        DB::transaction(function () use ($id): void {
            DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->lockForUpdate()->first();
            DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['invalidado_en' => now(), 'password_hash_temporal' => null, 'datos_registro' => null]);
            DB::table('registros_paciente_pendientes')->insert($this->pendingRow(['correo_propuesto' => 'reserva@example.test', 'documento_identidad' => '00123-A']));
        });
        $this->assertSame(2, RegistroPacientePendiente::query()->count());
        $this->assertNull(RegistroPacientePendiente::query()->findOrFail($id)->password_hash_temporal);
    }

    public function test_patient_and_reception_code_are_exclusive_for_open_linking_processes(): void
    {
        $patient = $this->patient();
        $issuer = $this->user('Recepcionista');
        $code = DB::table('codigos_activacion_paciente')->insertGetId($this->codeRow($patient->getKey(), $issuer->getKey()), 'id_codigo_activacion');
        $otherCode = DB::table('codigos_activacion_paciente')->insertGetId($this->codeRow($patient->getKey(), $issuer->getKey()), 'id_codigo_activacion');
        $row = $this->pendingRow(['tipo_registro' => 'Vinculacion', 'id_paciente' => $patient->getKey(), 'id_codigo_activacion' => $code]);
        $id = DB::table('registros_paciente_pendientes')->insertGetId($row, 'id_registro_pendiente');
        $this->assertRejected(fn () => DB::table('registros_paciente_pendientes')->insert(array_replace($row, ['correo_propuesto' => 'otro@example.test', 'id_codigo_activacion' => $otherCode])), '23505', 'registros_pendientes_paciente_abierto_uq');

        // Aísla la barrera del código; la correspondencia código/Paciente se valida además en el Service.
        $other = $this->patient();
        $this->assertRejected(fn () => DB::table('registros_paciente_pendientes')->insert(array_replace($row, ['correo_propuesto' => 'otro@example.test', 'id_paciente' => $other->getKey()])), '23505', 'registros_pendientes_codigo_abierto_uq');
        DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['completado_en' => now(), 'password_hash_temporal' => null]);
        DB::table('registros_paciente_pendientes')->insert($row);
        $this->assertSame(2, RegistroPacientePendiente::query()->count());
    }

    public function test_failed_multirow_operation_rolls_back_patient_link_and_process_closure(): void
    {
        $patient = $this->patient();
        $user = $this->user('Paciente');
        $id = DB::table('registros_paciente_pendientes')->insertGetId($this->pendingRow(), 'id_registro_pendiente');
        try {
            DB::transaction(function () use ($patient, $user, $id): void {
                DB::table('pacientes')->where('id_paciente', $patient->getKey())->update(['id_usuario' => $user->getKey()]);
                DB::table('registros_paciente_pendientes')->where('id_registro_pendiente', $id)->update(['completado_en' => now(), 'password_hash_temporal' => null]);
                throw new RuntimeException('Fallo simulado de persistencia.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado de persistencia.', $exception->getMessage());
        }
        $this->assertNull($patient->fresh()->id_usuario);
        $this->assertNull(RegistroPacientePendiente::query()->findOrFail($id)->completado_en);
        $this->assertNotNull(RegistroPacientePendiente::query()->findOrFail($id)->password_hash_temporal);
    }

    #[DataProvider('invalidWrites')]
    public function test_postgresql_rejects_invalid_writes(string $table, array $values, string $state, ?string $constraint): void
    {
        $patient = $this->patient();
        $issuer = $this->user('Recepcionista');
        $pending = DB::table('registros_paciente_pendientes')->insertGetId($this->pendingRow(), 'id_registro_pendiente');
        $row = match ($table) {
            'pacientes' => $this->patientRow(),
            'contactos_emergencia' => ['id_paciente' => $patient->getKey(), 'nombre' => 'Contacto ficticio', 'parentesco' => 'Familiar', 'telefono' => '70000000'],
            'preferencias_paciente' => ['id_paciente' => $patient->getKey(), 'recordatorios_habilitados' => true, 'preferencias_privacidad' => '{}'],
            'codigos_activacion_paciente' => $this->codeRow($patient->getKey(), $issuer->getKey()),
            'registros_paciente_pendientes' => $this->pendingRow(),
            'verificaciones_correo' => $this->verificationRow($pending),
        };
        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($row, $values)), $state, $constraint);
    }

    public static function invalidWrites(): array
    {
        return [
            'paciente CI vacio' => ['pacientes', ['documento_identidad' => '  '], '23514', 'pacientes_documento_ck'],
            'paciente nombre vacio' => ['pacientes', ['nombres' => ' '], '23514', 'pacientes_nombres_ck'],
            'paciente telefono vacio' => ['pacientes', ['telefono' => ' '], '23514', 'pacientes_telefono_ck'],
            'paciente sin nacimiento' => ['pacientes', ['fecha_nacimiento' => null], '23502', null],
            'paciente cuenta ajena' => ['pacientes', ['id_usuario' => 9000], '23503', 'pacientes_usuario_fk'],
            'contacto paciente ajeno' => ['contactos_emergencia', ['id_paciente' => 9000], '23503', 'contactos_emergencia_paciente_fk'],
            'contacto nombre vacio' => ['contactos_emergencia', ['nombre' => ' '], '23514', 'contactos_emergencia_nombre_ck'],
            'preferencia paciente ajeno' => ['preferencias_paciente', ['id_paciente' => 9000], '23503', 'preferencias_paciente_paciente_fk'],
            'preferencia array' => ['preferencias_paciente', ['preferencias_privacidad' => '[]'], '23514', 'preferencias_paciente_objeto_ck'],
            'preferencia sin eleccion' => ['preferencias_paciente', ['recordatorios_habilitados' => null], '23502', null],
            'codigo paciente ajeno' => ['codigos_activacion_paciente', ['id_paciente' => 9000], '23503', 'codigos_activacion_paciente_fk'],
            'codigo emisor ajeno' => ['codigos_activacion_paciente', ['id_recepcionista' => 9000], '23503', 'codigos_activacion_recepcionista_fk'],
            'codigo intentos negativos' => ['codigos_activacion_paciente', ['intentos_fallidos' => -1], '23514', 'codigos_activacion_intentos_ck'],
            'codigo sexto error' => ['codigos_activacion_paciente', ['intentos_fallidos' => 6], '23514', 'codigos_activacion_intentos_ck'],
            'codigo hash vacio' => ['codigos_activacion_paciente', ['codigo_hash' => ' '], '23514', 'codigos_activacion_hash_ck'],
            'codigo intervalo vacio' => ['codigos_activacion_paciente', ['expira_en' => '2026-10-19T12:00:00Z'], '23514', 'codigos_activacion_vigencia_ck'],
            'registro tipo ajeno' => ['registros_paciente_pendientes', ['tipo_registro' => 'Otro'], '23514', 'registros_pendientes_tipo_ck'],
            'registro nuevo con paciente' => ['registros_paciente_pendientes', ['id_paciente' => 9000], '23514', 'registros_pendientes_tipo_ck'],
            'vinculacion sin relaciones' => ['registros_paciente_pendientes', ['tipo_registro' => 'Vinculacion'], '23514', 'registros_pendientes_tipo_ck'],
            'registro sin hash' => ['registros_paciente_pendientes', ['password_hash_temporal' => null], '23514', 'registros_pendientes_hash_abierto_ck'],
            'nuevo sin datos' => ['registros_paciente_pendientes', ['datos_registro' => null], '23514', 'registros_pendientes_datos_nuevo_ck'],
            'registro datos array' => ['registros_paciente_pendientes', ['datos_registro' => '[]'], '23514', 'registros_pendientes_datos_ck'],
            'registro correo vacio' => ['registros_paciente_pendientes', ['correo_propuesto' => ' '], '23514', 'registros_pendientes_correo_ck'],
            'registro intervalo vacio' => ['registros_paciente_pendientes', ['expira_en' => '2026-10-19T12:00:00Z'], '23514', 'registros_pendientes_vigencia_ck'],
            'verificacion ambas FK' => ['verificaciones_correo', ['id_usuario' => 9000], '23514', 'verificaciones_correo_proposito_xor_ck'],
            'verificacion ninguna FK' => ['verificaciones_correo', ['id_registro_pendiente' => null], '23514', 'verificaciones_correo_proposito_xor_ck'],
            'verificacion proposito incompatible' => ['verificaciones_correo', ['proposito' => 'CambioCorreo'], '23514', 'verificaciones_correo_proposito_xor_ck'],
            'verificacion proposito ajeno' => ['verificaciones_correo', ['proposito' => 'InvitacionPersonal'], '23514', 'verificaciones_correo_proposito_xor_ck'],
            'verificacion registro ajeno' => ['verificaciones_correo', ['id_registro_pendiente' => 9000], '23503', 'verificaciones_correo_registro_fk'],
            'verificacion sexto error' => ['verificaciones_correo', ['intentos_fallidos' => 6], '23514', 'verificaciones_correo_intentos_ck'],
            'verificacion intervalo vacio' => ['verificaciones_correo', ['expira_en' => '2026-10-19T12:00:00Z'], '23514', 'verificaciones_correo_vigencia_ck'],
        ];
    }

    private function patientRow(array $values = []): array
    {
        return array_replace(['codigo_paciente' => 'PRUEBA-'.Str::random(16), 'nombres' => 'Paciente ficticio', 'apellidos' => 'De prueba', 'fecha_nacimiento' => '1990-03-25', 'telefono' => '70000000'], $values);
    }

    private function patient(array $values = []): Paciente
    {
        $patient = new Paciente;
        $patient->forceFill($this->patientRow($values));
        $patient->save();

        return $patient->refresh();
    }

    private function user(string $role): Usuario
    {
        $user = new Usuario;
        $user->forceFill(['id_rol' => Rol::query()->where('nombre', $role)->value('id_rol'), 'correo_acceso' => Str::random(16).'@example.test']);
        $user->save();

        return $user;
    }

    private function pendingRow(array $values = []): array
    {
        return array_replace(['tipo_registro' => 'Nuevo', 'correo_propuesto' => Str::random(16).'@example.test', 'password_hash_temporal' => '$resumen-ficticio-no-utilizable', 'datos_registro' => '{"nombres":"Paciente ficticio"}', 'iniciado_en' => '2026-10-19T12:00:00Z', 'expira_en' => '2026-10-20T12:00:00Z'], $values);
    }

    private function codeRow(int $patient, int $issuer): array
    {
        return ['id_paciente' => $patient, 'id_recepcionista' => $issuer, 'codigo_hash' => hash('sha256', Str::random(64)), 'emitido_en' => '2026-10-19T12:00:00Z', 'expira_en' => '2026-10-20T12:00:00Z'];
    }

    private function verificationRow(?int $pending): array
    {
        return ['id_registro_pendiente' => $pending, 'proposito' => 'ActivacionPaciente', 'correo_destino' => 'titular@example.test', 'codigo_hash' => hash('sha256', 'codigo ficticio'), 'enviado_en' => '2026-10-19T12:00:00Z', 'expira_en' => '2026-10-19T12:10:00Z'];
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
