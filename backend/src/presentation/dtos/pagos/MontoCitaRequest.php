<?php

namespace Clinica\presentation\dtos\pagos;

use Clinica\business\validators\ImportePagoRule;
use Clinica\shared\errors\AppError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MontoCitaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new AppError('PAGO_CONTENIDO_NO_ADMITIDO', 'Esta operación requiere contenido JSON.');
        }
        try {
            $datos = json_decode($this->getContent(), false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppError('PETICION_INVALIDA', 'La petición no es válida.');
        }
        if (! is_object($datos)) {
            throw new AppError('PETICION_INVALIDA', 'La petición no es válida.');
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->all(), 'id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        return ['id_objetivo' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'],
            ...($this->route()->getActionMethod() === 'proponer'
                ? ['monto_bob' => ['required', new ImportePagoRule]]
                : ['propuesta' => ['required', 'string', 'max:16384']])];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            foreach (array_diff(array_keys($this->all()), array_diff(array_keys($this->rules()), ['id_objetivo'])) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
