<?php

namespace Clinica\presentation\dtos\pacientes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PacienteListadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id_paciente' => (string) $this->getKey(), 'codigo_paciente' => $this->codigo_paciente,
            'documento_identidad' => $this->documento_identidad, 'nombres' => $this->nombres,
            'apellidos' => $this->apellidos, 'activo' => $this->activo];
    }
}
