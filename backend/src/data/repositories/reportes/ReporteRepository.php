<?php

namespace Clinica\data\repositories\reportes;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReporteRepository
{
    public function existeMedico(string $id): bool
    {
        return Medico::query()->whereKey($id)->exists();
    }

    public function existeEspecialidad(string $id): bool
    {
        return Especialidad::query()->whereKey($id)->exists();
    }

    public function cantidades(CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros): array
    {
        $query = Cita::query()->where('inicio_at', '>=', $inicio->format('Y-m-d H:i:s.uP'))
            ->where('inicio_at', '<', $fin->format('Y-m-d H:i:s.uP'))->whereIn('estado', $filtros['estados']);
        foreach (['id_medico', 'id_especialidad'] as $campo) {
            if (isset($filtros[$campo])) {
                $query->where($campo, $filtros[$campo]);
            }
        }

        // Una agregación, un snapshot y ninguna carga de filas ni contenido clínico.
        return (array) $query->selectRaw('COUNT(*) FILTER (WHERE estado = ?) AS atendidas,
            COUNT(*) FILTER (WHERE estado = ?) AS canceladas,
            COUNT(*) FILTER (WHERE estado = ?) AS inasistencias, COUNT(*) AS total',
            ['Atendida', 'Cancelada', 'Inasistencia'])->toBase()->first();
    }

    public function pacientesRegistrados(CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros): LengthAwarePaginator
    {
        // Una fila por ficha: sin joins a cuentas, citas, códigos o contenido clínico.
        $query = Paciente::query()->select(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos', 'activo', 'creado_en'])
            ->where('creado_en', '>=', $inicio->format('Y-m-d H:i:s.uP'))
            ->where('creado_en', '<', $fin->format('Y-m-d H:i:s.uP'));
        if (isset($filtros['activo'])) {
            $query->where('activo', $filtros['activo']);
        }
        $orden = match ($filtros['orden'] ?? 'registro_asc') {
            'registro_desc' => 'creado_en DESC, id_paciente DESC',
            'nombre_asc' => 'apellidos ASC, nombres ASC, id_paciente ASC',
            default => 'creado_en ASC, id_paciente ASC',
        };

        return $this->paginar($query->toBase(), $filtros, $orden, 'id_paciente');
    }

    public function citas(CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros): LengthAwarePaginator
    {
        // Proyección administrativa cerrada, compatible con CitaListadoResource; sin relaciones clínicas.
        $query = Cita::query()->join('medicos as m', 'm.id_medico', '=', 'citas.id_medico')
            ->join('pacientes as p', 'p.id_paciente', '=', 'citas.id_paciente')
            ->join('especialidades as e', 'e.id_especialidad', '=', 'citas.id_especialidad')
            ->select(['citas.id_cita', 'citas.codigo_cita', 'citas.inicio_at', 'citas.fin_at', 'citas.duracion_minutos',
                'citas.estado', 'citas.modalidad', 'citas.sede_atencion', 'citas.consultorio_atencion', 'citas.creada_en',
                'm.id_medico', 'm.codigo_medico', 'm.nombres as medico_nombres', 'm.apellidos as medico_apellidos',
                'p.id_paciente', 'p.codigo_paciente', 'p.nombres as paciente_nombres', 'p.apellidos as paciente_apellidos',
                'e.id_especialidad', 'e.nombre as especialidad_nombre'])
            ->where('citas.inicio_at', '>=', $inicio->format('Y-m-d H:i:s.uP'))
            ->where('citas.inicio_at', '<', $fin->format('Y-m-d H:i:s.uP'));
        foreach (['id_medico', 'id_especialidad', 'estado'] as $campo) {
            if (isset($filtros[$campo])) {
                $query->where('citas.'.$campo, $filtros[$campo]);
            }
        }
        // Solo constantes internas llegan al ORDER BY; los valores de filtros son bindings.
        $orden = ($filtros['orden'] ?? 'fecha_asc') === 'fecha_desc' ? 'inicio_at DESC, id_cita DESC' : 'inicio_at ASC, id_cita ASC';

        return $this->paginar($query->toBase(), $filtros, $orden, 'id_cita');
    }

    private function paginar(Builder $query, array $filtros, string $orden, string $clave): LengthAwarePaginator
    {
        $page = (int) ($filtros['page'] ?? 1);
        $size = (int) ($filtros['per_page'] ?? 20);
        // Página y total en un snapshot, incluido cuando la página solicitada está vacía.
        $rows = DB::select('WITH filtradas AS MATERIALIZED ('.$query->toSql().'),
            pagina AS (SELECT * FROM filtradas ORDER BY '.$orden.' LIMIT ? OFFSET ?)
            SELECT pagina.*, total.total_reporte FROM (SELECT COUNT(*) AS total_reporte FROM filtradas) total
            LEFT JOIN pagina ON TRUE ORDER BY '.$orden,
            [...$query->getBindings(), $size, ($page - 1) * $size]);
        $total = (int) $rows[0]->total_reporte;
        $items = [];
        foreach ($rows as $row) {
            if ($row->{$clave} !== null) {
                $item = (array) $row;
                unset($item['total_reporte']);
                $items[] = $item;
            }
        }

        return new LengthAwarePaginator($items, $total, $size, $page);
    }
}
