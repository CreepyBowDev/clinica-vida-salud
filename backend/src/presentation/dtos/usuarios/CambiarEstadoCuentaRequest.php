<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Validation\Rule;

class CambiarEstadoCuentaRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'estado_cuenta' => ['required', Rule::in(['Activa', 'Desactivada'])]];
    }
}
