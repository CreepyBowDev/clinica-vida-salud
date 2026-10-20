<?php

namespace Clinica\presentation\dtos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearPacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('documento_identidad'))) {
            $ci = trim($this->input('documento_identidad'), ' ');
            $this->merge(['documento_identidad' => $ci === '' ? null : $ci]);
        }
        if (is_string($this->input('correo_contacto'))) {
            $this->merge(['correo_contacto' => mb_strtolower(trim($this->input('correo_contacto'), ' '), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        return ['documento_identidad' => ['nullable', 'string', 'max:50', 'regex:/^[^\p{C}]+$/u'],
            'nombres' => ['required', 'string', 'max:120'], 'apellidos' => ['required', 'string', 'max:120'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sexo' => ['nullable', 'string', 'max:32'], 'telefono' => ['required', 'string', 'max:40'],
            'correo_contacto' => ['nullable', 'string', 'email', 'max:254'], 'direccion' => ['nullable', 'string', 'max:2000'],
            'posibles_duplicados_revisados' => ['sometimes', 'boolean', 'accepted'],
            'contacto_emergencia' => ['nullable', 'array:nombre,parentesco,telefono', 'min:3'],
            'contacto_emergencia.nombre' => ['required_with:contacto_emergencia', 'string', 'max:240'],
            'contacto_emergencia.parentesco' => ['required_with:contacto_emergencia', 'string', 'max:80'],
            'contacto_emergencia.telefono' => ['required_with:contacto_emergencia', 'string', 'max:40']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidos = array_filter(array_keys($this->rules()), fn ($campo) => ! str_contains($campo, '.'));
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en el registro administrativo.');
            }
            if ($this->input('documento_identidad') === null && ! $this->boolean('posibles_duplicados_revisados')) {
                $validator->errors()->add('posibles_duplicados_revisados', 'Revisa las posibles fichas previas antes de registrar sin CI.');
            }
        });
    }
}
