<?php

namespace Clinica\data\repositories\medicos;

use Carbon\CarbonImmutable;
use Clinica\data\models\Especialidad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EspecialidadRepository
{
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Especialidad::query();
        if (isset($filtros['nombre'])) {
            $query->whereRaw('strpos(lower(nombre), lower(?)) > 0', [$filtros['nombre']]);
        }
        if (array_key_exists('activa', $filtros)) {
            $query->where('activa', (bool) $filtros['activa']);
        }

        return $query->orderByDesc('id_especialidad')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function porIdBloqueado(int|string $id): ?Especialidad
    {
        return Especialidad::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function crear(array $datos): Especialidad
    {
        $especialidad = new Especialidad;
        $especialidad->forceFill([...$datos, 'activa' => true, 'actualizado_en' => CarbonImmutable::now('UTC')])->saveOrFail();

        return $especialidad;
    }

    public function guardar(Especialidad $especialidad): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $especialidad->actualizado_en = $ahora->greaterThan($especialidad->actualizado_en)
            ? $ahora : $especialidad->actualizado_en->addMicrosecond();
        $especialidad->saveOrFail();
    }
}
