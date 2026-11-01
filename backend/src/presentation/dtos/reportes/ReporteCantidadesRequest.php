<?php

namespace Clinica\presentation\dtos\reportes;

class ReporteCantidadesRequest extends ReportePeriodoRequest
{
    public function rules(): array
    {
        $fecha = ['bail', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'];
        $id = ['sometimes', 'bail', 'required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];

        return ['fecha' => ['required_without_all:desde,hasta', 'prohibits:desde,hasta', ...$fecha],
            'desde' => ['required_without:fecha', 'prohibits:fecha', ...$fecha],
            'hasta' => ['required_without:fecha', 'prohibits:fecha', ...$fecha],
            'id_medico' => $id, 'id_especialidad' => $id];
    }
}
