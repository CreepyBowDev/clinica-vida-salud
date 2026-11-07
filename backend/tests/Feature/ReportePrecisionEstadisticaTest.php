<?php

namespace Tests\Feature;

use Clinica\business\services\reportes\ReporteService;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ReportePrecisionEstadisticaTest extends TestCase
{
    public static function enteros(): array
    {
        return [[0, 0], ['0', 0], ['9007199254740991', 9007199254740991], ['9007199254740992', null],
            ['9223372036854775808', null], [1.0, null], [-1, null], ['01', null], ['1e2', null], [null, null]];
    }

    #[DataProvider('enteros')]
    public function test_safe_integer_conversion_without_float_or_truncation(mixed $entrada, ?int $salida): void
    {
        $clase = new ReflectionClass(ReporteService::class);
        if ($salida === null) {
            $this->expectException(OverflowException::class);
        }
        $this->assertSame($salida, $clase->getMethod('entero')->invoke($clase->newInstanceWithoutConstructor(), $entrada));
    }

    public function test_rational_percentage_at_maximum_safe_integer(): void
    {
        $clase = new ReflectionClass(ReporteService::class);
        $servicio = $clase->newInstanceWithoutConstructor();
        $metodo = $clase->getMethod('porcentaje');
        $this->assertSame('100.0', $metodo->invoke($servicio, 9007199254740991, 9007199254740991));
        $this->assertSame('0.0', $metodo->invoke($servicio, 1, 9007199254740991));
        $this->assertSame('50.0', $metodo->invoke($servicio, 4503599627370496, 9007199254740991));
        $this->assertNull($metodo->invoke($servicio, 0, 0));
    }
}
