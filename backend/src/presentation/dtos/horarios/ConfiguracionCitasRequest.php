<?php

namespace Clinica\presentation\dtos\horarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfiguracionCitasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $metodo = $this->route()->getActionMethod();
        if ($metodo === 'show') {
            return [];
        }
        $reglas = ['duracion_turno_minutos' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
        if ($metodo === 'impacto') {
            $reglas = [...$reglas, 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
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
