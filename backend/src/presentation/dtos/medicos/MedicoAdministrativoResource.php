<?php

namespace Clinica\presentation\dtos\medicos;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MedicoAdministrativoResource extends MedicoListadoResource
{
    protected function especialidadesVisibles(): Collection
    {
        return $this->especialidades;
    }

    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'registro_profesional' => $this->registro_profesional,
            'telefono' => $this->telefono, 'correo_contacto' => $this->correo_contacto,
            'cuenta_vinculada' => $this->id_usuario !== null,
            'actualizado_en' => $this->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
