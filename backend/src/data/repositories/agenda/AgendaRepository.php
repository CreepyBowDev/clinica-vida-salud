<?php

namespace Clinica\data\repositories\agenda;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgendaRepository
{
    public function medico(string $id): ?array
    {
        return Medico::query()->select(['id_medico', 'codigo_medico', 'nombres', 'apellidos'])->find($id)?->getAttributes();
    }

    public function medicos(array $filtros): LengthAwarePaginator
    {
        $query = Medico::query()->select(['id_medico', 'codigo_medico', 'nombres', 'apellidos', 'activo']);
        if (isset($filtros['nombre'])) {
            $query->whereRaw("strpos(lower(nombres || ' ' || apellidos || ' ' || codigo_medico), lower(?)) > 0", [$filtros['nombre']]);
        }

        return $this->paginar($query->toBase(), $filtros, 'apellidos ASC, nombres ASC, id_medico ASC', 'id_medico');
    }

    public function citas(string $medico, CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros): LengthAwarePaginator
    {
        $query = $this->citasProyectadas()->where('citas.id_medico', $medico)
            ->where('citas.inicio_at', '<', $fin->format('Y-m-d H:i:s.uP'))
            ->where('citas.fin_at', '>', $inicio->format('Y-m-d H:i:s.uP'));
        if (isset($filtros['estado'])) {
            $query->where('citas.estado', $filtros['estado']);
        }

        return $this->paginar($query->toBase(), $filtros, 'inicio_at ASC, id_cita ASC', 'id_cita');
    }

    public function paciente(string $id): ?array
    {
        return Paciente::query()->select(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos'])->find($id)?->getAttributes();
    }

    public function historial(string $paciente, CarbonImmutable $ahora, array $filtros): LengthAwarePaginator
    {
        $query = $this->conFinanzas($this->citasProyectadas())->where('citas.id_paciente', $paciente);
        if (isset($filtros['situacion_precio'])) {
            if (! Schema::hasTable('obligaciones_pago')) {
                if ($filtros['situacion_precio'] !== 'no_incorporada') {
                    $query->whereRaw('false');
                }
            } else {
                match ($filtros['situacion_precio']) {
                    'no_incorporada' => $query->where('citas.incorporada_finanzas', false),
                    'pendiente_asignacion' => $query->where('citas.incorporada_finanzas', true)->whereNull('op.id_obligacion'),
                    'asignado' => $query->whereNotNull('op.id_obligacion'),
                };
            }
        }
        if ($filtros['periodo'] === 'proximas') {
            $query->whereIn('citas.estado', ['Programada', 'Confirmada'])->where('citas.fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'));
        } elseif ($filtros['periodo'] === 'anteriores') {
            $query->where('citas.fin_at', '<=', $ahora->format('Y-m-d H:i:s.uP'));
        }
        if (isset($filtros['estado'])) {
            $query->where('citas.estado', $filtros['estado']);
        }
        $orden = $filtros['periodo'] === 'proximas' ? 'inicio_at ASC, id_cita ASC' : 'inicio_at DESC, id_cita DESC';

        return $this->paginar($query->toBase(), $filtros, $orden, 'id_cita');
    }

    public function detalle(string $id, array $ambito, array $campos, bool $finanzas = false): ?array
    {
        // Ámbito y columnas los decide el Service, nunca parámetros HTTP.
        // Filtrar en SQL antes de leer: una cita ajena no se materializa en PHP.
        $query = $this->citasProyectadas();
        if ($finanzas) {
            $this->conFinanzas($query);
        }
        $row = $query->where('citas.id_cita', $id)->where($ambito)
            ->addSelect($campos)->toBase()->first();

        return $row !== null ? (array) $row : null;
    }

    private function conFinanzas(EloquentBuilder $query): EloquentBuilder
    {
        if (! Schema::hasTable('obligaciones_pago')) {
            return $query->selectRaw('false AS incorporada_finanzas');
        }

        $query->leftJoin('obligaciones_pago as op', 'op.id_cita', '=', 'citas.id_cita')
            ->leftJoin('cobros_pago as cp', fn ($join) => $join->on('cp.id_obligacion', '=', 'op.id_obligacion')->where('cp.aplicado', true))
            ->leftJoin('devoluciones_pago as dp', 'dp.id_cobro', '=', 'cp.id_cobro')
            ->addSelect(['citas.incorporada_finanzas', 'op.id_obligacion', 'op.centavos_bob', 'op.centavos_usd',
                'op.tasa_numerador', 'op.tasa_denominador', 'op.exigible', 'op.vencimiento_at',
                'cp.id_cobro', 'cp.proveedor as proveedor_cobro', 'dp.estado as estado_devolucion']);
        if (Schema::hasTable('recibos_academicos_pago')) {
            $query->leftJoin('recibos_academicos_pago as rp', 'rp.id_cobro', '=', 'cp.id_cobro')->addSelect('rp.numero as numero_recibo');
        }

        return $query;
    }

    private function citasProyectadas(): EloquentBuilder
    {
        // Proyección cerrada: no se seleccionan motivo, CI, contacto, UUID o contenido clínico.
        return Cita::query()->join('medicos as m', 'm.id_medico', '=', 'citas.id_medico')
            ->join('pacientes as p', 'p.id_paciente', '=', 'citas.id_paciente')
            ->join('especialidades as e', 'e.id_especialidad', '=', 'citas.id_especialidad')
            ->select(['citas.id_cita', 'citas.codigo_cita', 'citas.inicio_at', 'citas.fin_at', 'citas.duracion_minutos',
                'citas.estado', 'citas.modalidad', 'citas.sede_atencion', 'citas.consultorio_atencion', 'citas.creada_en',
                'm.id_medico', 'm.codigo_medico', 'm.nombres as medico_nombres', 'm.apellidos as medico_apellidos',
                'p.id_paciente', 'p.codigo_paciente', 'p.nombres as paciente_nombres', 'p.apellidos as paciente_apellidos',
                'e.id_especialidad', 'e.nombre as especialidad_nombre']);
    }

    private function paginar(Builder $query, array $filtros, string $orden, string $clave): LengthAwarePaginator
    {
        $page = (int) ($filtros['page'] ?? 1);
        $size = (int) ($filtros['per_page'] ?? 20);
        // Una sentencia = mismo snapshot para filas/total, incluso en página vacía.
        // Orden y clave son constantes internas; todas las entradas del usuario son bindings.
        $rows = DB::select('WITH filtradas AS MATERIALIZED ('.$query->toSql().'),
            pagina AS (SELECT * FROM filtradas ORDER BY '.$orden.' LIMIT ? OFFSET ?)
            SELECT pagina.*, total.total_agenda FROM (SELECT COUNT(*) AS total_agenda FROM filtradas) total
            LEFT JOIN pagina ON TRUE ORDER BY '.$orden,
            [...$query->getBindings(), $size, ($page - 1) * $size]);
        $total = (int) $rows[0]->total_agenda;
        $items = [];
        foreach ($rows as $row) {
            if ($row->{$clave} !== null) {
                $item = (array) $row;
                unset($item['total_agenda']);
                $items[] = $item;
            }
        }

        return new LengthAwarePaginator($items, $total, $size, $page);
    }
}
