<?php

namespace Clinica\data\repositories\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\SuscripcionPush;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SuscripcionPushRepository
{
    public function porId(string $id): ?SuscripcionPush
    {
        return SuscripcionPush::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function porInstalacion(string $hash): ?SuscripcionPush
    {
        return SuscripcionPush::query()->where('instalacion_hash', $hash)->lockForUpdate()->first();
    }

    public function porEndpoint(string $hash): ?SuscripcionPush
    {
        return SuscripcionPush::query()->where('endpoint_hash', $hash)->first();
    }

    public function propia(string $id, string $usuario): ?SuscripcionPush
    {
        return SuscripcionPush::query()->whereKey($id)->where('id_usuario', $usuario)->lockForUpdate()->first();
    }

    public function listar(string $usuario, string $paciente, int $page, int $perPage): LengthAwarePaginator
    {
        return SuscripcionPush::query()->where('id_usuario', $usuario)->where('id_paciente', $paciente)
            ->orderByDesc('id_suscripcion')->paginate($perPage, ['*'], 'page', $page);
    }

    public function guardar(SuscripcionPush $suscripcion): void
    {
        $suscripcion->saveOrFail();
    }

    public function paraPaciente(string $usuario, string $paciente): Collection
    {
        return SuscripcionPush::query()->where('id_usuario', $usuario)->where('id_paciente', $paciente)
            ->orderBy('id_suscripcion')->lockForUpdate()->get();
    }

    public function descartarPendientes(string $id): void
    {
        DB::table('envios_recordatorio_push')->where('id_suscripcion', $id)->where('estado', 'Pendiente')
            ->update(['estado' => 'Descartado', 'resultado_seguro' => 'AUTORIZACION_RETIRADA', 'actualizado_en' => CarbonImmutable::now('UTC')]);
    }

    public function revocar(SuscripcionPush $suscripcion): void
    {
        $ahora = CarbonImmutable::now('UTC')->max($suscripcion->actualizada_en)->max($suscripcion->autorizada_en);
        if ($suscripcion->revocada_en === null) {
            $suscripcion->revocada_en = $ahora;
            $suscripcion->actualizada_en = $ahora;
            $this->guardar($suscripcion);
        }
        $this->descartarPendientes((string) $suscripcion->getKey());
    }

    public function revocarInstalacion(?string $hash, ?string $exceptoUsuario = null): void
    {
        if ($hash === null) {
            return;
        }
        $this->exigirTransaccion();
        $row = $this->porInstalacion($hash);
        if ($row && ($exceptoUsuario === null || (string) $row->id_usuario !== $exceptoUsuario)) {
            $this->revocar($row);
        }
    }

    public function revocarUsuario(int|string $id): void
    {
        $this->exigirTransaccion();
        foreach (SuscripcionPush::query()->where('id_usuario', $id)->orderBy('id_suscripcion')->lockForUpdate()->get() as $row) {
            $this->revocar($row);
        }
    }

    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La revocación push requiere una transacción coordinada.');
        }
    }
}
