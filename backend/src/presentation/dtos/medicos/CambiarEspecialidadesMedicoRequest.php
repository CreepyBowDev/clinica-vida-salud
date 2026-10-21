<?php

namespace Clinica\presentation\dtos\medicos;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class CambiarEspecialidadesMedicoRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'especialidades' => ['present', 'array', 'list'],
            'especialidades.*' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'distinct'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
