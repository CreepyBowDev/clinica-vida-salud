<?php

namespace Clinica\presentation\dtos\reportes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CantidadesCitasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return ['atendidas' => (int) $row['atendidas'], 'canceladas' => (int) $row['canceladas'],
            'inasistencias' => (int) $row['inasistencias'], 'total' => (int) $row['total']];
    }
}
