<?php

namespace Clinica\presentation\dtos;

class ActualizarPacienteRequest extends CuentaRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['documento_identidad', 'correo_contacto'] as $campo) {
            if (is_string($this->input($campo))) {
                $valor = trim($this->input($campo), ' ');
                $this->merge([$campo => $campo === 'correo_contacto' ? mb_strtolower($valor, 'UTF-8') : ($valor === '' ? null : $valor)]);
            }
        }
    }

    public function rules(): array
    {
        $campos = (new CrearPacienteRequest)->rules();
        unset($campos['posibles_duplicados_revisados']);
        foreach (['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion', 'contacto_emergencia'] as $campo) {
            $campos[$campo] = ['sometimes', ...$campos[$campo]];
        }

        return [...parent::rules(), ...$campos,
            'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'identidad_verificada' => ['sometimes', 'boolean', 'accepted'],
            'motivo' => ['sometimes', 'required', 'string', 'max:500', 'regex:/^[^\p{C}]+$/u']];
    }
}
