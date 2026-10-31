<?php

namespace Clinica\presentation\dtos\citas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AsistenciaRequest extends FormRequest
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
        $reglas = ['id_objetivo' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/']];
        if ($this->route()->getActionMethod() === 'consultar') {
            return $reglas;
        }
        [$declaracion, $mensaje] = match ($this->route()->getActionMethod()) {
            'registrarInasistencia' => ['ausencia_verificada', 'Debes declarar la ausencia comprobada del Paciente.'],
            'marcarAtendida' => ['atencion_realizada', 'Debes declarar que la atención fue efectivamente realizada.'],
            default => ['presencia_verificada', 'Debes declarar la presencia e identidad verificadas presencialmente.'],
        };

        return [...$reglas,
            'version_programacion_original' => ['required', 'integer', 'min:1', 'max:2147483647', 'regex:/\A[1-9][0-9]{0,9}\z/'],
            'actualizada_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z',
                'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/'],
            $declaracion => ['required', function (string $campo, mixed $valor, \Closure $fallar) use ($mensaje): void {
                if ($valor !== true) {
                    $fallar($mensaje);
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
            $permitidos = array_diff(array_keys($this->rules()), ['id_objetivo']);
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
