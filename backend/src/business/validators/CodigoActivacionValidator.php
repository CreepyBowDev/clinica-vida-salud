<?php

namespace Clinica\business\validators;

use Carbon\CarbonImmutable;
use Clinica\data\models\CodigoActivacionPaciente;

class CodigoActivacionValidator
{
    public static function documentoValido(?string $ci): bool
    {
        return $ci !== null && trim($ci, ' ') !== '' && mb_strlen($ci) <= 50 && preg_match('/^[^\p{C}]+$/u', $ci) === 1;
    }

    public static function vigente(?CodigoActivacionPaciente $codigo): bool
    {
        $ahora = CarbonImmutable::now('UTC');

        return $codigo !== null && $codigo->consumido_en === null && $codigo->invalidado_en === null
            && $codigo->intentos_fallidos < 5 && $ahora->greaterThanOrEqualTo($codigo->emitido_en)
            && $ahora->lessThan($codigo->expira_en);
    }
}
