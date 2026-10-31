<?php

namespace Clinica\data\repositories\historia_clinica;

use Carbon\CarbonImmutable;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\VersionAntecedente;
use Clinica\data\models\VersionConsulta;
use Illuminate\Support\Str;

class ConsultaMedicaRepository
{
    public function porCitaBloqueada(string $cita, string $paciente): ?ConsultaMedica
    {
        return ConsultaMedica::query()->where('id_cita', $cita)
            ->whereHas('cita', fn ($q) => $q->where('id_paciente', $paciente))->lockForUpdate()->first();
    }

    public function referenciaAntecedente(string $paciente): ?VersionAntecedente
    {
        // El padre Paciente ya está bloqueado, también cuando aún no existe versión.
        return VersionAntecedente::query()->select(['id_version_antecedente', 'numero_version'])
            ->where('id_paciente', $paciente)->orderByDesc('numero_version')->first();
    }

    public function crear(string $cita, CarbonImmutable $ahora): ConsultaMedica
    {
        $consulta = new ConsultaMedica;
        $consulta->forceFill(['id_cita' => $cita, 'codigo_consulta' => 'CON-'.Str::uuid(),
            'iniciada_en' => null, 'creada_en' => $ahora])->saveOrFail();

        return $consulta;
    }

    public function agregarVersion(string $consulta, string $autor, array $contenido, CarbonImmutable $ahora, int $numero = 1, ?string $motivo = null): VersionConsulta
    {
        $version = new VersionConsulta;
        $version->forceFill([...$contenido, 'id_consulta' => $consulta, 'numero_version' => $numero,
            'id_autor' => $autor, 'registrada_en' => $ahora, 'motivo_cambio' => $motivo])->saveOrFail();

        return $version;
    }

    public function agregarAntecedentes(string $paciente, string $consulta, string $autor, int $numero, array $contenido, CarbonImmutable $ahora, ?string $motivo = null): VersionAntecedente
    {
        $version = new VersionAntecedente;
        $version->forceFill([...$contenido, 'id_paciente' => $paciente, 'id_consulta_origen' => $consulta,
            'numero_version' => $numero, 'id_autor' => $autor, 'registrada_en' => $ahora, 'motivo_cambio' => $motivo])->saveOrFail();

        return $version;
    }
}
