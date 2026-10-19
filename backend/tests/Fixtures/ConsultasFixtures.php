<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait ConsultasFixtures
{
    use CitasFixtures;

    private function consultaContext(): array
    {
        $resources = $this->citaResources();
        $role = DB::table('roles')->where('nombre', 'Médico')->value('id_rol');
        $role ??= DB::table('roles')->insertGetId(['nombre' => 'Médico'], 'id_rol');
        $author = DB::table('usuarios')->insertGetId(['id_rol' => $role, 'correo_acceso' => Str::random(16).'@example.test', 'password_hash' => Hash::make('Frase ficticia de prueba 123'), 'estado_cuenta' => 'Activa', 'correo_verificado_en' => '2026-10-19T12:00:00Z'], 'id_usuario');
        DB::table('medicos')->where('id_medico', $resources['doctors'][0])->update(['id_usuario' => $author]);
        $appointment = DB::table('citas')->insertGetId($this->citaRow($resources), 'id_cita');
        $consultation = DB::table('consultas_medicas')->insertGetId($this->consultaRow($appointment), 'id_consulta');

        return array_merge($resources, compact('author', 'appointment', 'consultation'));
    }

    private function consultaRow(int $appointment, array $values = []): array
    {
        return array_replace(['codigo_consulta' => 'PRUEBA-'.Str::random(16), 'id_cita' => $appointment], $values);
    }

    private function versionConsultaRow(array $context, array $values = []): array
    {
        return array_replace(['id_consulta' => $context['consultation'], 'numero_version' => 1, 'id_autor' => $context['author'], 'registrada_en' => '2026-11-01T12:15:00Z', 'tipo_consulta' => 'Control', 'motivo_consulta' => 'Motivo clínico ficticio de prueba'], $values);
    }

    private function versionAntecedenteRow(array $context, array $values = []): array
    {
        return array_replace(['id_paciente' => $context['patient'], 'numero_version' => 1, 'id_autor' => $context['author'], 'registrada_en' => '2026-11-01T12:15:00Z', 'id_consulta_origen' => $context['consultation']], $values);
    }
}
