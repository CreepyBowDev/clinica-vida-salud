<?php

namespace Clinica\presentation\dtos\pagos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConsultaPagosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->route()->getActionMethod() === 'detalle'
            ? [...$this->all(), 'obligacion_objetivo' => $this->route('obligacion')] : $this->all();
    }

    public function rules(): array
    {
        $reglas = $this->user()?->rol?->nombre === 'Administrador'
            ? ['id_paciente' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/']] : [];
        $pagina = ['sometimes', 'integer', 'min:1', 'max:1000000'];
        if ($this->route()->getActionMethod() === 'detalle') {
            return [...$reglas, 'obligacion_objetivo' => ['required', 'uuid'], 'intentos_page' => $pagina, 'devoluciones_page' => $pagina];
        }
        $reglas += ['page' => $pagina, 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        foreach (['fecha_desde', 'fecha_hasta'] as $campo) {
            $reglas[$campo] = ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'];
        }
        if ($this->filled('fecha_desde')) {
            $reglas['fecha_hasta'][] = 'after_or_equal:fecha_desde';
        }
        if ($this->route()->getActionMethod() === 'historial') {
            return [...$reglas, 'proveedor' => ['sometimes', 'required', Rule::in(['stripe', 'paypal'])],
                'resultado' => ['sometimes', 'required', Rule::in(['Preparando', 'RequiereAccion', 'Procesando', 'Exitoso', 'Fallido', 'Cerrado', 'Incierto'])]];
        }
        foreach (['exigible', 'liquidada', 'vencida', 'operacion_en_verificacion'] as $campo) {
            $reglas[$campo] = ['sometimes', 'required', Rule::in(['0', '1'])];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_diff(array_keys($this->rules()), ['obligacion_objetivo'])) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta consulta.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
        });
    }
}
