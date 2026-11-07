<?php

namespace Clinica\presentation\dtos\reportes;

use Illuminate\Validation\Rule;

class ReporteCitasRequest extends ReportePeriodoRequest
{
    public function rules(): array
    {
        $reporte = $this->route('reporte');
        $fecha = ['bail', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'];
        $id = ['bail', 'required', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/'];
        $rules = [
            'fecha' => $reporte === 'citas/programadas' ? ['required', ...$fecha]
                : ['required_without_all:desde,hasta', 'prohibits:desde,hasta', ...$fecha],
            'id_medico' => [...($reporte === 'citas/por-medico' ? [] : ['sometimes']), ...$id],
            'id_especialidad' => [...($reporte === 'citas/por-especialidad' ? [] : ['sometimes']), ...$id],
            'orden' => ['sometimes', 'required', Rule::in(['fecha_asc', 'fecha_desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
        if ($reporte !== 'citas/programadas') {
            $rules['desde'] = ['required_without:fecha', 'prohibits:fecha', ...$fecha];
            $rules['hasta'] = ['required_without:fecha', 'prohibits:fecha', ...$fecha];
            $rules['estado'] = [...($reporte === 'citas/por-estado' ? [] : ['sometimes']), 'required',
                Rule::in(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])];
        }

        return $this->reglasListado($rules);
    }
}
