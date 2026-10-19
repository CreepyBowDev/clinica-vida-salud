<?php

namespace Clinica\data\database\seeders;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DemostracionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('La demostración solo está permitida en PostgreSQL local o de pruebas.');
        }
        $identity = DB::selectOne('SELECT current_database() AS database, current_user AS username');
        $expected = app()->environment('testing')
            ? ['clinica_vida_salud_test', 'clinica_vida_salud_test']
            : ['clinica_vida_salud', 'clinica_vida_salud_app'];
        if ([$identity->database, $identity->username] !== $expected) {
            throw new RuntimeException('La demostración requiere la base y el rol locales configurados para este proyecto.');
        }
        $password = config('seeders.demo_password');
        if ($password !== null && $password !== '' && (! is_string($password) || mb_strlen($password) < 12)) {
            throw new RuntimeException('CLINICA_DEMO_PASSWORD debe tener al menos 12 caracteres.');
        }
        $hash = Hash::make($password ?: Str::random(64));

        DB::transaction(function () use ($hash): void {
            DB::select('SELECT pg_advisory_xact_lock(19026, 1)');
            $files = [];
            try {
                $this->call(DatabaseSeeder::class);
                $this->populate($hash, $files);
            } catch (Throwable $exception) {
                // Compensación antes de liberar el bloqueo: solo archivos nuevos de esta carga.
                foreach ($files as $path) {
                    Storage::disk('local')->delete($path);
                }
                throw $exception;
            }
        });
        $this->command?->info('Demostración ficticia cargada. Las reejecuciones conservan los registros existentes.');
    }

    private function populate(string $hash, array &$files): void
    {
        $now = CarbonImmutable::now('America/La_Paz');
        $roles = DB::table('roles')->pluck('id_rol', 'nombre');
        $users = [];
        foreach (['admin' => 'Administrador', 'recepcion' => 'Recepcionista', 'medico1' => 'Médico', 'medico2' => 'Médico', 'medico3' => 'Médico', 'paciente' => 'Paciente'] as $alias => $role) {
            $users[$alias] = $this->ensure('usuarios', 'id_usuario', ['correo_acceso' => 'demo.'.$alias.'@example.test'], [
                'id_rol' => $roles[$role], 'password_hash' => $hash, 'estado_cuenta' => 'Activa',
                'correo_verificado_en' => $now, 'nombres_personal' => $role === 'Paciente' ? null : 'Demo '.$alias,
                'apellidos_personal' => $role === 'Paciente' ? null : 'Ficticio',
            ], ['id_rol']);
        }
        $doctors = [];
        $rooms = [];
        $specialties = [];
        foreach (['Pediatría', 'Medicina General', 'Geriatría'] as $i => $specialty) {
            $n = $i + 1;
            $rooms[$i] = $this->ensure('consultorios', 'id_consultorio', ['codigo_consultorio' => 'DEMO-VS-C0'.$n], ['nombre' => 'Consultorio ficticio demo '.$n]);
            $specialties[$i] = DB::table('especialidades')->whereRaw('lower(btrim(nombre)) = ?', [mb_strtolower($specialty, 'UTF-8')])->value('id_especialidad');
            $doctors[$i] = $this->ensure('medicos', 'id_medico', ['codigo_medico' => 'DEMO-VS-M0'.$n], [
                'id_usuario' => $users['medico'.$n], 'id_consultorio' => $rooms[$i],
                'registro_profesional' => 'DEMO-NO-VALIDO-'.$n, 'nombres' => 'Médico Demo '.$n,
                'apellidos' => 'Ficticio', 'telefono' => '00000000', 'correo_contacto' => 'demo.medico'.$n.'@example.test',
            ], ['id_usuario', 'id_consultorio']);
            DB::table('medico_especialidad')->insertOrIgnore(['id_medico' => $doctors[$i], 'id_especialidad' => $specialties[$i]]);
            for ($day = 1; $day <= 7; $day++) {
                $this->ensure('horarios_atencion', 'id_horario', ['id_medico' => $doctors[$i], 'dia_semana' => $day, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00'], []);
            }
        }
        $patients = [];
        for ($n = 1; $n <= 5; $n++) {
            $patients[] = $this->ensure('pacientes', 'id_paciente', ['codigo_paciente' => 'DEMO-VS-P0'.$n], [
                'id_usuario' => $n === 1 ? $users['paciente'] : null, 'documento_identidad' => 'DEMO-CI-0'.$n,
                'nombres' => 'Paciente Demo '.$n, 'apellidos' => 'Ficticio', 'fecha_nacimiento' => ['2016-01-10', '1985-02-12', '1950-03-15', '1990-04-20', '1980-05-25'][$n - 1],
                'telefono' => '00000000',
            ], ['id_usuario']);
        }
        // Se ancla la demostración al primer turno creado; repetirla no desplaza fechas.
        $stored = DB::table('citas')->where('codigo_cita', 'DEMO-VS-CITA-01')->value('inicio_at');
        $future = $stored ? CarbonImmutable::parse($stored)->setTimezone('America/La_Paz') : $now->addDay()->setTime(9, 0);
        $past = $future->subDays(8);
        $appointments = [];
        foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'] as $i => $state) {
            $doctor = $i % 3;
            $start = $i === 0 ? $future : ($i === 1 ? $future->subDay() : $past->addHours($i - 2));
            $code = 'DEMO-VS-CITA-0'.($i + 1);
            $exists = DB::table('citas')->where('codigo_cita', $code)->exists();
            $appointments[$i] = $this->ensure('citas', 'id_cita', ['codigo_cita' => $code], [
                'id_paciente' => $patients[$i], 'id_medico' => $doctors[$doctor], 'id_especialidad' => $specialties[$doctor],
                'id_consultorio' => $rooms[$doctor], 'creada_por' => $users['recepcion'],
                'inicio_at' => $start, 'fin_at' => $start->addMinutes(15), 'duracion_minutos' => 15, 'estado' => $state,
                'sede_atencion' => 'Clínica Vida Salud', 'consultorio_atencion' => 'Consultorio ficticio demo '.($doctor + 1),
                'creada_en' => $start->subDays(2), 'actualizada_en' => $state === 'Programada' ? $start->subDays(2) : $start->addMinutes(15),
            ], ['id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio']);
            if (! $exists) {
                $this->appointmentHistory($appointments[$i], $users['recepcion'], null, 'Programada', $start->subDays(2));
                if ($state !== 'Programada') {
                    $actor = $state === 'Atendida' ? $users['medico'.($doctor + 1)] : $users['recepcion'];
                    $date = match ($state) {
                        'Confirmada' => $start->subMinutes(5),
                        'Cancelada' => $start->subDay(),
                        default => $start->addMinutes(15),
                    };
                    $this->appointmentHistory($appointments[$i], $actor, 'Programada', $state, $date);
                }
            }
        }
        $consultation = $this->ensure('consultas_medicas', 'id_consulta', ['codigo_consulta' => 'DEMO-VS-CONSULTA-01'], [
            'id_cita' => $appointments[2], 'iniciada_en' => $past, 'creada_en' => $past,
        ], ['id_cita']);
        for ($n = 1; $n <= 2; $n++) {
            $this->ensure('versiones_consulta', 'id_version_consulta', ['id_consulta' => $consultation, 'numero_version' => $n], [
                'id_autor' => $users['medico3'], 'registrada_en' => $past->addMinutes($n * 5), 'tipo_consulta' => 'Control',
                'motivo_consulta' => 'Consulta de demostración completamente ficticia.',
                'motivo_cambio' => $n === 2 ? 'Ampliación ficticia de documentación.' : null,
                'observaciones' => 'Ejemplo ficticio, sin valor clínico. Versión '.$n,
            ], ['id_autor']);
            $this->ensure('versiones_antecedente', 'id_version_antecedente', ['id_paciente' => $patients[2], 'numero_version' => $n], [
                'id_autor' => $users['medico3'], 'id_consulta_origen' => $consultation, 'registrada_en' => $past->addMinutes($n * 5),
                'motivo_cambio' => $n === 2 ? 'Ampliación ficticia de antecedentes.' : null,
                'antecedentes_relevantes' => 'Antecedente ficticio de demostración, versión '.$n,
            ], ['id_autor', 'id_consulta_origen']);
        }
        $this->laboratory($consultation, $users['admin'], $doctors[2], $past, $files);
        foreach (DB::table('documentos_laboratorio')->where('ruta_privada', 'like', 'demo-seeders/%')->get() as $document) {
            $disk = Storage::disk('local');
            if (! $disk->exists($document->ruta_privada)) {
                throw new RuntimeException('Falta un PDF ficticio ya registrado; se conservaron sus metadatos.');
            }
            $bytes = $disk->get($document->ruta_privada);
            if (strlen($bytes) !== $document->tamano_bytes || hash('sha256', $bytes) !== $document->sha256) {
                throw new RuntimeException('Un PDF ficticio no coincide con sus metadatos; no se sobrescribió.');
            }
        }
        if (! DB::table('bitacora')->where('tipo_evento', 'SEED_DEMOSTRACION')->where('registro_referido', 'DEMO-VS-V1')->exists()) {
            DB::table('bitacora')->insert([
                'tipo_evento' => 'SEED_DEMOSTRACION', 'tipo_actor' => 'SISTEMA', 'modulo' => 'Instalacion',
                'operacion' => 'CargarDemostracion', 'resultado' => 'EXITO', 'ocurrido_en' => $now->format('Y-m-d H:i:s.uP'),
                'entidad_referida' => 'ConjuntoDemostracion', 'registro_referido' => 'DEMO-VS-V1',
                'correlacion' => (string) Str::uuid(),
            ]);
        }
    }

    private function laboratory(int $consultation, int $admin, int $doctor, CarbonImmutable $date, array &$files): void
    {
        foreach (['Pendiente', 'En revisión', 'Disponible'] as $i => $state) {
            $identity = ['id_consulta' => $consultation, 'estudio_solicitado' => 'DEMO-VS-ESTUDIO-0'.($i + 1)];
            $exists = DB::table('solicitudes_laboratorio')->where($identity)->exists();
            $request = $this->ensure('solicitudes_laboratorio', 'id_solicitud', $identity, ['solicitada_en' => $date->addMinutes(10)]);
            if ($exists || $i === 0) {
                continue;
            }
            $previous = null;
            for ($n = 1; $n <= ($i === 1 ? 2 : 1); $n++) {
                $path = 'demo-seeders/resultado-'.($i + 1).'-v'.$n.'.pdf';
                $bytes = $this->pdf('Estudio demo '.($i + 1).' - version '.$n);
                $disk = Storage::disk('local');
                if ($disk->exists($path)) {
                    if (hash('sha256', $disk->get($path)) !== hash('sha256', $bytes)) {
                        throw new RuntimeException('Un documento de demostración existente tiene otro contenido; se conservó.');
                    }
                } else {
                    $files[] = $path;
                    if (! $disk->put($path, $bytes)) {
                        throw new RuntimeException('No se pudo guardar el PDF ficticio privado.');
                    }
                }
                $document = $this->ensure('documentos_laboratorio', 'id_documento', ['ruta_privada' => $path], [
                    'cargado_por' => $admin, 'nombre_original' => 'resultado-ficticio.pdf', 'tipo_mime' => 'application/pdf',
                    'tamano_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'cargado_en' => $date->addHours($n === 1 ? 1 : 3),
                ], ['cargado_por', 'sha256', 'tamano_bytes']);
                $version = $this->ensure('versiones_resultado', 'id_version_resultado', ['id_solicitud' => $request, 'numero_version' => $n], [
                    'id_documento' => $document, 'id_autor' => $admin, 'id_version_origen' => $previous,
                    'registrada_en' => $date->addHours($n === 1 ? 1 : 3)->addMinutes($n), 'motivo_cambio' => $previous ? 'Sustitución ficticia tras devolución.' : null,
                ], ['id_documento', 'id_autor']);
                DB::table('solicitudes_laboratorio')->where('id_solicitud', $request)->update(['estado_interno' => 'En revisión', 'id_version_actual' => $version]);
                if ($i === 1 && $n === 1) {
                    DB::table('revisiones_resultado')->insert([
                        'id_version_resultado' => $version, 'id_medico' => $doctor, 'decision' => 'Devolver',
                        'motivo' => 'Corrección documental ficticia solicitada.', 'revisada_en' => $date->addHours(2)->format('Y-m-d H:i:s.uP'),
                    ]);
                } elseif ($i === 2) {
                    $review = DB::table('revisiones_resultado')->insertGetId([
                        'id_version_resultado' => $version, 'id_medico' => $doctor, 'decision' => 'Autorizar', 'revisada_en' => $date->addHours(2)->format('Y-m-d H:i:s.uP'),
                    ], 'id_revision');
                    DB::table('solicitudes_laboratorio')->where('id_solicitud', $request)->update(['estado_interno' => 'Disponible', 'id_revision_publicada' => $review]);
                }
                $previous = $version;
            }
        }
    }

    private function ensure(string $table, string $key, array $identity, array $values, array $links = []): int
    {
        $existing = DB::table($table)->where($identity)->first();
        if ($existing !== null) {
            foreach ($links as $link) {
                if ($existing->{$link} !== $values[$link]) {
                    throw new RuntimeException('Un identificador reservado de demostración tiene vínculos distintos; se conservó.');
                }
            }

            return $existing->{$key};
        }

        $values = array_map(fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.uP') : $value, $values);

        return DB::table($table)->insertGetId(array_merge($identity, $values), $key);
    }

    private function appointmentHistory(int $appointment, int $actor, ?string $before, string $after, CarbonImmutable $date): void
    {
        DB::table('cambios_cita')->insert([
            'id_cita' => $appointment, 'id_actor' => $actor, 'operacion' => $before === null ? 'Programar' : $after,
            'realizado_en' => $date->format('Y-m-d H:i:s.uP'), 'estado_anterior' => $before, 'estado_nuevo' => $after,
            'datos_anteriores' => $before === null ? null : json_encode(['estado' => $before]),
            'datos_nuevos' => json_encode(['estado' => $after]), 'motivo' => 'Escenario ficticio de demostración.',
        ]);
    }

    private function pdf(string $label): string
    {
        $text = "BT /F1 18 Tf 50 760 Td (DOCUMENTO FICTICIO - SIN VALOR CLINICO) Tj 0 -40 Td /F1 12 Tf ($label) Tj 0 -30 Td (Datos sinteticos para demostracion de software.) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text).">>\nstream\n".$text."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
