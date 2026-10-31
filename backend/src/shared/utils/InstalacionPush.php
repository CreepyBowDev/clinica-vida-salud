<?php

namespace Clinica\shared\utils;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;

class InstalacionPush
{
    public function actual(): ?string
    {
        // EncryptCookies autentica/descifra la cookie. El valor no autentica al Usuario.
        $token = request()->cookie(config('push.cookie'));
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\.([0-9]{10})\z/', $token, $m)) {
            return null;
        }
        $now = CarbonImmutable::now('UTC')->timestamp;
        $max = $now + $this->dias() * 86400;

        return (int) $m[1] > $now && (int) $m[1] <= $max ? $token : null;
    }

    private function dias(): int
    {
        return max(1, min(365, (int) config('push.instalacion_dias', 180)));
    }

    public function paraAlta(): string
    {
        return $this->actual() ?? bin2hex(random_bytes(32)).'.'.CarbonImmutable::now('UTC')->addDays($this->dias())->timestamp;
    }

    public function resumen(?string $token): ?string
    {
        return $token === null ? null : hash('sha256', $token);
    }

    public function vence(string $token): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) explode('.', $token)[1]);
    }

    public function confirmar(string $token): void
    {
        $minutos = max(1, (int) ceil(($this->vence($token)->timestamp - CarbonImmutable::now('UTC')->timestamp) / 60));
        Cookie::queue(Cookie::make(config('push.cookie'), $token, $minutos, '/', null,
            app()->environment('production') || (bool) config('session.secure'), true, false, 'lax'));
    }
}
