<?php

namespace Clinica\presentation\dtos\recordatorios;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordatorioListadoResource extends JsonResource
{
    private function instante(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public function toArray(Request $request): array
    {
        return ['id_recordatorio' => (string) $this->id_recordatorio, 'id_cita' => (string) $this->id_cita,
            'version_programacion' => (int) $this->version_programacion, 'generado_en' => $this->instante($this->generado_en),
            'cita' => ['codigo_cita' => $this->codigo_cita, 'inicio_at' => $this->instante($this->inicio_at),
                'fin_at' => $this->instante($this->fin_at), 'estado' => $this->estado, 'modalidad' => $this->modalidad,
                'sede_atencion' => $this->sede_atencion, 'consultorio' => ['id_consultorio' => (string) $this->id_consultorio,
                    'ubicacion' => $this->consultorio_atencion],
                'medico' => ['id_medico' => (string) $this->id_medico, 'codigo_medico' => $this->codigo_medico,
                    'nombres' => $this->medico_nombres, 'apellidos' => $this->medico_apellidos],
                'especialidad' => ['id_especialidad' => (string) $this->id_especialidad, 'nombre' => $this->especialidad_nombre]]];
    }
}
