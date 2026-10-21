<?php

namespace Clinica\presentation\dtos\pacientes;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class EmitirCodigoActivacionRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'identidad_verificada' => ['required', 'boolean', 'accepted']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'accepted' => 'Debes comprobar presencialmente la identidad y el CI del Paciente.'];
    }
}
