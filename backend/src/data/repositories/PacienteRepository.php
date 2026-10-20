<?php

namespace Clinica\data\repositories;

use Carbon\CarbonImmutable;
use Clinica\data\models\Paciente;
use Clinica\data\models\PreferenciaPaciente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PacienteRepository
{
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Paciente::query()->select(['id_paciente', 'codigo_paciente', 'documento_identidad', 'nombres', 'apellidos', 'activo']);
        if (isset($filtros['documento_identidad'])) {
            $query->whereRaw('btrim(documento_identidad) = ?', [$filtros['documento_identidad']]);
        }
        foreach (['nombres', 'apellidos'] as $campo) {
            if (isset($filtros[$campo])) {
                // strpos evita interpretar % y _ del usuario como comodines.
                $query->whereRaw('strpos(lower('.$campo.'), lower(?)) > 0', [$filtros[$campo]]);
            }
        }
        $query->when(array_key_exists('activo', $filtros), fn (Builder $q) => $q->where('activo', (bool) $filtros['activo']));

        return $query->orderByDesc('id_paciente')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function consultar(int|string $id): ?Paciente
    {
        $paciente = Paciente::query()->whereKey($id)->lockForUpdate()->first();

        return $paciente?->load(['contactoEmergencia', 'preferencia']);
    }

    public function porUsuario(int|string $id): ?Paciente
    {
        return Paciente::query()->where('id_usuario', $id)->lockForUpdate()->first()?->load(['contactoEmergencia', 'preferencia']);
    }

    public function crear(array $datos, ?array $emergencia): Paciente
    {
        $paciente = new Paciente;
        $paciente->forceFill($datos)->saveOrFail();
        if ($emergencia !== null) {
            $paciente->contactoEmergencia()->create($emergencia);
        }

        return $paciente->load(['contactoEmergencia', 'preferencia']);
    }

    public function guardar(Paciente $paciente): void
    {
        // El lock de la ficha y la transacción corresponden al Service.
        // También avanza por cambios solo en relaciones; Eloquent no debe reemplazarlo.
        $ahora = CarbonImmutable::now('UTC');
        $paciente->actualizado_en = $ahora->greaterThan($paciente->actualizado_en)
            ? $ahora : $paciente->actualizado_en->addMicrosecond();
        $paciente->saveOrFail();
    }

    public function guardarEmergencia(Paciente $paciente, ?array $datos): void
    {
        if ($datos === null) {
            $paciente->contactoEmergencia()->delete();
        } else {
            $paciente->contactoEmergencia()->updateOrCreate([], $datos);
        }
    }

    public function guardarRecordatorios(Paciente $paciente, bool $habilitados): void
    {
        $preferencia = $paciente->preferencia ?? new PreferenciaPaciente;
        if (! $preferencia->exists) {
            $preferencia->forceFill(['id_paciente' => $paciente->getKey(), 'preferencias_privacidad' => (object) []]);
        }
        $preferencia->recordatorios_habilitados = $habilitados;
        $preferencia->saveOrFail();
    }
}
