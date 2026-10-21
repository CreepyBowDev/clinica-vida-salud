<?php

namespace Clinica\presentation\dtos\medicos;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class CambiarEstadoMedicoRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'activo' => ['required', 'boolean'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
