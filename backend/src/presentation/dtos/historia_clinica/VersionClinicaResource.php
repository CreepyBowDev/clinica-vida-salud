<?php

namespace Clinica\presentation\dtos\historia_clinica;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersionClinicaResource extends JsonResource
{
    public static function instante(mixed $fecha): ?string
    {
        return $fecha === null ? null : CarbonImmutable::parse($fecha)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public function toArray(Request $request): array
    {
        $version = $this->resource['version'];
        $consulta = $this->resource['tipo'] === 'consulta';
        $id = $consulta ? 'id_version_consulta' : 'id_version_antecedente';
        $data = [$id => (string) $version->getKey(), 'numero_version' => $version->numero_version,
            'registrada_en' => self::instante($version->registrada_en)];
        foreach ($consulta ? ['tipo_consulta', 'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento']
            : ['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes'] as $campo) {
            $data[$campo] = $version->getAttribute($campo);
        }
        if ($this->resource['interno']) {
            $data['id_autor'] = (string) $version->id_autor;
            $data['motivo_cambio'] = $version->motivo_cambio;
            if (! $consulta) {
                $data['id_consulta_origen'] = (string) $version->id_consulta_origen;
            }
        }

        return $data;
    }
}
