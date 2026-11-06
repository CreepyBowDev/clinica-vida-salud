<?php

namespace Clinica\data\repositories\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\DevolucionPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ObligacionPagoRepository
{
    private const ACTIVOS = ['Preparando', 'RequiereAccion', 'Procesando', 'Incierto'];

    public function disponible(): bool
    {
        return Schema::hasTable('obligaciones_pago');
    }

    private function consulta(): Builder
    {
        return ObligacionPago::query()->with(['cita', 'liquidacion', 'intentos' => fn ($q) => $q->whereIn('estado', self::ACTIVOS)
            ->orderByDesc('creado_en')->orderByDesc('id_intento')->limit(1)])
            ->withExists(['liquidacion as liquidada', 'intentos as operacion_en_verificacion' => fn ($q) => $q
                ->where(fn ($i) => $i->whereIn('estado', self::ACTIVOS)
                    ->orWhereHas('operaciones', fn ($op) => $op->whereIn('estado', ['Pendiente', 'Procesando', 'Incierta'])))]);
    }

    public function paraRespuesta(ObligacionPago $obligacion): ObligacionPago
    {
        return $this->consulta()->findOrFail($obligacion->getKey());
    }

    private function fechas(Builder $query, array $filtros, string $columna): void
    {
        if (isset($filtros['fecha_desde'])) {
            $query->where($columna, '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_desde'], 'America/La_Paz')->utc()->format('Y-m-d H:i:s.uP'));
        }
        if (isset($filtros['fecha_hasta'])) {
            $query->where($columna, '<', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_hasta'], 'America/La_Paz')->addDay()->utc()->format('Y-m-d H:i:s.uP'));
        }
    }

    public function listar(string $paciente, array $filtros, CarbonImmutable $ahora): LengthAwarePaginator
    {
        $query = $this->consulta()->whereHas('cita', fn ($q) => $q->where('id_paciente', $paciente));
        $this->fechas($query, $filtros, 'emitida_en');
        if (isset($filtros['exigible'])) {
            $query->where('exigible', $filtros['exigible'] === '1');
        }
        if (isset($filtros['liquidada'])) {
            $filtros['liquidada'] === '1' ? $query->whereHas('liquidacion') : $query->whereDoesntHave('liquidacion');
        }
        if (isset($filtros['vencida'])) {
            $vencida = fn ($q) => $q->where('exigible', true)->whereDoesntHave('liquidacion')->where('vencimiento_at', '<=', $ahora->format('Y-m-d H:i:s.uP'));
            $filtros['vencida'] === '1' ? $query->where($vencida) : $query->whereNot($vencida);
        }
        if (isset($filtros['operacion_en_verificacion'])) {
            $activa = fn ($q) => $q->whereHas('intentos', fn ($i) => $i->whereIn('estado', self::ACTIVOS))
                ->orWhereHas('intentos.operaciones', fn ($o) => $o->whereIn('estado', ['Pendiente', 'Procesando', 'Incierta']));
            $filtros['operacion_en_verificacion'] === '1' ? $query->where($activa) : $query->whereNot($activa);
        }

        return $query->orderByDesc('emitida_en')->orderByDesc('id_obligacion')->paginate($filtros['per_page'] ?? 20,
            ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function detalle(string $id, string $paciente): ?ObligacionPago
    {
        return $this->consulta()->whereHas('cita', fn ($q) => $q->where('id_paciente', $paciente))->find($id);
    }

    public function historial(string $paciente, array $filtros, ?string $obligacion = null): LengthAwarePaginator
    {
        $query = IntentoPago::query()->with(['obligacion', 'cobros.devolucion.cobro'])
            ->whereHas('obligacion.cita', fn ($q) => $q->where('id_paciente', $paciente));
        if ($obligacion !== null) {
            $query->where('id_obligacion', $obligacion);
        }
        $this->fechas($query, $filtros, 'creado_en');
        foreach (['proveedor' => 'proveedor', 'resultado' => 'estado'] as $campo => $columna) {
            if (isset($filtros[$campo])) {
                $query->where($columna, $filtros[$campo]);
            }
        }

        return $query->orderByDesc('creado_en')->orderByDesc('id_intento')->paginate($filtros['per_page'] ?? 20,
            ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function devoluciones(string $obligacion, int $pagina): LengthAwarePaginator
    {
        return DevolucionPago::query()->with('cobro')->whereHas('cobro', fn ($q) => $q->where('id_obligacion', $obligacion))
            ->orderByDesc('creada_en')->orderByDesc('id_devolucion')->paginate(20, ['*'], 'devoluciones_page', $pagina);
    }

    public function porCita(string $cita): ?ObligacionPago
    {
        return ObligacionPago::query()->where('id_cita', $cita)->first();
    }

    public function crear(array $datos): ObligacionPago
    {
        $obligacion = new ObligacionPago;
        $obligacion->forceFill($datos)->saveOrFail();

        return $obligacion->refresh();
    }

    public function porCitaBloqueada(string $cita): ?ObligacionPago
    {
        return ObligacionPago::query()->where('id_cita', $cita)->lockForUpdate()->first();
    }

    public function tieneLiquidacion(ObligacionPago $obligacion): bool
    {
        return $obligacion->liquidacion()->exists();
    }

    public function guardar(ObligacionPago $obligacion): void
    {
        $obligacion->saveOrFail();
    }
}
