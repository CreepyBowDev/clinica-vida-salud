<?php

namespace Clinica\presentation\dtos\pagos;

use Illuminate\Validation\Validator;

class SimulacionPagoRequest extends IntentoPagoRequest
{
    public function rules(): array
    {
        return ['objetivo' => ['required', 'uuid'], 'clave' => ['required', 'uuid:4'],
            ...($this->route('obligacion') !== null ? ['modalidad' => ['required', 'in:stripe,paypal'],
                'escenario' => ['required', 'in:completado,pendiente'], 'revision' => ['required', 'string', 'max:16384']] : [])];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v): void {
            if ($this->query->all() !== []) {
                $v->errors()->add('query', 'No se admiten parámetros de consulta.');
            }
            $permitidos = $this->route('obligacion') !== null ? ['modalidad', 'escenario', 'revision'] : [];
            foreach (array_diff(array_keys($this->all()), $permitidos) as $k) {
                $v->errors()->add($k, 'Campo no permitido.');
            }
        });
    }
}
