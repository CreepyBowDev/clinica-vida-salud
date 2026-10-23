<?php

namespace Clinica\presentation\dtos\agenda;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitaListadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return ['id_cita' => (string) $row['id_cita'], 'codigo_cita' => $row['codigo_cita'],
            'inicio_at' => CarbonImmutable::parse($row['inicio_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'fin_at' => CarbonImmutable::parse($row['fin_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'duracion_minutos' => (int) $row['duracion_minutos'], 'estado' => $row['estado'], 'modalidad' => $row['modalidad'],
            'sede_atencion' => $row['sede_atencion'], 'consultorio_atencion' => $row['consultorio_atencion'],
            'creada_en' => CarbonImmutable::parse($row['creada_en'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'medico' => ['id_medico' => (string) $row['id_medico'], 'codigo_medico' => $row['codigo_medico'],
                'nombres' => $row['medico_nombres'], 'apellidos' => $row['medico_apellidos']],
            'especialidad' => ['id_especialidad' => (string) $row['id_especialidad'], 'nombre' => $row['especialidad_nombre']],
            'paciente' => ['id_paciente' => (string) $row['id_paciente'], 'codigo_paciente' => $row['codigo_paciente'],
                'nombres' => $row['paciente_nombres'], 'apellidos' => $row['paciente_apellidos']]];
    }
}
