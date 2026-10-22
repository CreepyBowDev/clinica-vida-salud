<?php

namespace Clinica\presentation\dtos\consultorios;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsignacionConsultorioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id_medico' => $this->resource['id_medico'], 'consultorio' => $this->resource['consultorio'],
            'actualizado_en' => $this->resource['actualizado_en'],
            'citas_trasladadas' => $this->when(array_key_exists('citas_trasladadas', $this->resource), fn () => $this->resource['citas_trasladadas'])];
    }
}
