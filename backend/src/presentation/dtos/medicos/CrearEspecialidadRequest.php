<?php

namespace Clinica\presentation\dtos\medicos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearEspecialidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => trim($this->input('nombre'), ' ')]);
        }
    }

    public function rules(): array
    {
        return ['nombre' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'descripcion' => ['nullable', 'string', 'max:2000', 'regex:/^[^\p{C}]+$/u']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en el registro de especialidades.');
            }
        });
    }
}
