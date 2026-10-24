<?php

namespace Clinica\data\database\seeders;

use Clinica\business\validators\PoliticaClave;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class CuentasPruebaSeeder extends Seeder
{
    public function run(): void
    {
        if (config('seeders.cuentas_prueba_habilitadas') !== true) {
            throw new RuntimeException('Habilita CLINICA_CUENTAS_PRUEBA_HABILITADAS solo en el entorno de demostración.');
        }
        $password = config('seeders.demo_password');
        if (Validator::make(['password' => $password], ['password' => PoliticaClave::reglas()])->fails()) {
            throw new RuntimeException('Configura CLINICA_DEMO_PASSWORD con al menos 12 caracteres, mayúscula, minúscula, número y carácter especial; respeta los límites del hash en el entorno privado.');
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Las cuentas de prueba requieren PostgreSQL.');
        }

        $accounts = DB::transaction(function () use ($password): array {
            DB::select('SELECT pg_advisory_xact_lock(19026, 1)');
            $roles = DB::table('roles')->pluck('id_rol', 'nombre');
            foreach (['Médico', 'Recepcionista', 'Paciente'] as $role) {
                if (! isset($roles[$role])) {
                    throw new RuntimeException('Carga primero los catálogos mediante DatabaseSeeder.');
                }
            }
            $specialties = DB::table('especialidades')->where('activa', true)->orderBy('id_especialidad')->get();
            if ($specialties->isEmpty()) {
                throw new RuntimeException('Se necesita al menos una especialidad activa.');
            }
            $accounts = [];
            foreach ($specialties as $specialty) {
                $alias = 'medico.e'.$specialty->id_especialidad;
                $id = $this->account($alias, $roles['Médico'], 'Médico', $password);
                $doctor = $this->profile('medicos', 'id_medico', ['codigo_medico' => 'PRUEBA-M-E'.$specialty->id_especialidad], [
                    'id_usuario' => $id, 'registro_profesional' => 'FICTICIO-E'.$specialty->id_especialidad,
                    'nombres' => 'Médico Prueba '.$specialty->id_especialidad, 'apellidos' => 'Ficticio',
                    'telefono' => '70000000', 'correo_contacto' => 'prueba.'.$alias.'@example.test',
                ]);
                DB::table('medico_especialidad')->insertOrIgnore([
                    'id_medico' => $doctor, 'id_especialidad' => $specialty->id_especialidad,
                ]);
                $accounts[] = ['Médico — '.$specialty->nombre, $this->email($id)];
            }
            for ($n = 1; $n <= 2; $n++) {
                $id = $this->account('recepcion'.sprintf('%02d', $n), $roles['Recepcionista'], 'Recepcionista', $password);
                $accounts[] = ['Recepcionista', $this->email($id)];
            }
            for ($n = 1; $n <= 10; $n++) {
                $number = sprintf('%02d', $n);
                $alias = 'paciente'.$number;
                $id = $this->account($alias, $roles['Paciente'], 'Paciente', $password);
                $this->profile('pacientes', 'id_paciente', ['codigo_paciente' => 'PRUEBA-P'.$number], [
                    'id_usuario' => $id, 'documento_identidad' => 'PRUEBA-CI-'.$number,
                    'nombres' => 'Paciente Prueba '.$number, 'apellidos' => 'Ficticio',
                    'fecha_nacimiento' => (1980 + $n).'-06-15', 'telefono' => '70000000',
                    'correo_contacto' => 'prueba.'.$alias.'@example.test',
                ]);
                $accounts[] = ['Paciente', $this->email($id)];
            }

            return $accounts;
        });

        $this->command?->info('Cuentas ficticias preparadas. No se modificó el Administrador ni las credenciales existentes.');
        $this->command?->table(['Rol / especialidad', 'Correo de acceso'], $accounts);
    }

    private function account(string $alias, int $role, string $roleName, #[\SensitiveParameter] string $password): int
    {
        // La referencia de auditoría identifica la cuenta incluso si después cambia su correo.
        $event = DB::table('bitacora')->where('tipo_evento', 'SEED_CUENTA_PRUEBA')
            ->where('registro_referido', $alias)->first();
        if ($event !== null) {
            $existing = DB::table('usuarios')->where('id_usuario', $event->id_usuario_objetivo)->first();
            if ($existing === null || $existing->id_rol !== $role) {
                throw new RuntimeException('Una cuenta de prueba existente tiene vínculos distintos; no se modificó.');
            }

            return $existing->id_usuario;
        }
        $email = 'prueba.'.$alias.'@example.test';
        if (DB::table('usuarios')->whereRaw('lower(btrim(correo_acceso)) = ?', [$email])->exists()) {
            throw new RuntimeException('Un correo reservado de prueba ya pertenece a una cuenta ajena; no se modificó.');
        }
        $id = DB::table('usuarios')->insertGetId([
            'id_rol' => $role, 'correo_acceso' => $email, 'password_hash' => Hash::make($password),
            'estado_cuenta' => 'Activa', 'correo_verificado_en' => now()->format('Y-m-d H:i:s.uP'),
            'nombres_personal' => $roleName === 'Paciente' ? null : 'Personal Prueba '.$alias,
            'apellidos_personal' => $roleName === 'Paciente' ? null : 'Ficticio',
            'cambio_clave_obligatorio' => false,
        ], 'id_usuario');
        DB::table('bitacora')->insert([
            'tipo_evento' => 'SEED_CUENTA_PRUEBA', 'tipo_actor' => 'SISTEMA',
            'id_usuario_objetivo' => $id, 'rol_objetivo' => $roleName,
            'modulo' => 'Instalacion', 'operacion' => 'CargarCuentaPrueba', 'resultado' => 'EXITO',
            'ocurrido_en' => now()->format('Y-m-d H:i:s.uP'), 'entidad_referida' => 'CuentaPrueba',
            'registro_referido' => $alias, 'correlacion' => (string) Str::uuid(),
            'metadatos_seguros' => json_encode(['verificacion_correo' => 'SIMULADA_PRUEBAS', 'metodo' => 'SEEDER']),
        ]);

        return $id;
    }

    private function profile(string $table, string $key, array $identity, array $values): int
    {
        $existing = DB::table($table)->where($identity)->first();
        if ($existing !== null) {
            if ($existing->id_usuario !== $values['id_usuario']) {
                throw new RuntimeException('Una ficha reservada de prueba tiene otro vínculo; no se modificó.');
            }

            return $existing->{$key};
        }

        return DB::table($table)->insertGetId(array_merge($identity, $values), $key);
    }

    private function email(int $id): string
    {
        return DB::table('usuarios')->where('id_usuario', $id)->value('correo_acceso');
    }
}
