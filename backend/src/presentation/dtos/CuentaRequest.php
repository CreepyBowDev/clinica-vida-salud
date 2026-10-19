<?php

namespace Clinica\presentation\dtos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->all(), 'id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        return ['id_objetivo' => ['required', 'integer', 'min:1', 'max:9223372036854775807']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidos = array_diff(array_keys($this->rules()), ['id_objetivo']);
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }

    public function messages(): array
    {
        return ['required' => 'Este campo es obligatorio.', 'integer' => 'Introduce un identificador válido.',
            'min' => 'Introduce un identificador válido.', 'max' => 'Este campo supera el límite permitido.',
            'in' => 'El valor seleccionado no está permitido.', 'prohibited_unless' => 'Este campo no corresponde al rol seleccionado.',
            'required_if' => 'Este campo es obligatorio para el rol seleccionado.', 'boolean' => 'Este campo debe ser verdadero o falso.'];
    }
}
