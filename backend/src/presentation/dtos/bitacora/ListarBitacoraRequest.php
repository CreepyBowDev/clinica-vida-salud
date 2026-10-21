<?php

namespace Clinica\presentation\dtos\bitacora;

use Clinica\shared\utils\ReferenciasBitacora;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListarBitacoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['modulo', 'operacion', 'tipo_evento', 'entidad_referida', 'registro_referido'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo), ' ')]);
            }
        }
    }

    public function rules(): array
    {
        $id = ['sometimes', 'required', 'integer', 'min:1', 'max:9223372036854775807'];

        return [
            'fecha_desde' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/^(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'],
            'fecha_hasta' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/^(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', ...($this->has('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])],
            'id_usuario_responsable' => $id,
            'id_usuario_objetivo' => $id,
            'tipo_actor' => ['sometimes', 'required', Rule::in(['USUARIO', 'SISTEMA', 'ANONIMO'])],
            'modulo' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^[^\p{C}]+$/u'],
            'operacion' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[^\p{C}]+$/u'],
            'tipo_evento' => ['sometimes', 'required', 'string', 'max:80', 'regex:/^[^\p{C}]+$/u'],
            'resultado' => ['sometimes', 'required', Rule::in(['EXITO', 'FALLO', 'DENEGADO', 'ERROR'])],
            'direccion_ip' => ['sometimes', 'required', 'ip'],
            'entidad_referida' => ['sometimes', 'required', Rule::in(ReferenciasBitacora::ENTIDADES)],
            'registro_referido' => ['sometimes', 'required', 'string', 'max:64'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no está permitido en la bitácora.');
            }
            if ($this->has('registro_referido')) {
                $entidad = $this->input('entidad_referida');
                if (! is_string($entidad) || ReferenciasBitacora::registro($entidad, $this->input('registro_referido')) === null) {
                    $validator->errors()->add('registro_referido', 'Introduce una entidad y un identificador de recurso válidos.');
                }
            }
        });
    }
}
