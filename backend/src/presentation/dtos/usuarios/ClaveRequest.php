<?php

namespace Clinica\presentation\dtos\usuarios;

use Clinica\business\validators\PoliticaClave;
use Illuminate\Foundation\Http\FormRequest;

abstract class ClaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => [...PoliticaClave::reglas(), 'confirmed'],
            'password_confirmation' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'string' => 'Este campo debe ser texto.',
            'min' => 'La contraseña debe tener al menos 12 caracteres.',
            'max' => 'Este campo supera la longitud permitida.',
            'confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }
}
