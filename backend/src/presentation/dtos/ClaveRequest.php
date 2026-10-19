<?php

namespace Clinica\presentation\dtos;

use Closure;
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
            'password' => ['bail', 'required', 'string', 'min:12', 'max:1024', 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (config('hashing.driver') === 'bcrypt' && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('La contraseña admite hasta 72 bytes y no admite caracteres nulos con el algoritmo actual.');
                }
            }],
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
