<?php

namespace Clinica\presentation\dtos\medicos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearMedicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo_contacto'))) {
            $this->merge(['correo_contacto' => mb_strtolower(trim($this->input('correo_contacto'), ' '), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        return ['registro_profesional' => ['required', 'string', 'max:80', 'regex:/^[^\p{C}]+$/u'],
            'nombres' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'apellidos' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'telefono' => ['required', 'string', 'max:40', 'regex:/^[^\p{C}]+$/u'],
            'correo_contacto' => ['required', 'string', 'email', 'max:254', 'regex:/^[^\p{C}]+$/u'],
            'especialidades' => ['required', 'array', 'list', 'min:1'],
            'especialidades.*' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'distinct']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidos = array_filter(array_keys($this->rules()), fn ($campo) => ! str_contains($campo, '.'));
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en el registro profesional.');
            }
        });
    }
}
