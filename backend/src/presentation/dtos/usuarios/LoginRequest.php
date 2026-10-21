<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'correo_acceso' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'string' => 'Este campo debe ser texto.',
            'email' => 'Introduce un correo electrónico válido.',
            'max' => 'Este campo supera la longitud permitida.',
        ];
    }
}
