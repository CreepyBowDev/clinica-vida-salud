<?php

namespace Clinica\presentation\dtos;

class EmitirCredencialTemporalRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'identidad_verificada' => ['required', 'boolean', 'accepted']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'accepted' => 'Debes comprobar presencialmente la identidad del trabajador.'];
    }
}
