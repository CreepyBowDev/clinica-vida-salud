<?php

namespace Clinica\shared\utils;

use InvalidArgumentException;

/** Valor histórico puro: no lee configuración ni recibe importes del proveedor. */
final readonly class ImportePago
{
    private function __construct(
        public int $centavosBob,
        public int $centavosUsd,
        public int $tasaNumerador = 7,
        public int $tasaDenominador = 1,
    ) {}

    public static function desdeBob(mixed $monto): self
    {
        if (! is_string($monto)
            || preg_match('/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?\z/', $monto) !== 1) {
            throw new InvalidArgumentException('El monto BOB debe ser un string decimal válido con máximo dos decimales.');
        }

        [$entero, $decimal] = array_pad(explode('.', $monto), 2, '');
        $bob = ((int) $entero) * 100 + (int) str_pad($decimal, 2, '0');
        if ($bob < 700 || $bob > 700000) {
            throw new InvalidArgumentException('El monto BOB debe estar entre 7.00 y 7000.00.');
        }

        return new self($bob, intdiv(2 * $bob + 7, 14));
    }

    /** Recupera el snapshot sin convertir otra vez ni consultar una tasa vigente. */
    public static function desdeHistorico(int $bob, int $usd, int $numerador, int $denominador): self
    {
        if ($bob < 700 || $bob > 700000 || $numerador !== 7 || $denominador !== 1
            || $usd !== intdiv(2 * $bob + 7, 14)) {
            throw new InvalidArgumentException('El importe histórico no corresponde al esquema académico 7:1.');
        }

        return new self($bob, $usd, $numerador, $denominador);
    }

    public function montoBob(): string
    {
        return self::decimal($this->centavosBob);
    }

    public function centavosStripe(): int
    {
        return $this->centavosUsd;
    }

    public function montoPayPal(): string
    {
        return self::decimal($this->centavosUsd);
    }

    public function precio(): array
    {
        return ['monto_bob' => $this->montoBob(), 'centavos_bob' => $this->centavosBob, 'moneda_original' => 'BOB',
            'tasa_bob_por_usd' => '7.00', 'monto_usd' => $this->montoPayPal(),
            'centavos_usd' => $this->centavosUsd, 'moneda_cobro' => 'USD'];
    }

    private static function decimal(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
