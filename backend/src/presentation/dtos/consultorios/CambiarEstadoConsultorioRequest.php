<?php

namespace Clinica\presentation\dtos\consultorios;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class CambiarEstadoConsultorioRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'activo' => ['required', 'boolean'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
