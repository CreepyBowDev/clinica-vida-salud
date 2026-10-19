<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AdministradorInicialSeeder extends Seeder
{
    public function run(): void
    {
        $data = config('seeders.administrador');
        $data['correo'] = mb_strtolower(trim((string) $data['correo'], ' '), 'UTF-8');
        if (Validator::make($data, [
            'correo' => ['required', 'email:rfc', 'max:254'],
            'nombres' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'apellidos' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'password' => ['required', 'string', 'min:12'],
        ])->fails()) {
            throw new RuntimeException('Completa CLINICA_ADMIN_CORREO, CLINICA_ADMIN_NOMBRES, CLINICA_ADMIN_APELLIDOS y CLINICA_ADMIN_PASSWORD (mínimo 12 caracteres) en el entorno privado.');
        }
        if (str_ends_with($data['correo'], '@example.test')) {
            throw new RuntimeException('Reserva example.test para demostración; configura el correo del Administrador de instalación.');
        }

        DB::transaction(function () use ($data): void {
            DB::select('SELECT pg_advisory_xact_lock(19026, 1)');
            $this->call(RolSeeder::class);
            $role = DB::table('roles')->where('nombre', 'Administrador')->value('id_rol');
            $existing = DB::table('usuarios')->whereRaw('lower(btrim(correo_acceso)) = ?', [$data['correo']])->first();
            if ($existing !== null) {
                if ($existing->id_rol !== $role) {
                    throw new RuntimeException('El correo configurado ya está asociado a otra cuenta; no se modificó.');
                }

                return;
            }
            $administrators = DB::table('usuarios')->where('id_rol', $role);
            if (app()->environment(['local', 'testing'])) {
                $administrators->where('correo_acceso', '<>', 'demo.admin@example.test');
            }
            if ($administrators->exists()) {
                throw new RuntimeException('Ya existe un Administrador de instalación; utiliza el flujo autorizado para otras cuentas.');
            }
            $id = DB::table('usuarios')->insertGetId([
                'id_rol' => $role,
                'correo_acceso' => $data['correo'],
                'nombres_personal' => trim($data['nombres']),
                'apellidos_personal' => trim($data['apellidos']),
                'password_hash' => Hash::make($data['password']),
                'estado_cuenta' => 'Activa',
                'correo_verificado_en' => now()->format('Y-m-d H:i:s.uP'),
                'cambio_clave_obligatorio' => false,
            ], 'id_usuario');
            DB::table('bitacora')->insert([
                'tipo_evento' => 'INSTALACION_ADMIN_INICIAL', 'tipo_actor' => 'SISTEMA',
                'id_usuario_objetivo' => $id, 'rol_objetivo' => 'Administrador',
                'modulo' => 'Instalacion', 'operacion' => 'ProvisionarAdministrador', 'resultado' => 'EXITO',
                'ocurrido_en' => now()->format('Y-m-d H:i:s.uP'), 'entidad_referida' => 'Usuario', 'registro_referido' => (string) $id,
                'correlacion' => (string) Str::uuid(),
                'metadatos_seguros' => json_encode(['verificacion_correo' => 'AUTORIZACION_INSTALACION', 'metodo' => 'SEEDER']),
            ]);
        });
        $this->command?->info('Administrador de instalación preparado o ya existente. Se conservaron sus credenciales y estado.');
    }
}
