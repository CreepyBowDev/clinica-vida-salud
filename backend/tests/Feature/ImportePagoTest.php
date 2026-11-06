<?php

namespace Tests\Feature;

use Clinica\shared\utils\ImportePago;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportePagoTest extends TestCase
{
    public static function validos(): array
    {
        return [['7', 700, 100, '7.00', '1.00'], ['7.0', 700, 100, '7.00', '1.00'],
            ['7.00', 700, 100, '7.00', '1.00'], ['7.03', 703, 100, '7.03', '1.00'],
            ['7.04', 704, 101, '7.04', '1.01'], ['70.00', 7000, 1000, '70.00', '10.00'],
            ['140', 14000, 2000, '140.00', '20.00'], ['210', 21000, 3000, '210.00', '30.00'],
            ['6999.99', 699999, 100000, '6999.99', '1000.00'], ['7000.00', 700000, 100000, '7000.00', '1000.00']];
    }

    #[DataProvider('validos')]
    public function test_exact_inputs_snapshots_and_provider_formats(string $entrada, int $bob, int $usd, string $normal, string $paypal): void
    {
        $importe = ImportePago::desdeBob($entrada);
        $this->assertSame($bob, $importe->centavosBob);
        $this->assertSame($usd, $importe->centavosUsd);
        $this->assertSame($normal, $importe->montoBob());
        $this->assertSame($usd, $importe->centavosStripe());
        $this->assertSame($paypal, $importe->montoPayPal());
        $snapshot = ImportePago::desdeHistorico($bob, $usd, 7, 1);
        $this->assertEquals($importe, $snapshot);
        $this->assertSame($importe->montoPayPal(), $snapshot->montoPayPal());
    }

    public static function invalidos(): array
    {
        return array_map(fn ($valor) => [$valor], [null, false, true, 7, 7.0, [], new \stdClass,
            '', '0', '0.00', '6.99', '7000.01', '9999', '10000', '-7', '+7', ' 7', '7 ', "7\n",
            '7,00', '7,000.00', '7e0', '07', '00', '.7', '7.', '7.000', '7.001', '７', '7/1', str_repeat('9', 1000)]);
    }

    #[DataProvider('invalidos')]
    public function test_rejects_without_normalizing_or_rounding(mixed $valor): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportePago::desdeBob($valor);
    }

    public function test_every_permitted_cent_has_one_rounding_and_same_usd_for_both_providers(): void
    {
        // Oráculo independiente por cociente/resto; 7:1 nunca produce un empate exacto .5.
        for ($bob = 700; $bob <= 700000; $bob++) {
            $entrada = intdiv($bob, 100).'.'.str_pad((string) ($bob % 100), 2, '0', STR_PAD_LEFT);
            $importe = ImportePago::desdeBob($entrada);
            $esperado = intdiv($bob, 7) + (($bob % 7) >= 4 ? 1 : 0);
            [$entero, $decimal] = explode('.', $importe->montoPayPal());
            if ($importe->centavosStripe() !== $esperado || (int) $entero * 100 + (int) $decimal !== $esperado) {
                $this->fail('Conversión no exacta para '.$entrada);
            }
        }
        $this->assertSame(699301, 700000 - 700 + 1);
    }

    public function test_incoherent_history_is_not_silently_reconverted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportePago::desdeHistorico(704, 100, 7, 1);
    }

    public function test_another_rate_is_not_a_runtime_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportePago::desdeHistorico(700, 100, 8, 1);
    }
}
