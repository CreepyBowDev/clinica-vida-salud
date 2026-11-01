<?php

namespace Clinica\presentation\dtos\reportes;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PacienteRegistradoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return ['id_paciente' => (string) $row['id_paciente'], 'codigo_paciente' => $row['codigo_paciente'],
            'nombres' => $row['nombres'], 'apellidos' => $row['apellidos'], 'activo' => (bool) $row['activo'],
            'creado_en' => CarbonImmutable::parse($row['creado_en'])->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
