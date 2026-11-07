<?php

namespace Clinica\data\repositories\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\EnvioRecordatorioPush;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EnvioRecordatorioPushRepository
{
    public function ultimoId(): string
    {
        return (string) (EnvioRecordatorioPush::query()->max('id_envio') ?? '0');
    }

    public function candidatos(string $cursor, string $hasta, int $lote, CarbonImmutable $ahora): array
    {
        $fecha = $ahora->format('Y-m-d H:i:s.uP');

        return EnvioRecordatorioPush::query()->where('id_envio', '>', $cursor)->where('id_envio', '<=', $hasta)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('estado', 'Pendiente')->where('proximo_intento_en', '<=', $fecha))
                ->orWhere(fn ($p) => $p->where('estado', 'Procesando')->where('reclamado_hasta', '<=', $fecha)))
            ->orderBy('id_envio')->limit($lote)->pluck('id_envio')->map(fn ($id) => (string) $id)->all();
    }

    public function localizar(string $id): ?object
    {
        return DB::table('envios_recordatorio_push as e')->join('recordatorios as r', 'r.id_recordatorio', '=', 'e.id_recordatorio')
            ->where('e.id_envio', $id)->select(['e.*', 'r.id_cita', 'r.id_administrador'])->first();
    }

    public function bloquear(string $id): ?EnvioRecordatorioPush
    {
        return EnvioRecordatorioPush::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function guardar(EnvioRecordatorioPush $envio, array $datos, CarbonImmutable $ahora): void
    {
        $envio->forceFill($datos + ['actualizado_en' => $ahora->max($envio->actualizado_en)])->saveOrFail();
    }

    public function pendientesPorCita(string $id, string $origen = 'Automatico'): Collection
    {
        return EnvioRecordatorioPush::query()->whereIn('id_recordatorio', function ($q) use ($id, $origen): void {
            $q->select('id_recordatorio')->from('recordatorios')->where('id_cita', $id)->where('origen', $origen);
        })->where('estado', 'Pendiente')->orderBy('id_envio')->lockForUpdate()->get();
    }

    public function descartar(EnvioRecordatorioPush $envio, CarbonImmutable $ahora): void
    {
        $envio->forceFill(['estado' => 'Descartado', 'resultado_seguro' => 'RECORDATORIO_NO_VIGENTE',
            'actualizado_en' => $ahora->max($envio->actualizado_en)])->saveOrFail();
    }

    public function preparar(array $datos): bool
    {
        // ON CONFLICT DO NOTHING protege la UQ del par; no reabre terminales ni oculta fallos FK/CHECK.
        return DB::table('envios_recordatorio_push')->insertOrIgnore($datos) === 1;
    }
}
