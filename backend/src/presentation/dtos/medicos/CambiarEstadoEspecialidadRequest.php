<?php

namespace Clinica\presentation\dtos\medicos;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class CambiarEstadoEspecialidadRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'activa' => ['required', 'boolean'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
