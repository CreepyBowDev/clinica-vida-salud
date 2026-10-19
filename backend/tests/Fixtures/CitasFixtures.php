<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CitasFixtures
{
    private function citaResources(): array
    {
        $role = DB::table('roles')->where('nombre', 'Recepcionista')->value('id_rol');
        $role ??= DB::table('roles')->insertGetId(['nombre' => 'Recepcionista'], 'id_rol');
        $actor = DB::table('usuarios')->insertGetId(['id_rol' => $role, 'correo_acceso' => Str::random(16).'@example.test'], 'id_usuario');
        $patient = DB::table('pacientes')->insertGetId(['codigo_paciente' => 'PRUEBA-'.Str::random(16), 'nombres' => 'Paciente ficticio', 'apellidos' => 'De prueba', 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000'], 'id_paciente');
        $specialty = DB::table('especialidades')->value('id_especialidad');
        $specialty ??= DB::table('especialidades')->insertGetId(['nombre' => 'Especialidad ficticia'], 'id_especialidad');
        $rooms = [];
        $doctors = [];
        for ($i = 0; $i < 2; $i++) {
            $rooms[] = DB::table('consultorios')->insertGetId(['codigo_consultorio' => 'PRUEBA-'.Str::random(16), 'nombre' => 'Consultorio ficticio '.$i], 'id_consultorio');
            $doctors[] = DB::table('medicos')->insertGetId(['id_consultorio' => $rooms[$i], 'codigo_medico' => 'PRUEBA-'.Str::random(16), 'registro_profesional' => 'REG-FICTICIO', 'nombres' => 'Médico ficticio', 'apellidos' => 'De prueba', 'telefono' => '70000000', 'correo_contacto' => 'contacto@example.test'], 'id_medico');
            DB::table('medico_especialidad')->insert(['id_medico' => $doctors[$i], 'id_especialidad' => $specialty]);
        }

        return compact('actor', 'patient', 'specialty', 'rooms', 'doctors');
    }

    private function citaRow(array $resources, array $values = []): array
    {
        return array_replace([
            'codigo_cita' => 'PRUEBA-'.Str::random(16),
            'id_paciente' => $resources['patient'],
            'id_medico' => $resources['doctors'][0],
            'id_especialidad' => $resources['specialty'],
            'id_consultorio' => $resources['rooms'][0],
            'creada_por' => $resources['actor'],
            'inicio_at' => '2026-11-01T12:00:00Z',
            'fin_at' => '2026-11-01T12:15:00Z',
            'duracion_minutos' => 15,
            'sede_atencion' => 'Sede ficticia de prueba',
            'consultorio_atencion' => 'PRUEBA — Consultorio ficticio',
        ], $values);
    }
}
