<?php

namespace Clinica\business\services\pagos;

/** Dos recuperadores concretos: sin motor genérico, escrituras nuevas ni resolución manual. */
class ConciliacionPagosService
{
    public function lote(int $limite = 100): array
    {
        if ($limite < 1 || $limite > 1000) {
            throw new \InvalidArgumentException('Lote fuera de rango.');
        }
        $resultado = [];
        foreach (['stripe', 'paypal'] as $proveedor) {
            if (config('pagos.'.$proveedor.'.habilitado') !== true) {
                $resultado[$proveedor] = ['habilitado' => false];

                continue;
            }
            try {
                $eventos = app($proveedor === 'stripe' ? EventoStripeService::class : EventoPayPalService::class);
                $recuperacion = app($proveedor === 'stripe' ? RecuperacionStripeService::class : RecuperacionPayPalService::class);
                $resultado[$proveedor] = ['eventos_revisados' => $eventos->lote($limite), ...$recuperacion->lote($limite), 'error' => false];
            } catch (\Throwable) {
                // El otro proveedor conserva su turno; jamás imprimir respuestas externas o excepciones.
                $resultado[$proveedor] = ['error' => true];
            }
        }

        return $resultado;
    }
}
