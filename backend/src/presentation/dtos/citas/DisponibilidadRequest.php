<?php

namespace Clinica\presentation\dtos\citas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reglas = ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        $metodo = $this->route()->getActionMethod();
        $id = ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];
        if ($metodo !== 'turnos') {
            $reglas['nombre'] = ['sometimes', 'required', 'string', 'max:120', 'not_regex:/\p{C}/u'];
        }
        if ($metodo !== 'especialidades') {
            $reglas['id_especialidad'] = $id;
        }
        if ($metodo === 'medicos') {
            $reglas['consultorio'] = ['sometimes', 'required', 'string', 'max:120', 'not_regex:/\p{C}/u'];
        }
        if ($metodo === 'turnos') {
            $reglas['id_medico'] = $id;
            $reglas['fecha'] = ['required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'];
            if ($this->user()?->rol?->nombre === 'Recepcionista') {
                $reglas['id_paciente'] = $id;
            }
        }

        return $reglas;
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
