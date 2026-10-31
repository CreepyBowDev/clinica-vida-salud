<?php

namespace Clinica\presentation\dtos\historia_clinica;

class ActualizarConsultaRequest extends RegistrarConsultaRequest
{
    public function rules(): array
    {
        $reglas = parent::rules();
        $reglas['consulta'][0] = 'sometimes';
        $reglas['consulta.tipo_consulta'] = ['sometimes', ...$reglas['consulta.tipo_consulta']];
        $reglas['consulta.motivo_consulta'] = ['sometimes', ...$reglas['consulta.motivo_consulta']];
        foreach (['id_consulta', 'id_version_consulta'] as $campo) {
            $reglas['originales.'.$campo] = array_values(array_diff($reglas['originales.'.$campo], ['present', 'nullable']));
            array_unshift($reglas['originales.'.$campo], 'required');
        }
        // El motivo es obligatorio solo después de comprobar cambio efectivo en el Service.
        $reglas['motivo_cambio'] = ['sometimes', 'nullable', 'string', 'max:2000'];

        return $reglas;
    }
}
