<?php

namespace Clinica\presentation\dtos\reportes;

use Illuminate\Validation\Rule;

class ReporteAgendaRequest extends ReportePeriodoRequest
{
    public function rules(): array
    {
        return [
            'fecha' => ['bail', 'required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'id_medico' => ['bail', 'required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'],
            'estado' => ['sometimes', 'required', Rule::in(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
