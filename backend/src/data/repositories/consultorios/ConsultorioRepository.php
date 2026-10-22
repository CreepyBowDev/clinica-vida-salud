<?php

namespace Clinica\data\repositories\consultorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Consultorio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ConsultorioRepository
{
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Consultorio::query();
        if (isset($filtros['q'])) {
            $query->where(fn ($q) => $q->whereRaw('strpos(lower(codigo_consultorio), lower(?)) > 0', [$filtros['q']])
                ->orWhereRaw('strpos(lower(nombre), lower(?)) > 0', [$filtros['q']]));
        }
        if (array_key_exists('activo', $filtros)) {
            $query->where('activo', (bool) $filtros['activo']);
        }

        return $query->orderByDesc('id_consultorio')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function porIdBloqueado(int|string $id): ?Consultorio
    {
        return Consultorio::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function resumen(Consultorio $consultorio, CarbonImmutable $ahora): Consultorio
    {
        return $consultorio->loadCount(['medicos', 'citas as obligaciones_count' => fn ($q) => $q
            ->whereIn('estado', ['Programada', 'Confirmada'])->where('fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'))]);
    }

    public function crear(array $datos): Consultorio
    {
        $consultorio = new Consultorio;
        $consultorio->forceFill(['codigo_consultorio' => $datos['codigo_consultorio'], 'nombre' => $datos['nombre'],
            'activo' => true, 'actualizado_en' => CarbonImmutable::now('UTC')])->saveOrFail();

        return $consultorio;
    }

    public function guardar(Consultorio $consultorio): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $consultorio->actualizado_en = $ahora->greaterThan($consultorio->actualizado_en)
            ? $ahora : $consultorio->actualizado_en->addMicrosecond();
        $consultorio->saveOrFail();
    }
}
