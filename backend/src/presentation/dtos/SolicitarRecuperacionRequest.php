<?php

namespace Clinica\presentation\dtos;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarRecuperacionRequest extends FormRequest
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
        return ['correo_acceso' => ['required', 'string', 'email', 'max:254']];
    }

    public function messages(): array
    {
        return ['required' => 'Introduce tu correo electrónico.', 'string' => 'Introduce un correo electrónico válido.',
            'email' => 'Introduce un correo electrónico válido.', 'max' => 'El correo supera la longitud permitida.'];
    }
}
