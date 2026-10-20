<?php

namespace Clinica\presentation\dtos;

use Illuminate\Validation\Validator;

class RegistrarPacienteRequest extends ClaveRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['documento_identidad', 'correo_acceso'] as $campo) {
            if (is_string($this->input($campo))) {
                $valor = trim($this->input($campo), ' ');
                $this->merge([$campo => $campo === 'correo_acceso' ? mb_strtolower($valor, 'UTF-8') : $valor]);
            }
        }
    }

    public function rules(): array
    {
        return [...parent::rules(),
            'documento_identidad' => ['required', 'string', 'max:50', 'regex:/^[^\p{C}]+$/u'],
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['required', 'string', 'max:120'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'telefono' => ['required', 'string', 'max:40'],
            'sexo' => ['nullable', 'string', 'max:32'],
            'correo_acceso' => ['required', 'string', 'email', 'max:254'],
            'correo_contacto' => ['nullable', 'string', 'email', 'max:254'],
            'direccion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en el registro.');
            }
        });
    }

    public function messages(): array
    {
        return [...parent::messages(), 'max' => 'Este campo supera la longitud permitida.',
            'documento_identidad.required' => 'El CI es obligatorio para el registro web. Acude a Recepción si necesitas asistencia.',
            'regex' => 'Este campo contiene caracteres no permitidos.', 'email' => 'Introduce un correo electrónico válido.',
            'date_format' => 'Introduce una fecha válida con formato AAAA-MM-DD.',
            'before_or_equal' => 'La fecha de nacimiento no puede estar en el futuro.'];
    }
}
