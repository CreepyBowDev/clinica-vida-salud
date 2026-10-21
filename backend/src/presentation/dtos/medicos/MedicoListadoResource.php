<?php

namespace Clinica\presentation\dtos\medicos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class MedicoListadoResource extends JsonResource
{
    protected function especialidadesVisibles(): Collection
    {
        return $this->especialidades->filter(fn ($e) => $e->pivot->habilitada);
    }

    public function toArray(Request $request): array
    {
        return ['id_medico' => (string) $this->getKey(), 'codigo_medico' => $this->codigo_medico,
            'nombres' => $this->nombres, 'apellidos' => $this->apellidos, 'activo' => $this->activo,
            'especialidades' => $this->especialidadesVisibles()->sortBy('id_especialidad')->values()->map(fn ($e) => [
                'id_especialidad' => (string) $e->getKey(), 'nombre' => $e->nombre, 'activa' => $e->activa,
                'habilitada' => $e->pivot->habilitada])->all()];
    }
}
