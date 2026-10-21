<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CambioCorreoRequest extends FormRequest
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
        return match ($this->route()->getActionMethod()) {
            'solicitar' => ['current_password' => ['required', 'string', 'max:1024'],
                'correo_acceso' => ['required', 'string', 'email', 'max:254']],
            'verificar' => ['codigo' => ['required', 'string', 'regex:/^[0-9]{6}$/D']],
            default => [],
        };
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
