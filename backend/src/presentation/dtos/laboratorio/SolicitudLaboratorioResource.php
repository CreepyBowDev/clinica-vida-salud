<?php

namespace Clinica\presentation\dtos\laboratorio;

use Clinica\presentation\dtos\historia_clinica\VersionClinicaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudLaboratorioResource extends JsonResource
{
    private function solicitud(object $s): array
    {
        $data = ['id_solicitud' => (string) $s->id_solicitud, 'id_consulta' => (string) $s->id_consulta,
            'estudio_solicitado' => $s->estudio_solicitado, 'solicitada_en' => VersionClinicaResource::instante($s->solicitada_en),
            'paciente' => ['id_paciente' => (string) $s->id_paciente, 'codigo_paciente' => $s->codigo_paciente,
                'nombres' => $s->paciente_nombres, 'apellidos' => $s->paciente_apellidos],
            'medico' => ['id_medico' => (string) $s->id_medico, 'codigo_medico' => $s->codigo_medico,
                'nombres' => $s->medico_nombres, 'apellidos' => $s->medico_apellidos],
            'estado' => $this->resource['interno'] ? $s->estado_interno : ($s->publicado ? 'Disponible' : 'Pendiente'),
            'resultado' => $s->publicado ? ['id_version_resultado' => (string) $s->id_version_resultado,
                'numero_version' => (int) $s->numero_version, 'publicado_en' => VersionClinicaResource::instante($s->publicado_en),
                'tipo_mime' => $s->tipo_mime, 'tamano_bytes' => (int) $s->tamano_bytes] : null];
        if ($this->resource['interno']) {
            $data['creada_por'] = $s->creada_por === null ? null : (string) $s->creada_por;
        }

        return $data;
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        if (isset($r['solicitud'])) {
            return [...$this->solicitud($r['solicitud']),
                ...(isset($r['documentacion']) ? ['edicion' => $r['documentacion']] : []),
                ...(isset($r['destino']) ? ['destino' => $this->solicitud($r['destino'])] : [])];
        }
        if (isset($r['versiones'])) {
            return $r['versiones']->getCollection()->map(fn ($v) => [
                'id_version_resultado' => (string) $v->getKey(), 'numero_version' => $v->numero_version,
                'id_autor' => (string) $v->id_autor, 'registrada_en' => VersionClinicaResource::instante($v->registrada_en),
                ...($r['administrativo'] || (int) $v->origen_ajeno === 0 ? ['motivo_cambio' => $v->motivo_cambio] : []),
                ...($r['administrativo'] ? ['id_version_origen' => $v->id_version_origen === null ? null : (string) $v->id_version_origen] : []),
            ])->all();
        }
        $lista = $r['paginacion']->getCollection()->map(fn ($s) => $this->solicitud($s))->all();
        if ($r['id_consulta'] === null) {
            return $lista;
        }

        return ['id_consulta' => $r['id_consulta'], 'solicitudes' => $lista,
            ...($r['edicion'] === null ? [] : ['edicion' => $r['edicion']])];
    }

    public function with(Request $request): array
    {
        if (! isset($this->resource['paginacion']) && ! isset($this->resource['versiones'])) {
            return [];
        }
        $p = $this->resource['paginacion'] ?? $this->resource['versiones'];

        return ['meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(),
            'last_page' => $p->lastPage(), 'zona_horaria' => 'America/La_Paz']];
    }

    public function withResponse(Request $request, $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}
