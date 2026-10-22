<?php

namespace Clinica\presentation\dtos\horarios;

use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Illuminate\Validation\Validator;

class HorarioRequest extends CuentaRequest
{
    public function validationData(): array
    {
        $datos = parent::validationData();
        if ($this->route('horario') !== null) {
            $datos['id_horario'] = $this->route('horario');
        }

        return $datos;
    }

    public function rules(): array
    {
        $metodo = $this->route()->getActionMethod();
        $pagina = ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if ($metodo === 'index') {
            return [...parent::rules(), ...$pagina, 'activo' => ['sometimes', 'boolean']];
        }
        $impacto = $metodo === 'impacto';
        $accion = $impacto ? $this->input('accion') : match ($metodo) {
            'store' => 'CREAR', 'update' => 'EDITAR', 'estado' => 'ESTADO',
        };
        $reglas = [...parent::rules(), 'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
        if ($impacto) {
            $reglas = [...$reglas, ...$pagina, 'accion' => ['required', 'string', 'in:CREAR,EDITAR,ESTADO']];
        }
        if ($accion === 'EDITAR' || $accion === 'ESTADO') {
            $reglas['id_horario'] = ['required', 'integer', 'min:1', 'max:9223372036854775807'];
        }
        if ($accion === 'ESTADO') {
            $reglas['activo'] = ['required', 'boolean'];
        } elseif ($accion === 'CREAR' || $accion === 'EDITAR') {
            $presencia = $accion === 'CREAR' ? 'required' : 'sometimes';
            $reglas = [...$reglas, 'dia_semana' => [$presencia, 'required', 'integer', 'between:1,7'],
                'hora_inicio' => [$presencia, 'required', 'date_format:H:i:s'], 'hora_fin' => [$presencia, 'required', 'date_format:H:i:s']];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            if ($this->route('horario') !== null && $this->exists('id_horario')) {
                $validator->errors()->add('id_horario', 'El horario se indica exclusivamente en la ruta.');
            }
            $editar = $this->route()->getActionMethod() === 'update'
                || ($this->route()->getActionMethod() === 'impacto' && $this->input('accion') === 'EDITAR');
            if ($editar && ! $this->hasAny(['dia_semana', 'hora_inicio', 'hora_fin'])) {
                $validator->errors()->add('horario', 'Indica al menos un campo del horario.');
            }
        });
    }
}
