<?php

namespace Clinica\presentation\dtos\recordatorios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListarRecordatoriosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $r = ['page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'fecha_desde' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'fecha_hasta' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',
                ...($this->query->has('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])]];
        if ($this->user()?->rol?->nombre === 'Administrador') {
            $r['id_paciente'] = ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];
        }

        return $r;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no está permitido.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
