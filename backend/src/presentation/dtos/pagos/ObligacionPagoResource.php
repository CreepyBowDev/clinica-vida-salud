<?php

namespace Clinica\presentation\dtos\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\PayPalPagoService;
use Clinica\business\services\pagos\StripePagoService;
use Clinica\shared\utils\ImportePago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ObligacionPagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $ahora = $o->calculado_at ?? CarbonImmutable::now('UTC');
        $liquidada = (bool) ($o->liquidada ?? false);
        $elegibilidad = ['puede_iniciar_pago' => false, 'revision_pago' => null];
        $proveedores = [];
        if ($request->user()?->rol?->nombre === 'Paciente') {
            $actor = (string) $request->user()->getKey();
            foreach (['stripe' => StripePagoService::class, 'paypal' => PayPalPagoService::class] as $proveedor => $service) {
                $disponible = app($service)->elegibilidad($o, $actor);
                if ($disponible['puede_iniciar_pago']) {
                    $proveedores[] = $proveedor;
                    if (! $elegibilidad['puede_iniciar_pago']) {
                        $elegibilidad = $disponible;
                    }
                }
            }
        }

        return ['id' => (string) $o->getKey(), 'cita' => ['id_cita' => (string) $o->cita->getKey(),
            'codigo_cita' => $o->cita->codigo_cita, 'inicio_at' => $o->cita->inicio_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'estado' => $o->cita->estado],
            'precio' => ImportePago::desdeHistorico($o->centavos_bob, $o->centavos_usd, $o->tasa_numerador, $o->tasa_denominador)->precio(),
            'emitida_at' => $o->emitida_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'vencimiento_at' => $o->vencimiento_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'exigible' => $o->exigible, 'liquidada' => $liquidada,
            'vencida' => $o->exigible && ! $liquidada && $ahora->greaterThanOrEqualTo($o->vencimiento_at),
            'operacion_en_verificacion' => (bool) ($o->operacion_en_verificacion ?? false),
            ...$elegibilidad,
            'proveedores_disponibles' => $proveedores,
            'intento_actual' => ! $o->relationLoaded('intentos') || $o->intentos->isEmpty() ? null
                : ['id' => (string) $o->intentos->first()->getKey(), 'proveedor' => $o->intentos->first()->proveedor,
                    'estado' => $o->intentos->first()->estado]];
    }
}
