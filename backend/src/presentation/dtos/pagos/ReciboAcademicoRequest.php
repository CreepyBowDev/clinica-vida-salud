<?php

namespace Clinica\presentation\dtos\pagos;

class ReciboAcademicoRequest extends ConsultaPagosRequest
{
    public function validationData(): array
    {
        return [...$this->all(), 'obligacion_objetivo' => $this->route('obligacion')];
    }

    public function rules(): array
    {
        return ['obligacion_objetivo' => ['required', 'uuid'],
            ...($this->user()?->rol?->nombre === 'Administrador'
                ? ['id_paciente' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/']] : [])];
    }
}
