<?php

namespace Clinica\presentation\dtos\pacientes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListarPacientesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['documento_identidad', 'nombres', 'apellidos'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo), ' ')]);
            }
        }
    }

    public function rules(): array
    {
        return ['documento_identidad' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[^\p{C}]+$/u'],
            'nombres' => ['sometimes', 'required', 'string', 'max:120'],
            'apellidos' => ['sometimes', 'required', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no está permitido.');
            }
        });
    }
}
