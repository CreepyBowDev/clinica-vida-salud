<?php

namespace Clinica\data\repositories\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\SolicitudLaboratorio;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SolicitudLaboratorioRepository
{
    public function contextos(array $ids): Collection
    {
        return DB::table('solicitudes_laboratorio as s')->join('consultas_medicas as cm', 'cm.id_consulta', '=', 's.id_consulta')
            ->join('citas as c', 'c.id_cita', '=', 'cm.id_cita')->whereIn('s.id_solicitud', $ids)
            ->select(['s.id_solicitud', 's.id_consulta', 'c.id_cita', 'c.id_paciente', 'c.id_medico'])->orderBy('s.id_solicitud')->get();
    }

    public function bloquearOrdenadas(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return SolicitudLaboratorio::query()->whereIn('id_solicitud', $ids)->orderBy('id_solicitud')->lockForUpdate()->get();
    }

    public function asignarVersion(SolicitudLaboratorio $solicitud, ?string $version): void
    {
        $solicitud->forceFill(['estado_interno' => 'En revisión', 'id_version_actual' => $version, 'id_revision_publicada' => null])->saveOrFail();
    }

    public function publicar(SolicitudLaboratorio $solicitud, string $revision): void
    {
        $solicitud->forceFill(['estado_interno' => 'Disponible', 'id_revision_publicada' => $revision])->saveOrFail();
    }

    public function referenciaConsulta(string $id): ?ConsultaMedica
    {
        return ConsultaMedica::query()->select(['id_consulta', 'id_cita'])->whereKey($id)->first();
    }

    public function referenciaSolicitud(string $id): ?SolicitudLaboratorio
    {
        return SolicitudLaboratorio::query()->select(['id_solicitud', 'id_consulta'])->whereKey($id)->first();
    }

    public function crear(string $consulta, string $autor, string $estudio, CarbonImmutable $ahora): SolicitudLaboratorio
    {
        $solicitud = new SolicitudLaboratorio;
        $solicitud->forceFill(['id_consulta' => $consulta, 'creada_por' => $autor, 'estudio_solicitado' => $estudio,
            'solicitada_en' => $ahora, 'estado_interno' => 'Pendiente', 'id_version_actual' => null, 'id_revision_publicada' => null])->saveOrFail();

        return $solicitud;
    }

    private function base(array $ambito): Builder
    {
        $q = DB::table('solicitudes_laboratorio as s')
            ->join('consultas_medicas as cm', 'cm.id_consulta', '=', 's.id_consulta')
            ->join('citas as c', 'c.id_cita', '=', 'cm.id_cita')
            ->join('pacientes as p', 'p.id_paciente', '=', 'c.id_paciente')
            ->join('medicos as m', 'm.id_medico', '=', 'c.id_medico');
        if ($ambito['medico'] !== null) {
            $q->where('c.id_medico', $ambito['medico']);
        }
        if ($ambito['paciente'] !== null) {
            $q->where('c.id_paciente', $ambito['paciente']);
        }

        return $q;
    }

    private function publicadoSql(): string
    {
        return "s.estado_interno = 'Disponible' AND v.id_version_resultado IS NOT NULL
            AND r.decision = 'Autorizar' AND r.id_medico = c.id_medico
            AND v.numero_version = (SELECT MAX(ult.numero_version) FROM versiones_resultado ult WHERE ult.id_solicitud = s.id_solicitud)";
    }

    private function proyeccion(array $ambito): Builder
    {
        return $this->base($ambito)
            ->leftJoin('versiones_resultado as v', fn ($j) => $j->on('v.id_version_resultado', '=', 's.id_version_actual')->on('v.id_solicitud', '=', 's.id_solicitud'))
            ->leftJoin('revisiones_resultado as r', fn ($j) => $j->on('r.id_revision', '=', 's.id_revision_publicada')->on('r.id_version_resultado', '=', 'v.id_version_resultado'))
            ->leftJoin('documentos_laboratorio as d', 'd.id_documento', '=', 'v.id_documento')
            ->select(['s.id_solicitud', 's.id_consulta', 's.estudio_solicitado', 's.solicitada_en', 's.estado_interno',
                'c.id_paciente', 'p.codigo_paciente', 'p.nombres as paciente_nombres', 'p.apellidos as paciente_apellidos',
                'c.id_medico', 'm.codigo_medico', 'm.nombres as medico_nombres', 'm.apellidos as medico_apellidos',
                'v.id_version_resultado', 'v.numero_version', 'r.revisada_en as publicado_en', 'd.tipo_mime', 'd.tamano_bytes',
                ...($ambito['interno'] ? ['s.creada_por'] : [])])
            ->selectRaw('CASE WHEN ('.$this->publicadoSql().') THEN TRUE ELSE FALSE END AS publicado');
    }

    public function listar(array $ambito, array $filtros): LengthAwarePaginator
    {
        $q = $this->proyeccion($ambito);
        foreach (['id_paciente' => 'c.id_paciente', 'id_consulta' => 's.id_consulta'] as $campo => $columna) {
            if (isset($filtros[$campo])) {
                $q->where($columna, $filtros[$campo]);
            }
        }
        if (isset($filtros['estado'])) {
            if ($ambito['interno']) {
                $q->where('s.estado_interno', $filtros['estado']);
            } else {
                $q->whereRaw(($filtros['estado'] === 'Disponible' ? '' : 'NOT ').'(COALESCE(('.$this->publicadoSql().'), FALSE))');
            }
        }
        foreach (['fecha_desde', 'fecha_hasta'] as $campo) {
            if (isset($filtros[$campo])) {
                $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $filtros[$campo], 'America/La_Paz');
                $q->where('s.solicitada_en', $campo === 'fecha_desde' ? '>=' : '<',
                    ($campo === 'fecha_hasta' ? $fecha->addDay() : $fecha)->utc()->format('Y-m-d H:i:s.uP'));
            }
        }

        return $q->orderByDesc('s.solicitada_en')->orderByDesc('s.id_solicitud')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function consultar(string $id, array $ambito): ?object
    {
        return $this->proyeccion($ambito)->where('s.id_solicitud', $id)->first();
    }
}
