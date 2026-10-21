<?php

namespace Clinica\presentation\dtos\medicos;

class ListarEspecialidadesRequest extends CrearEspecialidadRequest
{
    public function rules(): array
    {
        return ['nombre' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'activa' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
