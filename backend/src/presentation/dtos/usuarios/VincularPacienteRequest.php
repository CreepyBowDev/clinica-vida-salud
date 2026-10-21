<?php

namespace Clinica\presentation\dtos\usuarios;

class VincularPacienteRequest extends RegistrarPacienteRequest
{
    public function rules(): array
    {
        return [...array_intersect_key(parent::rules(), array_flip([
            'documento_identidad', 'correo_acceso', 'password', 'password_confirmation',
        ])), 'codigo_activacion' => ['required', 'string', 'regex:/^[A-Za-z0-9]{12}$/D']];
    }
}
