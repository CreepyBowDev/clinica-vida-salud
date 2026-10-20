<?php

namespace Clinica\presentation\dtos;

use Illuminate\Http\Request;

class PacienteAdministrativoResource extends PacienteListadoResource
{
    public function toArray(Request $request): array
    {
        $contacto = $this->contactoEmergencia;

        return [...parent::toArray($request), 'fecha_nacimiento' => $this->fecha_nacimiento->format('Y-m-d'),
            'sexo' => $this->sexo, 'telefono' => $this->telefono, 'correo_contacto' => $this->correo_contacto,
            'direccion' => $this->direccion, 'cuenta_vinculada' => $this->id_usuario !== null,
            'contacto_emergencia' => $contacto ? ['nombre' => $contacto->nombre,
                'parentesco' => $contacto->parentesco, 'telefono' => $contacto->telefono] : null,
            'recordatorios_habilitados' => $this->preferencia?->recordatorios_habilitados,
            'actualizado_en' => $this->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
