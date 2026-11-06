<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\ObligacionPagoRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\TestCase;

class GestionCitaIntegracionTest extends TestCase
{
    use AutenticacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function preparar(string $rol): array
    {
        // Solo las identidades previamente verificadas son fixtures; la preparación funcional usa APIs reales.
        $admin = $this->usuarioAuth();
        $administracion = $this->csrfAuth();
        $this->loginAuth($administracion, $admin)->assertOk();
        $especialidad = $this->navegador($administracion, 'POST', '/api/especialidades', ['nombre' => 'Gestión integración ficticia'])
            ->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($administracion, 'POST', '/api/medicos', ['registro_profesional' => 'GESTION-FICTICIA',
            'nombres' => 'Profesional', 'apellidos' => 'Ficticio', 'telefono' => '70000000', 'correo_contacto' => 'gestion@example.test',
            'especialidades' => [$especialidad]])->assertCreated()->json('data.id_medico');
        $sala = $this->navegador($administracion, 'POST', '/api/consultorios', ['codigo_consultorio' => 'GES-INT', 'nombre' => 'Sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($administracion, 'GET', '/api/medicos/'.$medico.'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $huella = $this->navegador($administracion, 'POST', '/api/medicos/'.$medico.'/consultorio/impacto', $seleccion)
            ->assertOk()->json('data.huella_impacto');
        $asignacion = $this->navegador($administracion, 'PUT', '/api/medicos/'.$medico.'/consultorio',
            [...$seleccion, 'huella_impacto' => $huella, 'confirmar_traslados' => false])->assertOk()->json('data');
        $this->navegador($administracion, 'POST', '/api/medicos/'.$medico.'/horarios', ['dia_semana' => 7,
            'hora_inicio' => '08:00:01', 'hora_fin' => '10:00:01', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $paciente = $this->navegador($cookies, 'POST', '/api/pacientes', ['nombres' => 'Paciente', 'apellidos' => 'Gestión sin CI',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'posibles_duplicados_revisados' => true])
            ->assertCreated()->json('data.id_paciente');
        $criterios = ['id_especialidad' => $especialidad, 'id_medico' => $medico, 'id_paciente' => $paciente, 'fecha' => '2026-11-01'];
        [$cita, $payload] = $this->reservar($cookies, $criterios);

        return compact('administracion', 'cookies', 'actor', 'paciente', 'medico', 'especialidad', 'sala', 'criterios', 'cita', 'payload');
    }

    private function reservar(array &$cookies, array $criterios): array
    {
        $turno = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($criterios))
            ->assertOk()->json('data.turnos.0');
        $motivo = 'MOTIVO-RESERVA-FICTICIO-PRIVADO';
        $propuesta = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$criterios,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => $motivo])
            ->assertOk()->json('data.propuesta');
        $payload = ['propuesta' => $propuesta, 'motivo_breve' => $motivo];

        return [$this->navegador($cookies, 'POST', '/api/citas', $payload)->assertCreated()->json('data'), $payload];
    }

    private function originales(array $vista): array
    {
        return ['version_programacion_original' => $vista['version_programacion'], 'actualizada_en_original' => $vista['actualizada_en']];
    }

    private function alternativas(array &$cookies, string $id): array
    {
        return $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/disponibilidad-reprogramacion?fecha=2026-11-01')
            ->assertOk()->json('data');
    }

    private function reprogramar(array &$cookies, string $id, array $vista): array
    {
        $datos = [...$this->originales($vista['cita_original']), 'fecha' => '2026-11-01',
            'hora_inicio' => $vista['turnos'][0]['hora_inicio'], 'revision_turno' => $vista['turnos'][0]['revision_turno']];
        $resultado = $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos)->assertOk()->json('data');

        return [$resultado, $datos];
    }

    /** Fixture insertada como incorporada desde su nacimiento; nunca reclasifica la reserva legada. */
    private function citaFinanciera(array $r, bool $conObligacion = true, bool $liquidada = false): array
    {
        $datos = (array) DB::table('citas')->where('id_cita', $r['cita']['id_cita'])->first();
        unset($datos['id_cita'], $datos['id_propuesta_reserva']);
        $datos = array_replace($datos, ['codigo_cita' => (string) Str::uuid(), 'incorporada_finanzas' => true,
            'inicio_at' => '2026-11-01T13:00:01Z', 'fin_at' => '2026-11-01T13:15:01Z']);
        $id = (string) DB::table('citas')->insertGetId($datos, 'id_cita');
        $obligacion = null;
        if ($conObligacion) {
            $obligacion = (string) Str::uuid();
            DB::table('obligaciones_pago')->insert(['id_obligacion' => $obligacion, 'id_cita' => $id,
                'centavos_bob' => 704, 'centavos_usd' => 101, 'emitida_por' => $r['actor']->getKey(),
                'emitida_en' => now(), 'vencimiento_at' => $datos['inicio_at']]);
        }
        if ($liquidada) {
            // Evidencia puramente ficticia de un cobro preexistente; no se ejecuta procesamiento de pagos.
            $intento = (string) Str::uuid();
            DB::table('intentos_pago')->insert(['id_intento' => $intento, 'id_obligacion' => $obligacion,
                'iniciado_por' => $r['actor']->getKey(), 'proveedor' => 'stripe', 'entorno' => 'test',
                'cuenta_contexto' => 'fixture_sin_proveedor', 'estado' => 'Exitoso', 'creado_en' => now(), 'actualizado_en' => now()]);
            DB::table('cobros_pago')->insert(['id_cobro' => (string) Str::uuid(), 'id_intento' => $intento,
                'id_obligacion' => $obligacion, 'proveedor' => 'stripe', 'entorno' => 'test',
                'cuenta_contexto' => 'fixture_sin_proveedor', 'referencia_cobro' => 'fixture_sin_cobro_real',
                'centavos_usd' => 101, 'aplicado' => true, 'confirmado_en' => now(), 'registrado_en' => now()]);
        }

        return [$id, $obligacion];
    }

    public static function coordinaciones(): array
    {
        return [['Administrador', false], ['Administrador', true], ['Recepcionista', false], ['Recepcionista', true]];
    }

    #[DataProvider('coordinaciones')]
    public function test_financial_coordination_preserves_price_payment_and_real_change(string $rol, bool $liquidada): void
    {
        $r = $this->preparar($rol);
        $cookies = $r['cookies'];
        [$id, $obligacion] = $this->citaFinanciera($r, liquidada: $liquidada);
        $antes = (array) DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first();
        $cobros = DB::table('cobros_pago')->get()->toJson();
        $intentos = DB::table('intentos_pago')->get()->toJson();
        $locks = [];
        DB::listen(function ($consulta) use (&$locks): void {
            if (str_contains($consulta->sql, 'obligaciones_pago') && str_contains($consulta->sql, 'for update')) {
                $locks[] = DB::transactionLevel();
            }
        });
        [$reprogramada, $datos] = $this->reprogramar($cookies, $id, $this->alternativas($cookies, $id));
        $posterior = DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first();
        $esperado = $liquidada ? $antes['vencimiento_at'] : $reprogramada['inicio_at'];
        $this->assertTrue(CarbonImmutable::parse($esperado)->equalTo(CarbonImmutable::parse($posterior->vencimiento_at)));
        $this->assertTrue($posterior->exigible);
        $evento = DB::table('bitacora')->where('registro_referido', $id)->where('tipo_evento', 'CITA_REPROGRAMADA')->first();
        $auditoria = json_decode($evento->cambios_seguros, true);
        if ($liquidada) {
            $this->assertArrayNotHasKey('coordinacion_financiera', $auditoria);
        } else {
            $this->assertSame($obligacion, $auditoria['coordinacion_financiera']['id_obligacion']);
            $this->assertSame($reprogramada['inicio_at'], $auditoria['coordinacion_financiera']['despues']['vencimiento_at']);
            $this->assertSame(CarbonImmutable::parse($antes['vencimiento_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
                $auditoria['coordinacion_financiera']['antes']['vencimiento_at']);
        }
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos)->assertConflict();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($reprogramada))->assertOk()
            ->assertDontSee('id_obligacion')->assertDontSee('centavos_bob');
        $final = (array) DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first();
        $this->assertFalse($final['exigible']);
        $cambio = DB::table('cambios_cita')->where('id_cambio_cita', $final['id_cambio_anulacion'])->first();
        $this->assertSame('CANCELACION', $cambio->operacion);
        $this->assertSame($id, (string) $cambio->id_cita);
        $this->assertSame((string) $r['actor']->getKey(), (string) $cambio->id_actor);
        $this->assertTrue(CarbonImmutable::parse($final['anulada_en'])->equalTo(CarbonImmutable::parse($cambio->realizado_en)));
        $evento = DB::table('bitacora')->where('registro_referido', $id)->where('tipo_evento', 'CITA_CANCELADA')->first();
        $this->assertSame((string) $r['actor']->getKey(), (string) $evento->id_usuario_responsable);
        $seguro = json_decode($evento->cambios_seguros, true)['coordinacion_financiera'];
        $this->assertSame((string) $cambio->id_cambio_cita, $seguro['id_cambio_cita']);
        $this->assertFalse($seguro['despues']['exigible']);
        foreach (['id_obligacion', 'id_cita', 'centavos_bob', 'centavos_usd', 'tasa_numerador', 'tasa_denominador',
            'moneda_original', 'moneda_cobro', 'emitida_por', 'emitida_en'] as $campo) {
            $this->assertSame($antes[$campo], $final[$campo]);
        }
        $this->assertNotEmpty($locks);
        $this->assertGreaterThan(1, min($locks));
        $this->assertSame($cobros, DB::table('cobros_pago')->get()->toJson());
        $this->assertSame($intentos, DB::table('intentos_pago')->get()->toJson());
        // F4: la cancelación prepara devolución durable del aplicado, incluso con Stripe apagado.
        $this->assertSame($liquidada ? 1 : 0, DB::table('devoluciones_pago')->count());
        $this->assertSame($liquidada ? 1 : 0, DB::table('operaciones_pago')->count());
        if ($liquidada) {
            $this->assertSame('Pendiente', DB::table('devoluciones_pago')->value('estado'));
            $this->assertSame('Devolver', DB::table('operaciones_pago')->value('tipo'));
        }
        $this->assertSame(1, DB::table('obligaciones_pago')->count());
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($reprogramada))->assertConflict();
        $this->assertSame($final, (array) DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first());
    }

    public function test_incorporated_without_obligation_keeps_original_management_without_creating_debt(): void
    {
        $r = $this->preparar('Recepcionista');
        $cookies = $r['cookies'];
        [$id] = $this->citaFinanciera($r, conObligacion: false);
        [$reprogramada] = $this->reprogramar($cookies, $id, $this->alternativas($cookies, $id));
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($reprogramada))->assertOk();
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->assertFalse(DB::table('citas')->where('id_cita', $r['cita']['id_cita'])->value('incorporada_finanzas'));
    }

    public static function fallosFinancieros(): array
    {
        return [['cancelacion', 'auditoria'], ['reprogramacion', 'auditoria'], ['cancelacion', 'persistencia'], ['reprogramacion', 'persistencia']];
    }

    #[DataProvider('fallosFinancieros')]
    public function test_financial_or_audit_failure_rolls_back_entire_management(string $operacion, string $fallo): void
    {
        $r = $this->preparar('Administrador');
        $cookies = $r['cookies'];
        [$id, $obligacion] = $this->citaFinanciera($r);
        $gestion = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $datos = $this->originales($gestion);
        if ($operacion === 'reprogramacion') {
            $vista = $this->alternativas($cookies, $id);
            $datos = [...$datos, 'fecha' => '2026-11-01', 'hora_inicio' => $vista['turnos'][0]['hora_inicio'],
                'revision_turno' => $vista['turnos'][0]['revision_turno']];
        }
        $citaAntes = (array) DB::table('citas')->where('id_cita', $id)->first();
        $obligacionAntes = (array) DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first();
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        if ($fallo === 'auditoria') {
            $this->partialMock(BitacoraRepository::class, function ($mock) use ($operacion): void {
                $mock->shouldReceive($operacion === 'cancelacion' ? 'citaCancelada' : 'citaReprogramada')->once()
                    ->andThrow(new \RuntimeException('Fallo simulado seguro'));
            });
        } else {
            $this->partialMock(ObligacionPagoRepository::class, function ($mock): void {
                $mock->shouldReceive('guardar')->once()->andThrow(new \RuntimeException('Fallo simulado seguro'));
            });
        }
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/'.$operacion, $datos)->assertStatus(500);
        $this->assertSame($citaAntes, (array) DB::table('citas')->where('id_cita', $id)->first());
        $this->assertSame($obligacionAntes, (array) DB::table('obligaciones_pago')->where('id_obligacion', $obligacion)->first());
        $this->assertSame(0, DB::table('cambios_cita')->where('id_cita', $id)->count());
        $this->assertSame(0, DB::table('bitacora')->where('registro_referido', $id)->whereIn('tipo_evento', ['CITA_CANCELADA', 'CITA_REPROGRAMADA'])->count());
        $this->assertNull(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'));
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Recepcionista']];
    }

    #[DataProvider('operadores')]
    public function test_real_booking_management_reconciliation_cancellation_history_and_reuse(string $rol): void
    {
        $r = $this->preparar($rol);
        $this->partialMock(ObligacionPagoRepository::class, function ($mock): void {
            $mock->shouldNotReceive('porCitaBloqueada');
        });
        $cookies = $r['cookies'];
        $admin = $r['administracion'];
        $id = $r['cita']['id_cita'];
        $antes = (array) DB::table('citas')->where('id_cita', $id)->first();
        // Avisos preparados únicamente para probar invalidación; no acredita generador CU-28.
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 1, 'generado_en' => now()]);
        $config = $this->navegador($admin, 'GET', '/api/configuracion-citas')->assertOk()->json('data');
        $this->navegador($admin, 'PATCH', '/api/configuracion-citas', ['duracion_turno_minutos' => 20,
            'actualizado_en_original' => $config['actualizado_en']])->assertOk();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->assertJsonPath('data.acciones.reprogramar', true);
        [$reprogramada, $datos] = $this->reprogramar($cookies, $id, $this->alternativas($cookies, $id));
        $this->assertSame(20, $reprogramada['duracion_minutos']);
        $this->assertSame(2, $reprogramada['version_programacion']);
        $this->assertNotSame($r['cita']['inicio_at'], $reprogramada['inicio_at']);
        $this->assertNotNull(DB::table('recordatorios')->where('id_cita', $id)->value('invalidado_en'));
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/reprogramacion', $datos)->assertConflict()
            ->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $this->navegador($cookies, 'GET', '/api/agenda?'.http_build_query(['id_medico' => $r['medico'], 'fecha' => '2026-11-01']))
            ->assertOk()->assertJsonPath('data.0.inicio_at', $reprogramada['inicio_at']);
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'].'/citas?periodo=todas')->assertOk()
            ->assertJsonPath('data.0.duracion_minutos', 20);
        $this->navegador($cookies, 'GET', '/api/citas/'.$id)->assertOk()->assertJsonPath('data.inicio_at', $reprogramada['inicio_at']);
        // La respuesta de escritura puede perderse: el GET posterior consulta estado, no reenvía PATCH.
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertUnauthorized();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $r['actor'])->assertOk();
        $gestion = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()
            ->assertJsonPath('data.ultimo_cambio.operacion', 'REPROGRAMACION')->json('data');
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $r['payload']['propuesta']])->assertOk()
            ->assertJsonPath('data.cita.version_programacion', 2);
        $this->navegador($cookies, 'POST', '/api/citas', $r['payload'])->assertOk()->assertJsonPath('meta.repetida', true)
            ->assertJsonPath('data.inicio_at', $reprogramada['inicio_at']);
        DB::table('recordatorios')->insert(['id_cita' => $id, 'version_programacion' => 2, 'generado_en' => now(), 'leido_en' => now()]);
        $motivo = 'MOTIVO-CANCELACION-FICTICIO-PRIVADO';
        $cancelacion = [...$this->originales($gestion), 'motivo' => $motivo];
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $cancelacion)->assertOk()
            ->assertJsonPath('data.estado', 'Cancelada')->assertJsonPath('data.version_programacion', 2)->assertDontSee($motivo);
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $cancelacion)->assertConflict();
        $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->assertJsonPath('data.acciones.cancelar', false)
            ->assertJsonPath('data.ultimo_cambio.operacion', 'CANCELACION')->assertDontSee($motivo);
        $this->navegador($cookies, 'POST', '/api/citas', $r['payload'])->assertOk()->assertJsonPath('meta.repetida', true)
            ->assertJsonPath('data.estado', 'Cancelada');
        $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'].'/citas?periodo=todas&estado=Cancelada')->assertOk()
            ->assertJsonPath('data.0.id_cita', $id)->assertJsonPath('data.0.inicio_at', $reprogramada['inicio_at']);
        $this->assertSame(0, DB::table('recordatorios')->where('id_cita', $id)->whereNull('invalidado_en')->count());
        $this->assertSame(2, DB::table('cambios_cita')->where('id_cita', $id)->count());
        foreach (['CITA_PROGRAMADA', 'CITA_REPROGRAMADA', 'CITA_CANCELADA'] as $tipo) {
            $evento = $this->navegador($admin, 'GET', '/api/bitacora?'.http_build_query(['tipo_evento' => $tipo,
                'entidad_referida' => 'Cita', 'registro_referido' => $id]))->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
            $this->navegador($admin, 'GET', '/api/bitacora/'.$evento)->assertOk()
                ->assertJsonPath('data.id_usuario_responsable', (string) $r['actor']->getKey())
                ->assertJsonPath('data.rol_responsable', $rol)->assertDontSee($motivo)
                ->assertDontSee($r['payload']['motivo_breve'])->assertDontSee($r['payload']['propuesta']);
        }
        [$nueva] = $this->reservar($cookies, $r['criterios']);
        $this->assertNotSame($id, $nueva['id_cita']);
        $this->assertSame($r['cita']['inicio_at'], $nueva['inicio_at']);
        $despues = (array) DB::table('citas')->where('id_cita', $id)->first();
        foreach (['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'creada_por', 'creada_en', 'id_propuesta_reserva', 'motivo_breve'] as $campo) {
            $this->assertSame($antes[$campo], $despues[$campo]);
        }
        $this->assertSame(2, DB::table('citas')->count());
        $this->assertSame(0, DB::table('consultas_medicas')->count());
    }

    #[DataProvider('operadores')]
    public function test_real_location_transfer_and_inactive_chart_preserve_same_cita_and_stale_guards(string $rol): void
    {
        $r = $this->preparar($rol);
        $cookies = $r['cookies'];
        $admin = $r['administracion'];
        $id = $r['cita']['id_cita'];
        $original = $this->navegador($cookies, 'GET', '/api/citas/'.$id.'/gestion')->assertOk()->json('data');
        $paciente = $this->navegador($cookies, 'GET', '/api/pacientes/'.$r['paciente'])->assertOk()->json('data');
        $this->navegador($cookies, 'PATCH', '/api/pacientes/'.$r['paciente'].'/estado', ['activo' => false,
            'motivo' => 'Cambio administrativo ficticio', 'actualizado_en_original' => $paciente['actualizado_en']])->assertOk();
        $sala = $this->navegador($admin, 'POST', '/api/consultorios', ['codigo_consultorio' => 'GES-INT-2', 'nombre' => 'Segunda sala ficticia'])
            ->assertCreated()->json('data.id_consultorio');
        $version = $this->navegador($admin, 'GET', '/api/medicos/'.$r['medico'].'/consultorio')->assertOk()->json('data.actualizado_en');
        $seleccion = ['id_consultorio' => $sala, 'actualizado_en_original' => $version];
        $impacto = $this->navegador($admin, 'POST', '/api/medicos/'.$r['medico'].'/consultorio/impacto', $seleccion)
            ->assertOk()->assertJsonPath('data.totales.conflictos', 0)->json('data');
        $this->navegador($admin, 'PUT', '/api/medicos/'.$r['medico'].'/consultorio', [...$seleccion,
            'huella_impacto' => $impacto['huella_impacto'], 'confirmar_traslados' => true])->assertOk();
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($original))->assertConflict()
            ->assertJsonPath('error.code', 'CITA_EDICION_OBSOLETA');
        $vista = $this->alternativas($cookies, $id);
        $this->assertSame($sala, $vista['cita_original']['id_consultorio']);
        $this->assertSame(1, $vista['cita_original']['version_programacion']);
        [$reprogramada] = $this->reprogramar($cookies, $id, $vista);
        $this->assertSame($sala, $reprogramada['id_consultorio']);
        $turnos = $this->navegador($cookies, 'GET', '/api/reservas/disponibilidad?'.http_build_query($r['criterios']))
            ->assertOk()->assertJsonPath('data.paciente_reservable', false)->json('data.turnos');
        $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$r['criterios'],
            'hora_inicio' => $turnos[0]['hora_inicio'], 'revision_turno' => $turnos[0]['revision_turno']])->assertConflict()
            ->assertJsonPath('error.code', 'CITA_PACIENTE_NO_DISPONIBLE');
        $this->navegador($cookies, 'PATCH', '/api/citas/'.$id.'/cancelacion', $this->originales($reprogramada))->assertOk()
            ->assertJsonPath('data.estado', 'Cancelada');
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(3, DB::table('cambios_cita')->where('id_cita', $id)->count());
        $this->assertFalse(DB::table('pacientes')->where('id_paciente', $r['paciente'])->value('activo'));
        $this->assertSame(0, DB::table('recordatorios')->count());
    }
}
