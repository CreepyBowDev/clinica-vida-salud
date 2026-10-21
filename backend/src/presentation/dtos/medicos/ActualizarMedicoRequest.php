<?php

namespace Clinica\presentation\dtos\medicos;

use Clinica\presentation\dtos\usuarios\CuentaRequest;

class ActualizarMedicoRequest extends CuentaRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo_contacto'))) {
            $this->merge(['correo_contacto' => mb_strtolower(trim($this->input('correo_contacto'), ' '), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        $campos = (new CrearMedicoRequest)->rules();
        unset($campos['especialidades'], $campos['especialidades.*']);
        foreach ($campos as &$reglas) {
            $reglas = ['sometimes', ...$reglas];
        }

        return [...parent::rules(), ...$campos, 'actualizado_en_original' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z']];
    }
}
