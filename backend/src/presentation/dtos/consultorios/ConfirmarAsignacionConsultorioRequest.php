<?php

namespace Clinica\presentation\dtos\consultorios;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class ConfirmarAsignacionConsultorioRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'id_consultorio' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'huella_impacto' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmar_traslados' => ['required', 'boolean']];
    }
}
