<?php

namespace Clinica\presentation\dtos\laboratorio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearSolicitudLaboratorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->request->all(), ...($this->isJson() ? $this->json()->all() : []), 'id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        $id = ['integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];

        return [
            'id_objetivo' => ['required', ...$id],
            'estudio_solicitado' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'originales' => ['required', 'array:id_version_consulta'],
            'originales.id_version_consulta' => ['required', ...$id],
            'atencion_real_verificada' => ['required', function (string $campo, mixed $valor, \Closure $fallar): void {
                if ($valor !== true) {
                    $fallar('Debes declarar que la atención real fue iniciada o realizada.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            foreach (array_diff(array_keys($this->all()), ['estudio_solicitado', 'originales', 'atencion_real_verificada']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
