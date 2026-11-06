<?php

namespace Clinica\presentation\dtos\pagos;

use Clinica\shared\errors\AppError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IntentoPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new AppError('PAGO_CONTENIDO_NO_ADMITIDO', 'Esta operación requiere contenido JSON.');
        }
        try {
            $obj = json_decode($this->getContent(), false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppError('PETICION_INVALIDA', 'La petición no es válida.');
        }
        if (! is_object($obj)) {
            throw new AppError('PETICION_INVALIDA', 'La petición no es válida.');
        }
    }

    public function validationData(): array
    {
        return [...$this->all(), 'objetivo' => $this->route('obligacion') ?? $this->route('intento'),
            'clave' => $this->header('Idempotency-Key')];
    }

    public function rules(): array
    {
        return ['objetivo' => ['required', 'uuid'], 'clave' => ['required', 'uuid:4'],
            ...($this->route('obligacion') !== null ? ['proveedor' => ['required', 'in:stripe,paypal'],
                'revision_pago' => ['required', 'string', 'max:16384']] : [])];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v): void {
            if ($this->query->all() !== []) {
                $v->errors()->add('query', 'No se admiten parámetros de consulta.');
            }
            $permitidos = $this->route('obligacion') !== null ? ['proveedor', 'revision_pago'] : [];
            foreach (array_diff(array_keys($this->all()), $permitidos) as $k) {
                $v->errors()->add($k, 'Campo no permitido.');
            }
        });
    }
}
