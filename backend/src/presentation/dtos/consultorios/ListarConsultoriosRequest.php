<?php

namespace Clinica\presentation\dtos\consultorios;

class ListarConsultoriosRequest extends CrearConsultorioRequest
{
    public function rules(): array
    {
        return ['q' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'activo' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
