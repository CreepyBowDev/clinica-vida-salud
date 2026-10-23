<?php

namespace Clinica\presentation\dtos\agenda;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitaDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource['cita'];
        $rol = $this->resource['rol'];
        $data = (new CitaListadoResource($row))->toArray($request);
        if (in_array($rol, ['Recepcionista', 'Médico', 'Paciente'], true)) {
            $data['motivo_breve'] = $row['motivo_breve'];
        }
        if ($rol === 'Recepcionista') {
            $data['paciente']['documento_identidad'] = $row['paciente_documento_identidad'];
            $data['paciente']['telefono'] = $row['paciente_telefono'];
        } elseif ($rol === 'Médico') {
            $data['paciente']['fecha_nacimiento'] = $row['paciente_fecha_nacimiento'] === null ? null
                : CarbonImmutable::parse($row['paciente_fecha_nacimiento'])->format('Y-m-d');
        }

        return $data;
    }
}
