<?php

namespace Clinica\presentation\dtos\consultorios;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class ActualizarConsultorioRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'codigo_consultorio' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[^\p{C}]+$/u'],
            'nombre' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
