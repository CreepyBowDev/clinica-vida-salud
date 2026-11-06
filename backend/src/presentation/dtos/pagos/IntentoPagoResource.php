<?php

namespace Clinica\presentation\dtos\pagos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntentoPagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return ['id' => (string) $i->getKey(), 'obligacion_id' => (string) $i->id_obligacion,
            'proveedor' => $i->proveedor, 'estado' => $i->estado,
            'creado_at' => $i->creado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actualizado_at' => $i->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'centavos_usd' => $i->obligacion->centavos_usd, 'moneda' => 'USD',
            'cobros' => $i->cobros->map(fn ($c) => ['id' => (string) $c->getKey(), 'aplicado' => $c->aplicado,
                'incidencia' => $c->incidencia_codigo, 'centavos_usd' => $c->centavos_usd,
                'confirmado_at' => $c->confirmado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'referencia' => $c->referencia_cobro,
                'devolucion' => $c->devolucion === null ? null : new DevolucionPagoResource($c->devolucion)])->all()];
    }
}
