<?php

namespace Clinica\presentation\dtos\medicos;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class ActualizarEspecialidadRequest extends CuentaRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => trim($this->input('nombre'), ' ')]);
        }
    }

    public function rules(): array
    {
        return [...parent::rules(),
            'nombre' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:/^[^\p{C}]+$/u'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
