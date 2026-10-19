<?php

namespace Clinica\presentation\dtos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarCuentasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo_acceso'))) {
            $this->merge(['correo_acceso' => mb_strtolower(trim($this->input('correo_acceso'), ' '), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        return [
            'rol' => ['sometimes', Rule::in(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])],
            'estado_cuenta' => ['sometimes', Rule::in(['Pendiente', 'Activa', 'Desactivada'])],
            'correo_acceso' => ['sometimes', 'string', 'email', 'max:254'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
