<?php

namespace Clinica\presentation\dtos\laboratorio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RevisarResultadoLaboratorioRequest extends FormRequest
{
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
        $id = ['integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];

        return [
            'id_objetivo' => ['required', ...$id],
            'originales' => ['required', 'array:estado_interno,id_version_actual,id_ultima_revision,ultima_version'],
            'originales.estado_interno' => ['required', Rule::in(['Pendiente', 'En revisión', 'Disponible'])],
            'originales.id_version_actual' => ['present', 'nullable', ...$id],
            'originales.id_ultima_revision' => ['present', 'nullable', ...$id],
            'originales.ultima_version' => ['required', 'integer', 'min:0', 'max:2147483647', 'regex:/\A(0|[1-9][0-9]{0,9})\z/'],
            'decision' => ['required', Rule::in(['Autorizar', 'Devolver'])],
            'correspondencia_verificada' => ['required', function (string $campo, mixed $valor, \Closure $fallar): void {
                if ($valor !== true) {
                    $fallar('Comprueba la legibilidad y correspondencia del documento con paciente y estudio.');
                }
            }],
            'motivo' => $this->input('decision') === 'Devolver'
                ? ['required', 'string', 'max:2000', 'regex:/\S/u'] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('body', 'Esta operación requiere JSON.');
            }
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            $permitidos = ['originales', 'decision', 'correspondencia_verificada'];
            if ($this->input('decision') === 'Devolver') {
                $permitidos[] = 'motivo';
            }
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
