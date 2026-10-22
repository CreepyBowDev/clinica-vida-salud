<?php

namespace Clinica\presentation\dtos\consultorios;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class ImpactoAsignacionConsultorioRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'id_consultorio' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
