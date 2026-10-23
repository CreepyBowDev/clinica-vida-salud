<?php

namespace Clinica\presentation\dtos\agenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class HistorialCitasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->route()->getActionMethod() === 'paciente'
            ? [...$this->all(), 'id_objetivo' => $this->route('id')] : $this->all();
    }

    public function rules(): array
    {
        $rules = ['periodo' => ['sometimes', 'required', Rule::in(['proximas', 'anteriores', 'todas'])],
            'estado' => ['sometimes', 'required', Rule::in(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if ($this->route()->getActionMethod() === 'paciente') {
            $rules['id_objetivo'] = ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_diff(array_keys($this->rules()), ['id_objetivo']);
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en esta operación.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
