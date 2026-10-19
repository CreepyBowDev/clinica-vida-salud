<?php

namespace Clinica\presentation\dtos;

use Illuminate\Validation\Rule;

class CambiarRolCuentaRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'rol' => ['required', Rule::in(['Administrador', 'Recepcionista', 'Médico'])],
            'id_medico' => ['required_if:rol,Médico', 'prohibited_unless:rol,Médico', 'integer', 'min:1', 'max:9223372036854775807'],
            'identidad_verificada' => ['sometimes', 'prohibited_unless:rol,Médico', 'boolean'],
        ];
    }
}
