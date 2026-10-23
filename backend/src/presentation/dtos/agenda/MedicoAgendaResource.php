<?php

namespace Clinica\presentation\dtos\agenda;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicoAgendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id_medico' => (string) $this->resource['id_medico'], 'codigo_medico' => $this->resource['codigo_medico'],
            'nombres' => $this->resource['nombres'], 'apellidos' => $this->resource['apellidos'], 'activo' => (bool) $this->resource['activo']];
    }
}
