<?php

namespace Clinica\presentation\dtos\consultorios;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultorioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id_consultorio' => (string) $this->getKey(), 'codigo_consultorio' => $this->codigo_consultorio,
            'nombre' => $this->nombre, 'activo' => $this->activo,
            'actualizado_en' => $this->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'medicos_asignados' => $this->whenCounted('medicos'),
            'citas_vigentes' => $this->whenHas('obligaciones_count')];
    }
}
