<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AutenticacionFixtures;
use Tests\Fixtures\CitasFixtures;
use Tests\TestCase;

class AgendaIntegracionTest extends TestCase
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

    public static function reservantes(): array
    {
        return [['Recepcionista'], ['Paciente']];
    }

    #[DataProvider('reservantes')]
    public function test_real_reservation_is_consistent_across_four_roles_and_survives_logout_and_elapsed_time(string $reservante): void
    {
        // Identidades ya verificadas y planificación son fixtures: no acredita altas/invitaciones nuevas.
        $usuarios = $cookies = [];
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $usuarios[$rol] = $this->usuarioAuth($rol);
            $cookies[$rol] = $this->csrfAuth();
            $this->loginAuth($cookies[$rol], $usuarios[$rol])->assertOk();
        }
        $r = $this->citaResources();
        $r['patient'] = $usuarios['Paciente']->paciente->getKey();
        $r['doctors'][0] = $usuarios['Médico']->medico->getKey();
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => true]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => true, 'id_consultorio' => $r['rooms'][0]]);
        DB::table('medico_especialidad')->insert(['id_medico' => $r['doctors'][0], 'id_especialidad' => $r['specialty']]);
        DB::table('horarios_atencion')->insert(['id_medico' => $r['doctors'][0], 'dia_semana' => 7, 'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00']);
        $seleccion = ['id_medico' => (string) $r['doctors'][0], 'id_especialidad' => (string) $r['specialty'], 'fecha' => '2026-11-01',
            ...($reservante === 'Recepcionista' ? ['id_paciente' => (string) $r['patient']] : [])];
        $turno = $this->navegador($cookies[$reservante], 'GET', '/api/reservas/disponibilidad?'.http_build_query($seleccion))->assertOk()->json('data.turnos.0');
        $motivo = 'MOTIVO-INTEGRACION-FICTICIO';
        $propuesta = $this->navegador($cookies[$reservante], 'POST', '/api/reservas/propuesta', [...$seleccion,
            'hora_inicio' => $turno['hora_inicio'], 'revision_turno' => $turno['revision_turno'], 'motivo_breve' => $motivo])->assertOk()->json('data.propuesta');
        $cita = $this->navegador($cookies[$reservante], 'POST', '/api/citas', ['propuesta' => $propuesta, 'motivo_breve' => $motivo])->assertCreated()->json('data');
        $detalle = '/api/citas/'.$cita['id_cita'];
        $historial = '/api/pacientes/'.$r['patient'].'/citas';
        $agenda = '/api/agenda?id_medico='.$r['doctors'][0];
        $before = $this->snapshot();
        foreach (['Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $rol) {
            $path = match ($rol) {
                'Médico' => '/api/agenda', 'Paciente' => '/api/mis-citas', default => $agenda,
            };
            $listado = $this->navegador($cookies[$rol], 'GET', $path)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id_cita', $cita['id_cita'])->assertDontSee('motivo_breve')->assertDontSee($motivo)
                ->assertHeader('Cache-Control', 'no-store, private')->json('data.0');
            $data = $this->navegador($cookies[$rol], 'GET', $detalle)->assertOk()->json('data');
            foreach (['codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos', 'estado', 'sede_atencion', 'consultorio_atencion'] as $campo) {
                $this->assertSame($cita[$campo], $listado[$campo]);
                $this->assertSame($listado[$campo], $data[$campo]);
            }
            if ($rol === 'Administrador') {
                $this->assertArrayNotHasKey('motivo_breve', $data);
            } else {
                $this->assertSame($motivo, $data['motivo_breve']);
            }
            $this->assertSame($rol === 'Recepcionista', array_key_exists('documento_identidad', $data['paciente']));
            $this->assertSame($rol === 'Recepcionista', array_key_exists('telefono', $data['paciente']));
            $this->assertSame($rol === 'Médico', array_key_exists('fecha_nacimiento', $data['paciente']));
            if (in_array($rol, ['Administrador', 'Recepcionista'], true)) {
                $this->navegador($cookies[$rol], 'GET', '/api/agenda/medicos?nombre='.$usuarios['Médico']->medico->codigo_medico)
                    ->assertOk()->assertJsonPath('data.0.id_medico', (string) $r['doctors'][0]);
            }
            $this->navegador($cookies[$rol], 'GET', $historial)->assertStatus($rol === 'Recepcionista' ? 200 : 403);
            $this->navegador($cookies[$rol], 'POST', '/api/auth/logout')->assertNoContent();
            $this->navegador($cookies[$rol], 'GET', $detalle)->assertUnauthorized();
            $cookies[$rol] = $this->csrfAuth();
            $this->loginAuth($cookies[$rol], $usuarios[$rol])->assertOk();
            $this->navegador($cookies[$rol], 'GET', $detalle)->assertOk()->assertJsonPath('data.id_cita', $cita['id_cita']);
        }
        // Logout/login genera auditoría de autenticación: las lecturas no crean eventos de negocio.
        $after = $this->snapshot();
        $this->assertSame($before, $after);
        $otro = $this->usuarioAuth('Paciente');
        $ajenas = $this->csrfAuth();
        $this->loginAuth($ajenas, $otro)->assertOk();
        $this->navegador($ajenas, 'GET', '/api/mis-citas')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($this->navegador($ajenas, 'GET', $detalle)->assertNotFound()->json(),
            $this->navegador($ajenas, 'GET', '/api/citas/9223372036854775807')->assertNotFound()->json());

        // Inactividad/ubicación actuales simuladas: no acredita APIs administrativas ni CU-15–CU-19.
        DB::table('pacientes')->where('id_paciente', $r['patient'])->update(['activo' => false]);
        DB::table('medicos')->where('id_medico', $r['doctors'][0])->update(['activo' => false, 'id_consultorio' => $r['rooms'][1]]);
        DB::table('consultorios')->where('id_consultorio', $r['rooms'][0])->update(['nombre' => 'Nombre posterior ficticio']);
        CarbonImmutable::setTestNow($cita['fin_at']);
        $this->navegador($cookies['Paciente'], 'GET', '/api/mis-citas')->assertOk()->assertJsonCount(0, 'data');
        $this->navegador($cookies['Paciente'], 'GET', '/api/mis-citas?periodo=anteriores')->assertOk()->assertJsonPath('data.0.id_cita', $cita['id_cita'])
            ->assertJsonPath('data.0.estado', 'Programada')->assertJsonPath('data.0.consultorio_atencion', $cita['consultorio_atencion']);
        $this->navegador($cookies['Médico'], 'GET', '/api/agenda')->assertOk()->assertJsonPath('data.0.id_cita', $cita['id_cita']);
        $this->navegador($cookies['Recepcionista'], 'GET', $historial)->assertOk()->assertJsonPath('data.0.id_cita', $cita['id_cita']);
        $this->assertSame('Programada', DB::table('citas')->value('estado'));
        $this->assertSame(1, DB::table('citas')->count());
        $this->assertSame(1, DB::table('bitacora')->where('tipo_evento', 'CITA_PROGRAMADA')->count());
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['citas', 'cambios_cita', 'recordatorios', 'consultas_medicas'] as $table) {
            $rows[$table] = DB::table($table)->get()->toJson();
        }

        return $rows;
    }

    public function test_history_can_be_traversed_using_returned_links_without_omissions(): void
    {
        $actor = $this->usuarioAuth('Paciente');
        $cookies = $this->csrfAuth();
        $this->loginAuth($cookies, $actor)->assertOk();
        $r = $this->citaResources();
        $r['patient'] = $actor->paciente->getKey();
        $ids = [];
        foreach (['2000-01-01T12:00:00Z', '2026-11-01T12:00:00Z', '2099-01-01T12:00:00Z'] as $fecha) {
            $inicio = CarbonImmutable::parse($fecha);
            $ids[] = (string) DB::table('citas')->insertGetId($this->citaRow($r, ['inicio_at' => $inicio, 'fin_at' => $inicio->addMinutes(15)]), 'id_cita');
        }
        $before = $this->snapshot();
        $url = '/api/mis-citas?periodo=todas&estado=Programada&per_page=1';
        $vistos = [];
        do {
            $page = $this->navegador($cookies, 'GET', $url)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.filtros.periodo', 'todas')->assertJsonPath('meta.filtros.estado', 'Programada')->json();
            $vistos[] = $page['data'][0]['id_cita'];
            $this->assertLessThanOrEqual(3, count($vistos));
            $this->navegador($cookies, 'GET', '/api/citas/'.end($vistos))->assertOk();
            $url = $page['links']['next'];
        } while ($url !== null);
        $this->assertSame(array_reverse($ids), $vistos);
        $this->assertSame($before, $this->snapshot());
    }
}
