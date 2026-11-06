<?php

namespace Clinica\presentation\dtos\pagos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DevolucionPagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->getKey(), 'estado' => $this->estado, 'centavos_usd' => $this->centavos_usd,
            'moneda' => 'USD', 'creada_at' => $this->creada_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actualizada_at' => $this->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
