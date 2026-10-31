<?php

namespace Clinica\presentation\dtos\historia_clinica;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LeerHistoriaClinicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->query->all(), 'id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        return [
            'id_objetivo' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), ['page', 'per_page']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
