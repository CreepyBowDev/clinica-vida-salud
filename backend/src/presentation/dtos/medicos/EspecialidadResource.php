<?php

namespace Clinica\presentation\dtos\medicos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EspecialidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id_especialidad' => (string) $this->getKey(), 'nombre' => $this->nombre,
            'descripcion' => $this->descripcion, 'activa' => $this->activa,
            'actualizado_en' => $this->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
