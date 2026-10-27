<?php

namespace Clinica\presentation\dtos\citas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GestionCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->request->all(), ...($this->isJson() ? $this->json()->all() : []), 'id_objetivo' => $this->route('id')];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route()->getActionMethod() === 'cancelar' && is_string($this->input('motivo'))) {
            $motivo = trim($this->input('motivo'));
            $this->merge(['motivo' => $motivo === '' ? null : $motivo]);
        }
    }

    public function rules(): array
    {
        $id = ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];

        $metodo = $this->route()->getActionMethod();
        if ($metodo === 'consultar') {
            return ['id_objetivo' => $id];
        }

        return ['id_objetivo' => $id,
            'version_programacion_original' => ['required', 'integer', 'min:1', 'max:2147483647', 'regex:/\A[1-9][0-9]{0,9}\z/'],
            'actualizada_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z',
                'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/'],
            ...($metodo === 'cancelar' ? ['motivo' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/\p{C}/u']] : [
                'fecha' => ['required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
                'hora_inicio' => ['required', 'date_format:H:i:s'],
                'revision_turno' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            ])];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            $permitidos = array_diff(array_keys($this->rules()), ['id_objetivo']);
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
