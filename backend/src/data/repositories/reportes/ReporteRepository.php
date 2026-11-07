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
use OverflowException;

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

    public function pacientesRegistrados(CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros): array
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

        return $this->paginar($query->toBase(), $filtros, $orden, 'pacientes/registrados', $inicio, $fin);
    }

    public function citas(CarbonImmutable $inicio, CarbonImmutable $fin, array $filtros, string $reporte): array
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

        return $this->paginar($query->toBase(), $filtros, $orden, $reporte, $inicio, $fin);
    }

    private function paginar(Builder $query, array $filtros, string $orden, string $reporte, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $page = (int) ($filtros['page'] ?? 1);
        $size = (int) ($filtros['per_page'] ?? 20);
        $pacientes = $reporte === 'pacientes/registrados';
        $temporal = $pacientes || $reporte === 'citas/por-estado';
        $minutos = $reporte === 'citas/por-medico';
        // La agrupación depende de fechas civiles, no de días locales de 23/25 horas.
        $desdeCivil = CarbonImmutable::createFromFormat('!Y-m-d', $inicio->setTimezone('America/La_Paz')->format('Y-m-d'), 'UTC');
        $finCivil = CarbonImmutable::createFromFormat('!Y-m-d', $fin->setTimezone('America/La_Paz')->format('Y-m-d'), 'UTC');
        $diaria = $desdeCivil->diffInDays($finCivil) <= 31;
        // Solo expresiones cerradas; ninguna dimensión, orden o identificador procede de HTTP.
        if ($temporal) {
            $campo = $pacientes ? 'creado_en' : 'inicio_at';
            $formato = $diaria ? 'YYYY-MM-DD' : 'YYYY-MM';
            $grupos = "SELECT to_char($campo AT TIME ZONE 'America/La_Paz', '$formato') AS periodo,
                COUNT(*)::text AS cantidad FROM filtradas GROUP BY 1 ORDER BY 1";
        } elseif ($minutos) {
            $grupos = 'SELECT estado, COUNT(*)::text AS cantidad, SUM(duracion_minutos)::text AS minutos FROM filtradas GROUP BY estado';
        } else {
            $grupos = 'SELECT id_medico::text AS id_medico, codigo_medico, medico_nombres, medico_apellidos,
                COUNT(*)::text AS cantidad FROM filtradas
                GROUP BY id_medico, codigo_medico, medico_nombres, medico_apellidos
                ORDER BY COUNT(*) DESC, filtradas.id_medico ASC';
        }
        $ids = $pacientes ? "'id_paciente', pagina.id_paciente::text"
            : "'id_cita', pagina.id_cita::text, 'id_medico', pagina.id_medico::text,
                'id_paciente', pagina.id_paciente::text, 'id_especialidad', pagina.id_especialidad::text";
        $actividad = $pacientes ? '(SELECT json_agg(a) FROM (SELECT activo, COUNT(*)::text AS cantidad FROM filtradas GROUP BY activo) a)'
            : "'[]'::json";
        $duracion = $minutos ? 'COALESCE(SUM(duracion_minutos), 0)::text' : 'NULL::text';
        // Un sobre de una fila: misma sentencia/snapshot, incluso sin página o sin resultados.
        // IDs y agregados como texto antes de JSON: nunca pasan por un float al decodificar.
        $row = DB::selectOne('WITH filtradas AS MATERIALIZED ('.$query->toSql().'),
            pagina AS (SELECT * FROM filtradas ORDER BY '.$orden.' LIMIT ? OFFSET ?)
            SELECT COUNT(*)::text AS total_reporte, '.$duracion.' AS minutos,
                COALESCE((SELECT jsonb_agg(to_jsonb(pagina) || jsonb_build_object('.$ids.') ORDER BY '.$orden.') FROM pagina), \'[]\'::jsonb) AS filas,
                COALESCE((SELECT json_agg(g) FROM ('.$grupos.') g), \'[]\'::json) AS grupos,
                COALESCE('.$actividad.', \'[]\'::json) AS actividad FROM filtradas',
            [...$query->getBindings(), $size, ($page - 1) * $size]);
        $total = $row->total_reporte;
        if (! ctype_digit($total) || strlen($total) > 16 || (strlen($total) === 16 && strcmp($total, '9007199254740991') > 0)) {
            throw new OverflowException('Total estadístico no representable.');
        }

        return ['pagina' => new LengthAwarePaginator(json_decode($row->filas, true, 512, JSON_THROW_ON_ERROR), (int) $total, $size, $page),
            'agregados' => ['total' => $total, 'minutos' => $row->minutos,
                'grupos' => json_decode($row->grupos, true, 512, JSON_THROW_ON_ERROR),
                'actividad' => json_decode($row->actividad, true, 512, JSON_THROW_ON_ERROR)]];
    }
}
