<?php

namespace Clinica\presentation\dtos\medicos;

class ListarMedicosRequest extends CrearMedicoRequest
{
    public function rules(): array
    {
        return ['nombre' => ['sometimes', 'required', 'string', 'max:241', 'regex:/^[^\p{C}]+$/u'],
            'id_especialidad' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            'activo' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
