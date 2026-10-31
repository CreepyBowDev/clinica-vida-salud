<?php

namespace Clinica\presentation\dtos\historia_clinica;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistoriaClinicaResource extends JsonResource
{
    private function version(mixed $version, string $tipo, Request $request): ?array
    {
        return $version === null ? null : (new VersionClinicaResource([
            'version' => $version, 'tipo' => $tipo, 'interno' => $this->resource['interno'],
        ]))->toArray($request);
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        if (str_starts_with($r['vista'], 'versiones_')) {
            $tipo = $r['vista'] === 'versiones_consulta' ? 'consulta' : 'antecedentes';

            return $r['paginacion']->getCollection()->map(fn ($v) => $this->version($v, $tipo, $request))->all();
        }
        $p = $r['paciente'];
        $data = ['paciente' => ['id_paciente' => (string) $p->getKey(), 'codigo_paciente' => $p->codigo_paciente,
            'nombres' => $p->nombres, 'apellidos' => $p->apellidos],
            'antecedentes' => $this->version($r['antecedentes'], 'antecedentes', $request)];
        if ($r['vista'] === 'historia') {
            $data['consultas'] = $r['paginacion']->getCollection()->map(fn ($c) => [
                'id_consulta' => (string) $c->id_consulta, 'codigo_consulta' => $c->codigo_consulta, 'id_cita' => (string) $c->id_cita,
                'inicio_at' => VersionClinicaResource::instante($c->inicio_at), 'tipo_consulta' => $c->tipo_consulta,
                'medico' => ['id_medico' => (string) $c->id_medico, 'codigo_medico' => $c->codigo_medico,
                    'nombres' => $c->nombres, 'apellidos' => $c->apellidos],
            ])->all();

            return $data;
        }
        $cita = $r['cita'];
        $detalle = [];
        foreach (['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'] as $campo) {
            $detalle[$campo] = (string) $cita->getAttribute($campo);
        }
        foreach (['codigo_cita', 'estado', 'modalidad', 'duracion_minutos', 'version_programacion', 'sede_atencion', 'consultorio_atencion'] as $campo) {
            $detalle[$campo] = $cita->getAttribute($campo);
        }
        foreach (['inicio_at', 'fin_at', 'actualizada_en'] as $campo) {
            $detalle[$campo] = VersionClinicaResource::instante($cita->getAttribute($campo));
        }
        $detalle['medico'] = ['id_medico' => (string) $cita->medico->getKey(), 'codigo_medico' => $cita->medico->codigo_medico,
            'nombres' => $cita->medico->nombres, 'apellidos' => $cita->medico->apellidos];
        $consulta = $r['consulta'];
        $data['cita'] = $detalle;
        if (array_key_exists('modificado', $r)) {
            $data['modificado'] = $r['modificado'];
        }
        if ($r['interno'] && isset($r['edicion'])) {
            $data['edicion'] = $r['edicion'];
        }
        $data['consulta'] = $consulta === null ? null : [
            'id_consulta' => (string) $consulta->getKey(), 'codigo_consulta' => $consulta->codigo_consulta,
            'iniciada_en' => VersionClinicaResource::instante($consulta->iniciada_en),
            'creada_en' => VersionClinicaResource::instante($consulta->creada_en),
            'version_vigente' => $this->version($r['version'], 'consulta', $request),
        ];
        $data['estudios'] = $r['paginacion']->getCollection()->map(fn ($e) => [
            'id_solicitud' => $e['id_solicitud'], 'estudio_solicitado' => $e['estudio_solicitado'],
            'solicitada_en' => VersionClinicaResource::instante($e['solicitada_en']), 'estado' => $e['estado'],
            'resultado' => $e['resultado'] === null ? null : ['numero_version' => $e['resultado']['numero_version'],
                'publicado_en' => VersionClinicaResource::instante($e['resultado']['publicado_en'])],
        ])->all();

        return $data;
    }

    public function with(Request $request): array
    {
        $p = $this->resource['paginacion'];

        return ['meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(),
            'last_page' => $p->lastPage(), 'zona_horaria' => 'America/La_Paz']];
    }

    public function withResponse(Request $request, $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}
