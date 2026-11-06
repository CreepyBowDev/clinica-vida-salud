<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\CobroPago;
use Clinica\data\models\DevolucionPago;
use Clinica\data\models\EventoPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class PagosPersistenciaTest extends TestCase
{
    use AutenticacionFixtures;

    private int $actor;

    private array $baseCita;

    private int $citaLegada;

    private string $ahora = '2026-11-05T10:00:00.123456Z';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        // Preparamos una cita realmente anterior a la migración; todo se revierte al terminar.
        $previas = array_values(array_filter(glob(base_path('src/data/database/migrations/*.php')),
            fn ($path) => ! str_contains($path, '000040_') && ! str_contains($path, '000041_')));
        $this->artisan('migrate', ['--force' => true, '--path' => $previas, '--realpath' => true])->assertSuccessful();
        $this->actor = (int) $this->usuarioAuth()->getKey();
        $paciente = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'P-FICTICIO', 'nombres' => 'Paciente',
            'apellidos' => 'Ficticio', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $consultorio = DB::table('consultorios')->insertGetId(['codigo_consultorio' => 'FICTICIO', 'nombre' => 'Sala ficticia'], 'id_consultorio');
        $medico = DB::table('medicos')->insertGetId(['codigo_medico' => 'M-FICTICIO', 'registro_profesional' => 'REG-FICTICIO',
            'nombres' => 'Medico', 'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'medico@example.test'], 'id_medico');
        $especialidad = DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad ficticia'], 'id_especialidad');
        DB::table('medico_especialidad')->insert(['id_medico' => $medico, 'id_especialidad' => $especialidad]);
        $this->baseCita = ['id_paciente' => $paciente, 'id_medico' => $medico, 'id_especialidad' => $especialidad,
            'id_consultorio' => $consultorio, 'creada_por' => $this->actor, 'duracion_minutos' => 15,
            'sede_atencion' => 'Sede ficticia', 'consultorio_atencion' => 'Sala ficticia'];
        $this->citaLegada = $this->cita(null);
        $this->artisan('migrate', ['--force' => true,
            '--path' => 'src/data/database/migrations/2026_11_05_000040_create_pagos_persistence.php'])->assertSuccessful();
        $this->artisan('migrate', ['--force' => true,
            '--path' => 'src/data/database/migrations/2026_11_06_000041_add_simulacion_academica_to_pagos.php'])->assertSuccessful();
    }

    private function cita(?bool $incorporada = true): int
    {
        $hora = CarbonImmutable::parse($this->ahora)->addDays(DB::table('citas')->count() + 1);
        $datos = [...$this->baseCita, 'codigo_cita' => (string) Str::uuid(), 'inicio_at' => $hora, 'fin_at' => $hora->addMinutes(15)];
        if ($incorporada !== null) {
            $datos['incorporada_finanzas'] = $incorporada;
        }

        return DB::table('citas')->insertGetId($datos, 'id_cita');
    }

    private function obligacion(?int $cita = null, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        DB::table('obligaciones_pago')->insert(array_replace(['id_obligacion' => $id, 'id_cita' => $cita ?? $this->cita(),
            'centavos_bob' => 704, 'centavos_usd' => 101, 'emitida_por' => $this->actor,
            'emitida_en' => $this->ahora, 'vencimiento_at' => $this->ahora], $cambios));

        return $id;
    }

    private function intento(string $obligacion, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        DB::table('intentos_pago')->insert(array_replace(['id_intento' => $id, 'id_obligacion' => $obligacion,
            'iniciado_por' => $this->actor, 'proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => 'cuenta_ficticia',
            'creado_en' => $this->ahora, 'actualizado_en' => $this->ahora], $cambios));

        return $id;
    }

    private function cobro(string $intento, bool $aplicado = true, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        $origen = DB::table('intentos_pago')->where('id_intento', $intento)->first();
        DB::table('cobros_pago')->insert(array_replace(['id_cobro' => $id, 'id_intento' => $intento,
            'id_obligacion' => $origen->id_obligacion, 'proveedor' => $origen->proveedor, 'entorno' => $origen->entorno,
            'cuenta_contexto' => $origen->cuenta_contexto, 'referencia_cobro' => (string) Str::uuid(), 'centavos_usd' => 101,
            'aplicado' => $aplicado, 'incidencia_codigo' => $aplicado ? null : 'COBRO_DUPLICADO_NO_APLICADO',
            'confirmado_en' => $this->ahora, 'registrado_en' => $this->ahora], $cambios));

        return $id;
    }

    private function cancelacion(int $cita): int
    {
        return DB::table('cambios_cita')->insertGetId(['id_cita' => $cita, 'id_actor' => $this->actor,
            'operacion' => 'Cancelacion', 'realizado_en' => $this->ahora, 'estado_anterior' => 'Programada',
            'estado_nuevo' => 'Cancelada', 'datos_nuevos' => '{}'], 'id_cambio_cita');
    }

    private function devolucion(string $cobro, int $cancelacion, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        DB::table('devoluciones_pago')->insert(array_replace(['id_devolucion' => $id, 'id_cobro' => $cobro,
            'centavos_usd' => 101, 'id_cambio_cancelacion' => $cancelacion,
            'creada_en' => $this->ahora, 'actualizada_en' => $this->ahora], $cambios));

        return $id;
    }

    private function operacion(string $intento, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        $origen = DB::table('intentos_pago')->where('id_intento', $intento)->first();
        DB::table('operaciones_pago')->insert(array_replace(['id_operacion' => $id, 'id_intento' => $intento,
            'id_obligacion' => $origen->id_obligacion, 'proveedor' => $origen->proveedor, 'entorno' => $origen->entorno,
            'cuenta_contexto' => $origen->cuenta_contexto, 'tipo' => 'Crear', 'solicitada_por' => $this->actor,
            'clave_solicitud' => (string) Str::uuid(), 'huella_solicitud' => hash('sha256', 'solicitud-ficticia'),
            'clave_externa' => (string) Str::uuid(), 'proxima_ejecucion_en' => $this->ahora,
            'creada_en' => $this->ahora, 'actualizada_en' => $this->ahora], $cambios));

        return $id;
    }

    private function evento(array $cambios = []): string
    {
        $id = (string) Str::uuid();
        DB::table('eventos_pago')->insert(array_replace(['id_evento' => $id, 'proveedor' => 'stripe', 'entorno' => 'test',
            'cuenta_contexto' => 'cuenta_ficticia', 'id_externo' => 'evt_ficticio', 'tipo' => 'payment_intent.succeeded',
            'huella_contenido' => hash('sha256', 'evento-ficticio'), 'referencias' => '{}',
            'recepcion_en' => $this->ahora, 'proxima_ejecucion_en' => $this->ahora], $cambios));

        return $id;
    }

    private function rechaza(callable $accion, string $sqlstate): void
    {
        DB::beginTransaction();
        try {
            $accion();
        } catch (QueryException $e) {
            DB::rollBack();
            $this->assertSame($sqlstate, $e->errorInfo[0]);

            return;
        }
        DB::rollBack();
        $this->fail('PostgreSQL no rechazó la operación inválida.');
    }

    public function test_legacy_and_current_flows_stay_excluded_without_backfill(): void
    {
        $vieja = $this->citaLegada;
        $vigente = $this->cita(null);
        $this->assertFalse(Cita::findOrFail($vieja)->incorporada_finanzas);
        $this->assertFalse(Cita::findOrFail($vigente)->incorporada_finanzas);
        $this->assertSame(0, ObligacionPago::count());
        $this->rechaza(fn () => $this->obligacion($vieja), '23503');
        $this->rechaza(fn () => DB::table('citas')->where('id_cita', $vieja)->update(['incorporada_finanzas' => true]), '23514');
        $incorporada = $this->cita();
        $this->assertNull(Cita::findOrFail($incorporada)->obligacionPago);
        $this->assertArrayNotHasKey('incorporada_finanzas', Cita::findOrFail($incorporada)->toArray());
    }

    public function test_one_obligation_and_immutable_historical_price_with_real_foreign_keys(): void
    {
        $cita = $this->cita();
        $id = $this->obligacion($cita);
        $this->rechaza(fn () => $this->obligacion($cita), '23505');
        $this->rechaza(fn () => $this->obligacion(999999999), '23503');
        $this->rechaza(fn () => DB::table('obligaciones_pago')->where('id_obligacion', $id)->update(['centavos_bob' => 700, 'centavos_usd' => 100]), '23514');
        DB::table('obligaciones_pago')->where('id_obligacion', $id)->update(['vencimiento_at' => '2026-11-06T10:00:00Z']);
        $this->assertSame(101, ObligacionPago::findOrFail($id)->centavos_usd);
    }

    public static function importesInvalidos(): array
    {
        return [[['centavos_bob' => 699, 'centavos_usd' => 100]], [['centavos_bob' => 700001, 'centavos_usd' => 100000]],
            [['centavos_usd' => 100]], [['tasa_numerador' => 8]], [['tasa_denominador' => 2]],
            [['moneda_original' => 'USD']], [['moneda_cobro' => 'BOB']], [['incorporada_finanzas' => false]],
            [['exigible' => false]], [['anulada_en' => '2026-11-05T10:00:00Z']]];
    }

    #[DataProvider('importesInvalidos')]
    public function test_sql_rejects_incoherent_price_or_annulment(array $cambios): void
    {
        $cita = $this->cita();
        $this->rechaza(fn () => $this->obligacion($cita, $cambios), '23514');
    }

    public static function activos(): array
    {
        return [['Preparando'], ['RequiereAccion'], ['Procesando'], ['Incierto']];
    }

    #[DataProvider('activos')]
    public function test_only_one_chargeable_attempt_even_across_providers(string $estado): void
    {
        $obligacion = $this->obligacion();
        $id = $this->intento($obligacion, ['estado' => $estado]);
        $this->rechaza(fn () => $this->intento($obligacion, ['proveedor' => 'paypal', 'entorno' => 'sandbox']), '23505');
        DB::table('intentos_pago')->where('id_intento', $id)->update(['estado' => 'Cerrado']);
        $otro = $this->intento($obligacion, ['proveedor' => 'paypal', 'entorno' => 'sandbox']);
        $this->assertNotSame($id, $otro);
    }

    public function test_references_context_and_one_applied_payment_preserve_both_exceptional_charges(): void
    {
        $obligacion = $this->obligacion();
        $uno = $this->intento($obligacion, ['estado' => 'Exitoso', 'referencia_inicio' => 'cs_ficticia', 'referencia_pago' => 'pi_ficticio']);
        $dos = $this->intento($obligacion, ['estado' => 'Exitoso', 'proveedor' => 'paypal', 'entorno' => 'sandbox']);
        $this->rechaza(fn () => $this->intento($obligacion, ['estado' => 'Cerrado', 'referencia_inicio' => 'cs_ficticia']), '23505');
        $this->rechaza(fn () => $this->intento($obligacion, ['estado' => 'Cerrado', 'referencia_pago' => 'pi_ficticio']), '23505');
        $this->rechaza(fn () => $this->intento($obligacion, ['entorno' => 'live']), '23514');
        $cobro = $this->cobro($uno, true, ['referencia_cobro' => 'ch_ficticio']);
        $this->rechaza(fn () => $this->cobro($dos), '23505');
        $excepcional = $this->cobro($dos, false);
        $this->rechaza(fn () => $this->cobro($uno, false, ['referencia_cobro' => 'ch_ficticio']), '23505');
        $this->rechaza(fn () => $this->cobro($dos, false, ['centavos_usd' => 102]), '23503');
        $this->rechaza(fn () => $this->cobro($dos, false, ['entorno' => 'test']), '23503');
        $this->rechaza(fn () => $this->cobro($dos, false, ['incidencia_codigo' => null]), '23514');
        $this->assertSame(2, CobroPago::count());
        $this->assertSame($cobro, ObligacionPago::findOrFail($obligacion)->liquidacion->getKey());
        $this->assertFalse(CobroPago::findOrFail($excepcional)->aplicado);
        $this->rechaza(fn () => DB::table('cobros_pago')->where('id_cobro', $excepcional)->update(['aplicado' => true]), '23514');
    }

    public function test_obligation_attempt_and_context_cannot_be_cross_wired(): void
    {
        $uno = $this->obligacion();
        $dos = $this->obligacion();
        $intento = $this->intento($uno);
        $this->rechaza(fn () => $this->cobro($intento, true, ['id_obligacion' => $dos]), '23503');
        $this->rechaza(fn () => $this->operacion($intento, ['id_obligacion' => $dos]), '23503');
        $this->rechaza(fn () => $this->evento(['id_intento' => $intento, 'cuenta_contexto' => 'otra_cuenta']), '23503');
    }

    public function test_durable_events_deduplicate_by_account_and_preserve_original_evidence(): void
    {
        $id = $this->evento(['estado' => 'PendienteVinculo']);
        $this->rechaza(fn () => $this->evento(), '23505');
        $otro = $this->evento(['cuenta_contexto' => 'otra_cuenta']);
        $this->assertNotSame($id, $otro);
        $this->rechaza(fn () => $this->evento(['id_externo' => 'evt_otro', 'referencias' => '[]']), '23514');
        $this->rechaza(fn () => DB::table('eventos_pago')->where('id_evento', $id)->update(['huella_contenido' => hash('sha256', 'otro')]), '23514');
        $this->rechaza(fn () => DB::table('eventos_pago')->where('id_evento', $id)->update(['estado' => 'Procesando']), '23514');
        DB::table('eventos_pago')->where('id_evento', $id)->update(['estado' => 'Procesando', 'ejecuciones' => 1,
            'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => $this->ahora]);
        $this->assertSame(1, EventoPago::findOrFail($id)->ejecuciones);
    }

    public function test_total_refund_only_for_applied_charge_with_recoverable_operations(): void
    {
        $cita = $this->cita();
        $obligacion = $this->obligacion($cita);
        $intento = $this->intento($obligacion, ['estado' => 'Exitoso']);
        $aplicado = $this->cobro($intento);
        $extra = $this->cobro($intento, false);
        $cancelacion = $this->cancelacion($cita);
        $this->rechaza(fn () => $this->devolucion($aplicado, $cancelacion, ['centavos_usd' => 100]), '23503');
        $id = $this->devolucion($aplicado, $cancelacion);
        $this->rechaza(fn () => $this->devolucion($aplicado, $cancelacion), '23505');
        $this->rechaza(fn () => $this->devolucion($extra, $cancelacion), '23503');
        $this->rechaza(fn () => $this->devolucion($extra, $cancelacion, ['cobro_aplicado' => false]), '23514');
        $this->rechaza(fn () => $this->devolucion($extra, $cancelacion, ['centavos_usd' => 100]), '23503');
        $datos = ['tipo' => 'Devolver', 'id_cobro' => $aplicado, 'id_devolucion' => $id, 'solicitada_por' => null, 'clave_solicitud' => null];
        $operacion = $this->operacion($intento, [...$datos, 'estado' => 'Incierta']);
        $this->rechaza(fn () => $this->operacion($intento, $datos), '23505');
        DB::table('operaciones_pago')->where('id_operacion', $operacion)->update(['estado' => 'Fallida']);
        $this->operacion($intento, [...$datos, 'estado' => 'Exitosa', 'referencia_externa' => 're_ficticio']);
        $this->rechaza(fn () => $this->operacion($intento, [...$datos, 'estado' => 'Exitosa']), '23505');
        $this->assertSame(2, DevolucionPago::findOrFail($id)->operaciones()->count());
        $this->assertSame($aplicado, DevolucionPago::findOrFail($id)->cobro->getKey());
    }

    public function test_operation_keys_recovery_and_leases(): void
    {
        $obligacion = $this->obligacion();
        $intento = $this->intento($obligacion, ['estado' => 'Cerrado']);
        $solicitud = (string) Str::uuid();
        $externa = (string) Str::uuid();
        $operacion = $this->operacion($intento, ['clave_solicitud' => $solicitud, 'clave_externa' => $externa, 'estado' => 'Incierta']);
        $historico = $this->intento($obligacion, ['estado' => 'Cerrado', 'proveedor' => 'paypal', 'entorno' => 'sandbox']);
        $this->rechaza(fn () => $this->operacion($historico), '23505');
        $this->rechaza(fn () => $this->operacion($intento, ['tipo' => 'Capturar']), '23505');
        $this->rechaza(fn () => $this->operacion($intento, ['tipo' => 'Consultar', 'clave_externa' => $externa]), '23505');
        $this->rechaza(fn () => DB::table('operaciones_pago')->where('id_operacion', $operacion)->update(['estado' => 'Procesando']), '23514');
        DB::table('operaciones_pago')->where('id_operacion', $operacion)->update(['estado' => 'Fallida']);
        $otro = $this->intento($obligacion);
        $this->rechaza(fn () => $this->operacion($otro, ['clave_solicitud' => $solicitud]), '23505');
        $nueva = $this->operacion($otro, ['estado' => 'Procesando', 'ejecuciones' => 1,
            'codigo_reclamo' => (string) Str::uuid(), 'reclamado_hasta' => $this->ahora]);
        $this->assertSame('Procesando', OperacionPago::findOrFail($nueva)->estado);
        $this->assertSame('Fallida', OperacionPago::findOrFail($operacion)->estado);
    }

    public function test_models_relations_serialization_encryption_and_evidence_preservation(): void
    {
        $cita = $this->cita();
        $obligacion = $this->obligacion($cita);
        $intento = $this->intento($obligacion);
        $operacion = $this->operacion($intento);
        $evento = $this->evento(['id_intento' => $intento]);
        $op = OperacionPago::findOrFail($operacion);
        $op->datos_solicitud_cifrados = ['referencia_opaca' => 'ficticia'];
        $op->save();
        $ev = EventoPago::findOrFail($evento);
        $this->assertSame(['referencia_opaca' => 'ficticia'], $op->fresh()->datos_solicitud_cifrados);
        $this->assertStringNotContainsString('ficticia', DB::table('operaciones_pago')->where('id_operacion', $operacion)->value('datos_solicitud_cifrados'));
        foreach ([$op, $ev, IntentoPago::findOrFail($intento), ObligacionPago::findOrFail($obligacion)] as $model) {
            $this->assertSame([$model->getKeyName() => $model->getKey()], $model->toArray());
            $this->assertTrue($model->isGuarded('estado'));
            $this->rechaza(fn () => DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->delete(), '23514');
        }
        $this->assertSame($obligacion, Cita::findOrFail($cita)->obligacionPago->getKey());
        $this->assertSame($cita, (int) $op->intento->obligacion->cita->getKey());
        $this->assertSame($this->actor, (int) $op->solicitante->getKey());
        $this->assertSame($intento, $ev->intento->getKey());
        $this->assertInstanceOf(CarbonImmutable::class, $op->creada_en);
        $this->assertSame('Programada', Cita::findOrFail($cita)->estado);
    }
}
