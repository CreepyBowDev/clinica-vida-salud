<?php

namespace Clinica\presentation\dtos\agenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConsultaCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return ['id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        return ['id_objetivo' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_keys($this->all()) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en esta operación.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
