<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class ReservaIntegracionTest extends TestCase
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
        DB::table('configuracion_clinica')->insertOrIgnore(['id_configuracion' => 1, 'nombre_sede' => 'Sede ficticia']);
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['env'] = 'local';
        CarbonImmutable::setTestNow('2026-11-01T11:00:00Z');
    }

    private function instalacion(): array
    {
        $admin = $this->usuarioAuth();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $admin)->assertOk();
        $especialidad = $this->navegador($cookies, 'POST', '/api/especialidades', ['nombre' => 'Reserva integración ficticia'])->assertCreated()->json('data.id_especialidad');
        $medico = $this->navegador($cookies, 'POST', '/api/medicos', ['registro_profesional' => 'RESERVA-INTEGRACION',
            'nombres' => 'Profesional', 'apellidos' => 'Ficticio', 'telefono' => '70000000',
            'correo_contacto' => 'reserva@example.test', 'especialidades' => [$especialidad]])->assertCreated()->json('data');
        $sala = $this->navegador($cookies, 'POST', '/api/consultorios', ['codigo_consultorio' => 'RES-INT', 'nombre' => 'Sala ficticia'])->assertCreated()->json('data');
        $base = '/api/medicos/'.$medico['id_medico'];
        $version = $this->navegador($cookies, 'GET', $base.'/consultorio')->assertOk()->json('data.actualizado_en');
        $propuesta = ['id_consultorio' => $sala['id_consultorio'], 'actualizado_en_original' => $version];
        $huella = $this->navegador($cookies, 'POST', $base.'/consultorio/impacto', $propuesta)->assertOk()->json('data.huella_impacto');
        $asignacion = $this->navegador($cookies, 'PUT', $base.'/consultorio', [...$propuesta, 'huella_impacto' => $huella,
            'confirmar_traslados' => false])->assertOk()->json('data');
        $this->navegador($cookies, 'POST', $base.'/horarios', ['dia_semana' => 7, 'hora_inicio' => '08:00:01',
            'hora_fin' => '10:00:01', 'actualizado_en_original' => $asignacion['actualizado_en']])->assertCreated();

        return [$cookies, $especialidad, $medico, $sala];
    }

    public static function actores(): array
    {
        return [['Recepcionista'], ['Paciente']];
    }

    #[DataProvider('actores')]
    public function test_complete_journey_uses_six_operations_and_safe_audit_with_real_sessions(string $rol): void
    {
        [$adminCookies, $especialidad, $medico, $sala] = $this->instalacion();
        $actor = $this->usuarioAuth($rol);
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        if ($rol === 'Recepcionista') {
            $paciente = $this->navegador($cookies, 'POST', '/api/pacientes', ['nombres' => 'Paciente', 'apellidos' => 'Presencial sin CI',
                'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'posibles_duplicados_revisados' => true])->assertCreated()->json('data.id_paciente');
            $this->assertNull(DB::table('pacientes')->where('id_paciente', $paciente)->value('documento_identidad'));
            $this->assertNull(DB::table('pacientes')->where('id_paciente', $paciente)->value('id_usuario'));
        } else {
            // Fixture de identidad ya activada: no acredita el flujo de alta CU-04 en esta prueba.
            $paciente = (string) DB::table('pacientes')->where('id_usuario', $actor->getKey())->value('id_paciente');
            DB::table('pacientes')->where('id_paciente', $paciente)->update(['activo' => true]);
        }
        $this->navegador($cookies, 'GET', '/api/reservas/especialidades?nombre=Reserva%20integraci%C3%B3n&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id_especialidad', $especialidad)->assertHeader('Cache-Control', 'no-store, private');
        $this->navegador($cookies, 'GET', '/api/reservas/medicos?'.http_build_query(['id_especialidad' => $especialidad, 'consultorio' => 'RES-INT', 'per_page' => 1]))
            ->assertOk()->assertJsonPath('data.0.id_medico', $medico['id_medico'])->assertDontSee('reserva@example.test');
        $seleccion = ['id_especialidad' => $especialidad, 'id_medico' => $medico['id_medico'], 'fecha' => '2026-11-01',
            ...($rol === 'Recepcionista' ? ['id_paciente' => $paciente] : [])];
        $url = '/api/reservas/disponibilidad?'.http_build_query($seleccion);
        $turno = $this->navegador($cookies, 'GET', $url)->assertOk()->json('data.turnos.0');
        $antes = DB::table('citas')->count();
        $motivo = 'MOTIVO-FICTICIO-NO-AUDITAR';
        $p = $this->navegador($cookies, 'POST', '/api/reservas/propuesta', [...$seleccion, 'hora_inicio' => $turno['hora_inicio'],
            'revision_turno' => $turno['revision_turno'], 'motivo_breve' => $motivo])->assertOk()->json('data');
        $this->assertSame($antes, DB::table('citas')->count());
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.registrada', false);
        $payload = ['propuesta' => $p['propuesta'], 'motivo_breve' => $motivo];
        $cita = $this->navegador($cookies, 'POST', '/api/citas', $payload)->assertCreated()->assertJsonPath('meta.repetida', false)->json('data');
        $this->assertSame($paciente, $cita['id_paciente']);
        $this->assertSame($sala['id_consultorio'], $cita['id_consultorio']);
        $this->assertSame('Programada', $cita['estado']);
        $this->assertSame($turno['inicio_at'], $cita['inicio_at']);
        $this->assertSame('RES-INT — Sala ficticia', $cita['consultorio_atencion']);
        $siguiente = $this->navegador($cookies, 'GET', $url)->assertOk()->json('data.turnos.0');
        $this->assertSame($cita['fin_at'], $siguiente['inicio_at']);
        $this->navegador($cookies, 'POST', '/api/auth/logout')->assertNoContent();
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertUnauthorized();
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $this->navegador($cookies, 'POST', '/api/reservas/resultado', ['propuesta' => $p['propuesta']])->assertOk()->assertJsonPath('data.cita.id_cita', $cita['id_cita']);
        $this->navegador($cookies, 'POST', '/api/citas', $payload)->assertOk()->assertJsonPath('meta.repetida', true);
        $this->navegador($cookies, 'GET', '/api/bitacora')->assertForbidden();
        $evento = $this->navegador($adminCookies, 'GET', '/api/bitacora?tipo_evento=CITA_PROGRAMADA&registro_referido='.$cita['id_cita'].'&entidad_referida=Cita')
            ->assertOk()->assertJsonCount(1, 'data')->json('data.0.id_evento');
        $detalle = $this->navegador($adminCookies, 'GET', '/api/bitacora/'.$evento)->assertOk()->assertDontSee($motivo)
            ->assertDontSee($p['propuesta'])->assertDontSee($p['id_propuesta'])->json('data');
        $this->assertSame((string) $actor->getKey(), $detalle['id_usuario_responsable']);
        $this->assertSame('EXITO', $detalle['resultado']);
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
        $this->assertSame(0, DB::table('recordatorios')->count());
        $this->assertSame(0, DB::table('cambios_cita')->count());
    }

    public function test_availability_query_count_stays_constant_with_representative_occupancy(): void
    {
        $actor = $this->usuarioAuth('Recepcionista');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '23:00:00']);
        $url = '/api/reservas/disponibilidad?'.http_build_query(['id_especialidad' => $r['specialty'], 'id_medico' => $r['doctors'][0],
            'id_paciente' => $r['patient'], 'fecha' => '2026-11-01', 'per_page' => 1]);
        $medidas = [];
        foreach ([30, 300] as $cantidad) {
            $actual = DB::table('citas')->count();
            for ($i = $actual; $i < $cantidad; $i++) {
                $inicio = CarbonImmutable::parse('2026-11-01T12:00:00Z')->addMinutes(2 * $i);
                DB::table('citas')->insert($this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinute(), 'duracion_minutos' => 1]));
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $inicio = hrtime(true);
            try {
                $this->navegador($cookies, 'GET', $url)->assertOk()->assertJsonCount(1, 'data.turnos');
                $medidas[$cantidad] = ['consultas' => count(DB::getQueryLog()), 'ms_http' => round((hrtime(true) - $inicio) / 1e6, 2)];
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        $this->assertSame($medidas[30]['consultas'], $medidas[300]['consultas']);
        $this->assertSame(300, DB::table('citas')->count());
        fwrite(STDERR, "\nReservas: medición local ficticia: ".json_encode($medidas)."\n");
    }
}
