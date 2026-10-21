<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Foundation\Http\FormRequest;

class VerificarRegistroPacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['codigo' => ['required', 'string', 'regex:/^[0-9]{6}$/D']];
    }

    public function messages(): array
    {
        return ['required' => 'Introduce el código de verificación.', 'string' => 'Introduce el código como texto de seis dígitos.',
            'regex' => 'Introduce los seis dígitos del código.'];
    }
}
