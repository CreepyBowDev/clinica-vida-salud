<?php

namespace Clinica\presentation\dtos\citas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReservaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo_breve'))) {
            $texto = trim($this->input('motivo_breve'));
            $this->merge(['motivo_breve' => $texto === '' ? null : $texto]);
        }
    }

    public function rules(): array
    {
        $metodo = $this->route()->getActionMethod();
        $motivo = ['motivo_breve' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/\p{C}/u']];
        if ($metodo !== 'propuesta') {
            return ['propuesta' => ['required', 'string', 'max:16384'], ...($metodo === 'store' ? $motivo : [])];
        }
        $id = ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];

        return ['id_medico' => $id, 'id_especialidad' => $id,
            'fecha' => ['required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'hora_inicio' => ['required', 'date_format:H:i:s'], 'revision_turno' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            ...($this->user()?->rol?->nombre === 'Recepcionista' ? ['id_paciente' => $id] : []), ...$motivo];
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
