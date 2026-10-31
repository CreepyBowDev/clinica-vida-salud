<?php

namespace Clinica\presentation\dtos\laboratorio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CargarDocumentoLaboratorioRequest extends FormRequest
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
        $correccion = $this->routeIs('laboratorio.corregir');
        $reasociar = $correccion && $this->input('tipo') === 'reasociar';
        $confirmacion = ['required', function (string $campo, mixed $valor, \Closure $fallar): void {
            if ($valor !== '1' && $valor !== 1) {
                $fallar('Comprueba explícitamente el paciente y el estudio.');
            }
        }];
        $reglas = ['id_objetivo' => ['required', ...$id], 'asociacion_verificada' => $confirmacion,
            'motivo_cambio' => [$correccion ? 'required' : 'sometimes', 'nullable', 'string', 'max:2000']];
        if (! $reasociar) {
            $reglas['archivo'] = ['required', 'file', 'max:10240'];
        }
        if ($correccion) {
            $reglas['tipo'] = ['required', Rule::in(['sustituir', 'reasociar'])];
        }
        if ($reasociar) {
            $reglas['id_solicitud_destino'] = ['required', ...$id];
            $reglas['asociacion_destino_verificada'] = $confirmacion;
        }
        foreach ($reasociar ? ['originales', 'originales_destino'] : ['originales'] as $prefijo) {
            $reglas += [
                $prefijo => ['required', 'array:estado_interno,id_version_actual,id_ultima_revision,ultima_version'],
                $prefijo.'.estado_interno' => ['required', Rule::in(['Pendiente', 'En revisión', 'Disponible'])],
                $prefijo.'.id_version_actual' => ['present', 'nullable', ...$id],
                $prefijo.'.id_ultima_revision' => ['present', 'nullable', ...$id],
                $prefijo.'.ultima_version' => ['required', 'integer', 'min:0', 'max:2147483647', 'regex:/\A(0|[1-9][0-9]{0,9})\z/'],
            ];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Esta operación no admite parámetros de consulta.');
            }
            $permitidos = array_filter(array_keys($this->rules()), fn ($k) => $k !== 'id_objetivo' && ! str_contains($k, '.'));
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no está permitido en esta operación.');
            }
        });
    }
}
