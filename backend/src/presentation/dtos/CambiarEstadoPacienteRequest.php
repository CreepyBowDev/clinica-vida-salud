<?php

namespace Clinica\presentation\dtos;

class CambiarEstadoPacienteRequest extends CuentaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'activo' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:500', 'regex:/^[^\p{C}]+$/u'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
