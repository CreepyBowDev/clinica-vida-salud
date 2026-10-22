<?php

namespace Clinica\presentation\dtos\consultorios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearConsultorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['codigo_consultorio' => ['required', 'string', 'max:50', 'regex:/^[^\p{C}]+$/u'],
            'nombre' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
