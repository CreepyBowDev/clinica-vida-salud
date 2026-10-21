<?php

namespace Clinica\data\repositories\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\TokenAcceso;

class TokenAccesoRepository
{
    public function porHash(string $hash, bool $bloquear = false): ?TokenAcceso
    {
        $query = TokenAcceso::query()->where('token_hash', $hash)->where('proposito', 'InvitacionPersonal');
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function ultimo(int|string $id): ?TokenAcceso
    {
        return TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'InvitacionPersonal')->orderByDesc('emitido_en')->orderByDesc('id_token')->first();
    }

    public function emisionesDesde(int|string $id, CarbonImmutable $desde): int
    {
        return TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'InvitacionPersonal')->where('emitido_en', '>', $desde)->count();
    }

    public function primeraEmisionDesde(int|string $id, CarbonImmutable $desde): ?TokenAcceso
    {
        return TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'InvitacionPersonal')
            ->where('emitido_en', '>', $desde)->orderBy('emitido_en')->first();
    }

    public function invalidarInvitaciones(int|string $id, CarbonImmutable $ahora): void
    {
        TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'InvitacionPersonal')
            ->whereNull('consumido_en')->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }

    public function emitir(int|string $id, string $hash, CarbonImmutable $ahora): TokenAcceso
    {
        $token = new TokenAcceso;
        $token->forceFill(['id_usuario' => $id, 'proposito' => 'InvitacionPersonal', 'token_hash' => $hash,
            'emitido_en' => $ahora, 'expira_en' => $ahora->addHours(24)])->saveOrFail();

        return $token;
    }

    public function consumir(TokenAcceso $token, CarbonImmutable $ahora): void
    {
        $token->consumido_en = $ahora;
        $token->saveOrFail();
    }

    public function recuperacionPorHash(string $hash, bool $bloquear = false): ?TokenAcceso
    {
        $query = TokenAcceso::query()->where('token_hash', $hash)->where('proposito', 'RecuperacionClave');
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function invalidarRecuperaciones(int|string $id, CarbonImmutable $ahora): void
    {
        TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'RecuperacionClave')
            ->whereNull('consumido_en')->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }

    public function emitirRecuperacion(int|string $id, string $hash, CarbonImmutable $ahora): TokenAcceso
    {
        $token = new TokenAcceso;
        $token->forceFill(['id_usuario' => $id, 'proposito' => 'RecuperacionClave', 'token_hash' => $hash,
            'emitido_en' => $ahora, 'expira_en' => $ahora->addMinutes(60)])->saveOrFail();

        return $token;
    }

    public function temporalPorId(int|string|null $id, bool $bloquear = false): ?TokenAcceso
    {
        $query = TokenAcceso::query()->whereKey($id)->where('proposito', 'CredencialTemporal');
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function ultimoTemporal(int|string $id, bool $bloquear = false): ?TokenAcceso
    {
        $query = TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'CredencialTemporal')
            ->orderByDesc('emitido_en')->orderByDesc('id_token');
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function emisionesTemporalesDesde(int|string $id, CarbonImmutable $desde): int
    {
        return TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'CredencialTemporal')
            ->where('emitido_en', '>', $desde)->count();
    }

    public function primeraTemporalDesde(int|string $id, CarbonImmutable $desde): ?TokenAcceso
    {
        return TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'CredencialTemporal')
            ->where('emitido_en', '>', $desde)->orderBy('emitido_en')->first();
    }

    public function invalidarTemporales(int|string $id, CarbonImmutable $ahora): void
    {
        TokenAcceso::query()->where('id_usuario', $id)->where('proposito', 'CredencialTemporal')
            ->whereNull('consumido_en')->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }

    public function emitirTemporal(int|string $id, string $hash, CarbonImmutable $ahora): TokenAcceso
    {
        $token = new TokenAcceso;
        $token->forceFill(['id_usuario' => $id, 'proposito' => 'CredencialTemporal', 'token_hash' => $hash,
            'emitido_en' => $ahora, 'expira_en' => $ahora->addMinutes((int) config('seguridad.credencial_temporal.vigencia_minutos'))])->saveOrFail();

        return $token;
    }
}
