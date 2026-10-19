<?php

namespace Clinica\presentation\dtos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioAutenticadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->cambio_clave_obligatorio) {
            // Solo estado para mostrar/restaurar el formulario obligatorio; sin perfil o permisos ordinarios.
            return ['acceso' => 'cambio_clave', 'cambio_clave_obligatorio' => true];
        }
        $persona = match ($this->rol->nombre) {
            'Médico' => $this->medico,
            'Paciente' => $this->paciente,
            default => null,
        };

        return [
            'id_usuario' => (string) $this->getKey(),
            'correo_acceso' => $this->correo_acceso,
            'nombre_visible' => trim($persona ? $persona->nombres.' '.$persona->apellidos : $this->nombres_personal.' '.$this->apellidos_personal),
            'rol' => $this->rol->nombre,
        ];
    }
}
