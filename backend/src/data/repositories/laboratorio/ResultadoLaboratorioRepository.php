<?php

namespace Clinica\data\repositories\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\data\models\RevisionResultado;
use Clinica\data\models\SolicitudLaboratorio;
use Clinica\data\models\VersionResultado;
use Illuminate\Pagination\LengthAwarePaginator;

class ResultadoLaboratorioRepository
{
    public function contexto(SolicitudLaboratorio $s): array
    {
        $vigente = $this->version($s->id_version_actual === null ? null : (string) $s->id_version_actual);
        $ultima = $this->ultimaVersion((string) $s->getKey());
        $revision = $this->ultimaRevision($vigente === null ? null : (string) $vigente->getKey());

        return ['vigente' => $vigente, 'ultima' => $ultima, 'revision' => $revision,
            'originales' => ['estado_interno' => $s->estado_interno,
                'id_version_actual' => $s->id_version_actual === null ? null : (string) $s->id_version_actual,
                'id_ultima_revision' => $revision === null ? null : (string) $revision->getKey(),
                'ultima_version' => $ultima?->numero_version ?? 0]];
    }

    public function version(?string $id): ?VersionResultado
    {
        return $id === null ? null : VersionResultado::query()->whereKey($id)->first();
    }

    public function ultimaVersion(string $solicitud): ?VersionResultado
    {
        return VersionResultado::query()->where('id_solicitud', $solicitud)->orderByDesc('numero_version')->first();
    }

    public function ultimaRevision(?string $version): ?RevisionResultado
    {
        return $version === null ? null : RevisionResultado::query()->where('id_version_resultado', $version)
            ->orderByDesc('revisada_en')->orderByDesc('id_revision')->first();
    }

    public function fueReasociada(string $version, string $solicitud): bool
    {
        return VersionResultado::query()->where('id_version_origen', $version)->where('id_solicitud', '<>', $solicitud)->exists();
    }

    public function agregarRevision(string $version, string $responsable, string $revisor, string $decision, ?string $motivo, CarbonImmutable $ahora): RevisionResultado
    {
        $revision = new RevisionResultado;
        $revision->forceFill(['id_version_resultado' => $version, 'id_medico' => $responsable, 'revisada_por' => $revisor,
            'decision' => $decision, 'motivo' => $motivo, 'revisada_en' => $ahora])->saveOrFail();

        return $revision;
    }

    public function agregarVersion(string $solicitud, string $documento, int $numero, ?string $origen, string $autor, ?string $motivo, CarbonImmutable $ahora): VersionResultado
    {
        $v = new VersionResultado;
        $v->forceFill(['id_solicitud' => $solicitud, 'id_documento' => $documento, 'numero_version' => $numero,
            'id_version_origen' => $origen, 'id_autor' => $autor, 'motivo_cambio' => $motivo, 'registrada_en' => $ahora])->saveOrFail();

        return $v;
    }

    public function versiones(string $solicitud, array $filtros): LengthAwarePaginator
    {
        return VersionResultado::query()->select(['id_version_resultado', 'id_solicitud', 'numero_version', 'id_version_origen', 'id_autor', 'registrada_en', 'motivo_cambio'])
            ->selectSub(VersionResultado::query()->from('versiones_resultado as origen')->selectRaw('COUNT(*)')
                ->whereColumn('origen.id_version_resultado', 'versiones_resultado.id_version_origen')
                ->whereColumn('origen.id_solicitud', '<>', 'versiones_resultado.id_solicitud'), 'origen_ajeno')
            ->where('id_solicitud', $solicitud)->orderByDesc('numero_version')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }
}
