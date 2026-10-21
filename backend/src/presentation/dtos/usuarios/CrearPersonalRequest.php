<?php

namespace Clinica\presentation\dtos\usuarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearPersonalRequest extends FormRequest
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
            'rol' => ['required', 'string', Rule::in(['Administrador', 'Recepcionista', 'Médico'])],
            'correo_acceso' => ['required', 'string', 'email', 'max:254'],
            'nombres_personal' => ['required_unless:rol,Médico', 'prohibited_if:rol,Médico', 'string', 'max:120'],
            'apellidos_personal' => ['required_unless:rol,Médico', 'prohibited_if:rol,Médico', 'string', 'max:120'],
            'id_medico' => ['required_if:rol,Médico', 'prohibited_unless:rol,Médico', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.', 'required_if' => 'Este campo es obligatorio para el rol seleccionado.',
            'required_unless' => 'Este campo es obligatorio para el rol seleccionado.',
            'prohibited_if' => 'Este campo no corresponde al rol seleccionado.', 'prohibited_unless' => 'Este campo no corresponde al rol seleccionado.',
            'string' => 'Este campo debe ser texto.', 'email' => 'Introduce un correo electrónico válido.',
            'in' => 'El rol seleccionado no está permitido.', 'integer' => 'Introduce un identificador válido.',
            'min' => 'Introduce un identificador válido.', 'max' => 'Este campo supera el límite permitido.',
        ];
    }
}
