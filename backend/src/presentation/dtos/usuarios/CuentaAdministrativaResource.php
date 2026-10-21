<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaAdministrativaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $datos = ['id_usuario' => (string) $this->getKey(), 'correo_acceso' => $this->correo_acceso,
            'rol' => $this->rol->nombre, 'estado_cuenta' => $this->estado_cuenta,
            'correo_verificado_en' => $this->correo_verificado_en?->toIso8601String()];
        if ($this->rol->nombre !== 'Paciente') {
            $datos += ['nombres_personal' => $this->nombres_personal, 'apellidos_personal' => $this->apellidos_personal,
                'nombre_visible' => $this->rol->nombre === 'Médico'
                    ? trim($this->medico?->nombres.' '.$this->medico?->apellidos)
                    : trim($this->nombres_personal.' '.$this->apellidos_personal),
                'id_medico' => $this->medico ? (string) $this->medico->getKey() : null];
        }

        return $datos;
    }
}
