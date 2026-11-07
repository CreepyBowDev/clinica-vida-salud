<?php

namespace Clinica\presentation\dtos\reportes;

use Illuminate\Validation\Rule;

class ReportePacientesRequest extends ReportePeriodoRequest
{
    public function rules(): array
    {
        $fecha = ['bail', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'];

        return $this->reglasListado([
            'fecha' => ['required_without_all:desde,hasta', 'prohibits:desde,hasta', ...$fecha],
            'desde' => ['required_without:fecha', 'prohibits:fecha', ...$fecha],
            'hasta' => ['required_without:fecha', 'prohibits:fecha', ...$fecha],
            'activo' => ['sometimes', 'required', Rule::in(['0', '1'])],
            'orden' => ['sometimes', 'required', Rule::in(['registro_asc', 'registro_desc', 'nombre_asc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
