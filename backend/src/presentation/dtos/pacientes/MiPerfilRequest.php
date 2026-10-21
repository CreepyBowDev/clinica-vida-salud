<?php

namespace Clinica\presentation\dtos\pacientes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MiPerfilRequest extends FormRequest
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
        if ($this->isMethod('GET')) {
            return [];
        }
        $permitidos = ['telefono', 'direccion', 'correo_contacto', 'contacto_emergencia',
            'contacto_emergencia.nombre', 'contacto_emergencia.parentesco', 'contacto_emergencia.telefono'];
        $campos = array_intersect_key((new CrearPacienteRequest)->rules(), array_flip($permitidos));
        foreach (['telefono', 'direccion', 'correo_contacto', 'contacto_emergencia'] as $campo) {
            $campos[$campo] = ['sometimes', ...$campos[$campo]];
        }

        return [...$campos, 'recordatorios_habilitados' => ['sometimes', 'boolean'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidos = array_filter(array_keys($this->rules()), fn ($campo) => ! str_contains($campo, '.'));
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en el perfil personal.');
            }
        });
    }
}
