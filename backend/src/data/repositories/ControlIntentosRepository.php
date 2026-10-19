<?php

namespace Clinica\data\repositories;

use Carbon\CarbonImmutable;
use Clinica\data\models\ControlIntentos;

class ControlIntentosRepository
{
    public function obtenerBloqueado(string $clave): ControlIntentos
    {
        ControlIntentos::query()->insertOrIgnore([
            'clave_ambito_hash' => $clave,
            'inicio_ventana' => CarbonImmutable::now('UTC'),
            'cantidad_intentos' => 0,
        ]);

        return ControlIntentos::query()->where('clave_ambito_hash', $clave)->lockForUpdate()->firstOrFail();
    }

    public function guardar(ControlIntentos $control): void
    {
        $control->saveOrFail();
    }
}
