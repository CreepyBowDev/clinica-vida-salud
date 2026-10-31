<?php

namespace Clinica\presentation\dtos\laboratorio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LeerLaboratorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->query->all(), ...($this->route('id') === null ? [] : ['id_objetivo' => $this->route('id')])];
    }

    public function rules(): array
    {
        $id = ['integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];
        $reglas = $this->route('id') === null ? [] : ['id_objetivo' => ['required', ...$id]];
        if ($this->routeIs('laboratorio.detalle')) {
            return $reglas;
        }
        if ($this->routeIs('laboratorio.archivo')) {
            return $reglas + ['id_version_resultado' => ['required', ...$id]];
        }
        if ($this->routeIs('laboratorio.versiones')) {
            return $reglas + ['page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
                'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100']];
        }
        $reglas += [
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'estado' => ['sometimes', 'required', Rule::in(['Pendiente', 'En revisión', 'Disponible'])],
            'fecha_desde' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'fecha_hasta' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', ...($this->query->has('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])],
        ];
        if ($this->routeIs('laboratorio.lista')) {
            $reglas += ['id_paciente' => ['sometimes', 'required', ...$id], 'id_consulta' => ['sometimes', 'required', ...$id]];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), array_diff(array_keys($this->rules()), ['id_objetivo'])) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no está permitido.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
