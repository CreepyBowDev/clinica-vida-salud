<?php

namespace Clinica\presentation\dtos\historia_clinica;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarConsultaRequest extends FormRequest
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
        $reglas = [
            'id_objetivo' => ['required', ...$id],
            'originales' => ['required', 'array:version_programacion,actualizada_en,id_consulta,id_version_consulta,id_version_antecedente'],
            'originales.version_programacion' => ['required', 'integer', 'min:1', 'max:2147483647', 'regex:/\A[1-9][0-9]{0,9}\z/'],
            'originales.actualizada_en' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z',
                'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/'],
            'atencion_real_verificada' => ['required', function (string $campo, mixed $valor, \Closure $fallar): void {
                if ($valor !== true) {
                    $fallar('Debes declarar que la atención real fue iniciada o realizada.');
                }
            }],
            'consulta' => ['required', 'array:tipo_consulta,motivo_consulta,sintomas,observaciones,diagnostico,indicaciones_tratamiento'],
            'consulta.tipo_consulta' => ['required', Rule::in(['Control', 'Enfermedad'])],
            'consulta.motivo_consulta' => ['required', 'string', 'max:4000'],
            'antecedentes' => ['sometimes', 'array:tipo_sangre,alergias,enfermedades_cronicas,medicamentos,enfermedades_familiares,antecedentes_relevantes'],
        ];
        foreach (['id_consulta', 'id_version_consulta', 'id_version_antecedente'] as $campo) {
            $reglas['originales.'.$campo] = ['present', 'nullable', ...$id];
        }
        foreach (['sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento'] as $campo) {
            $reglas['consulta.'.$campo] = ['sometimes', 'nullable', 'string', 'max:10000'];
        }
        foreach (['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes'] as $campo) {
            $reglas['antecedentes.'.$campo] = ['sometimes', 'nullable', 'string', 'max:'.($campo === 'tipo_sangre' ? 16 : 10000)];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            $permitidos = array_filter(array_keys($this->rules()), fn (string $campo) => $campo !== 'id_objetivo' && ! str_contains($campo, '.'));
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
