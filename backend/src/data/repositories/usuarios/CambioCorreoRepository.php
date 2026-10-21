<?php

namespace Clinica\data\repositories\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\VerificacionCorreo;
use Illuminate\Support\Facades\DB;

class CambioCorreoRepository
{
    public function ultimo(int|string $usuario): ?VerificacionCorreo
    {
        return VerificacionCorreo::query()->where('id_usuario', $usuario)->where('proposito', 'CambioCorreo')
            ->orderByDesc('id_verificacion')->lockForUpdate()->first();
    }

    public function ultimoPorCorreo(string $correo): ?VerificacionCorreo
    {
        return VerificacionCorreo::query()->where('proposito', 'CambioCorreo')->whereRaw('lower(btrim(correo_destino)) = ?', [$correo])
            ->orderByDesc('enviado_en')->orderByDesc('id_verificacion')->first();
    }

    public function invalidar(int|string $usuario, CarbonImmutable $ahora): void
    {
        // Incluye emisiones todavía en transporte (invalidado_en = enviado_en).
        // La marca distinta impide que un callback tardío vuelva a habilitarlas.
        DB::update("UPDATE verificaciones_correo SET invalidado_en = GREATEST(enviado_en + interval '1 microsecond', ?::timestamptz)
            WHERE id_usuario = ? AND proposito = 'CambioCorreo' AND consumido_en IS NULL",
            [$ahora->format('Y-m-d H:i:s.uP'), $usuario]);
    }

    public function crear(int|string $usuario, string $correo, string $hash, CarbonImmutable $ahora): VerificacionCorreo
    {
        $codigo = new VerificacionCorreo;
        $codigo->forceFill(['id_usuario' => $usuario, 'proposito' => 'CambioCorreo', 'correo_destino' => $correo,
            'codigo_hash' => $hash, 'enviado_en' => $ahora, 'expira_en' => $ahora->addMinutes(10),
            'invalidado_en' => $ahora])->saveOrFail();

        return $codigo;
    }

    public function guardar(VerificacionCorreo $codigo): void
    {
        $codigo->saveOrFail();
    }

    public function codigoUtilizado(int|string $usuario, string $hash): bool
    {
        return VerificacionCorreo::query()->where('id_usuario', $usuario)->where('proposito', 'CambioCorreo')->where('codigo_hash', $hash)->exists();
    }

    public function emisionesHora(int|string $usuario, string $correo, CarbonImmutable $desde): iterable
    {
        return VerificacionCorreo::query()->where('proposito', 'CambioCorreo')->where('enviado_en', '>', $desde)
            ->where(fn ($q) => $q->where('id_usuario', $usuario)->orWhereRaw('lower(btrim(correo_destino)) = ?', [$correo]))
            ->orderBy('enviado_en')->get();
    }
}
