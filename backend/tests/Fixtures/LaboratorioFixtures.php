<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait LaboratorioFixtures
{
    use ConsultasFixtures;

    private function laboratorioContext(): array
    {
        $context = $this->consultaContext();
        $role = DB::table('roles')->where('nombre', 'Administrador')->value('id_rol');
        $role ??= DB::table('roles')->insertGetId(['nombre' => 'Administrador'], 'id_rol');
        $admin = DB::table('usuarios')->insertGetId(['id_rol' => $role, 'correo_acceso' => Str::random(16).'@example.test', 'password_hash' => Hash::make('Frase ficticia de prueba 123'), 'estado_cuenta' => 'Activa', 'correo_verificado_en' => '2026-10-19T12:00:00Z'], 'id_usuario');
        $request = DB::table('solicitudes_laboratorio')->insertGetId($this->solicitudRow($context), 'id_solicitud');
        $document = DB::table('documentos_laboratorio')->insertGetId($this->documentoRow($admin), 'id_documento');

        return array_merge($context, compact('admin', 'request', 'document'));
    }

    private function solicitudRow(array $context, array $values = []): array
    {
        return array_replace(['id_consulta' => $context['consultation'], 'estudio_solicitado' => 'Estudio ficticio de prueba', 'solicitada_en' => '2026-11-01T12:15:00Z'], $values);
    }

    private function documentoRow(int $admin, array $values = []): array
    {
        return array_replace(['cargado_por' => $admin, 'ruta_privada' => 'laboratorio/prueba-'.Str::uuid().'.pdf', 'nombre_original' => 'resultado-ficticio.pdf', 'tipo_mime' => 'application/pdf', 'tamano_bytes' => 123, 'sha256' => hash('sha256', 'Bytes ficticios; este fixture solo registra metadatos'), 'cargado_en' => '2026-11-01T12:20:00Z'], $values);
    }

    private function versionResultadoRow(array $context, array $values = []): array
    {
        return array_replace(['id_solicitud' => $context['request'], 'id_documento' => $context['document'], 'numero_version' => 1, 'id_autor' => $context['admin'], 'registrada_en' => '2026-11-01T12:20:00Z'], $values);
    }

    private function revisionRow(array $context, int $version, array $values = []): array
    {
        return array_replace(['id_version_resultado' => $version, 'id_medico' => $context['doctors'][0], 'decision' => 'Autorizar', 'revisada_en' => '2026-11-01T12:25:00Z'], $values);
    }

    private function resultadoEnRevision(array $context): int
    {
        $version = DB::table('versiones_resultado')->insertGetId($this->versionResultadoRow($context), 'id_version_resultado');
        DB::table('solicitudes_laboratorio')->where('id_solicitud', $context['request'])->update(['estado_interno' => 'En revisión', 'id_version_actual' => $version]);

        return $version;
    }
}
