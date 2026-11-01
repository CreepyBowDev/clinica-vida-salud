<?php

namespace Clinica\presentation\dtos\reportes;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ReportePeriodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en esta operación.');
            }
            if ($this->request->all() !== [] || ($this->isJson() && $this->json()->all() !== [])) {
                $validator->errors()->add('body', 'Esta consulta no admite un cuerpo de datos.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $desde = $this->input('fecha') ?? $this->input('desde');
            $hasta = $this->input('fecha') ?? $this->input('hasta');
            if ($desde > $hasta || $hasta === '9999-12-31') {
                $validator->errors()->add('fecha', 'El período no es válido o su extremo final no es representable.');

                return;
            }
            $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $desde, 'America/La_Paz');
            $fin = CarbonImmutable::createFromFormat('!Y-m-d', $hasta, 'America/La_Paz');
            if ($inicio->diffInDays($fin) > 365) {
                $validator->errors()->add('hasta', 'El período no puede superar 366 días inclusivos.');
            }
        });
    }
}
