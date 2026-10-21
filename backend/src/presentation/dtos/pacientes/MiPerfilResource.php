<?php

namespace Clinica\presentation\dtos\pacientes;

use Illuminate\Http\Request;

class MiPerfilResource extends PacienteAdministrativoResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'correo_acceso' => $this->usuario->correo_acceso];
    }
}
