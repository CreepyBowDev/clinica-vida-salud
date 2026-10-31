<?php

namespace Clinica\data\repositories\historia_clinica;

use Clinica\data\models\Cita;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\Paciente;
use Clinica\data\models\VersionAntecedente;
use Clinica\data\models\VersionConsulta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class HistoriaClinicaRepository
{
    public function paciente(string $id): ?Paciente
    {
        return Paciente::query()->select(['id_paciente', 'id_usuario', 'codigo_paciente', 'nombres', 'apellidos'])
            ->whereKey($id)->lockForUpdate()->first();
    }

    public function referenciaCita(string $id): ?Cita
    {
        return Cita::query()->select(['id_cita', 'id_paciente', 'id_medico'])->whereKey($id)->first();
    }

    public function cita(string $id, string $paciente): ?Cita
    {
        return Cita::query()->whereKey($id)->where('id_paciente', $paciente)->lockForUpdate()
            ->with('medico:id_medico,codigo_medico,nombres,apellidos')->first();
    }

    public function relacionMedica(string $medico, string $paciente): bool
    {
        return Cita::query()->where('id_medico', $medico)->where('id_paciente', $paciente)
            ->whereIn('estado', ['Programada', 'Confirmada', 'Atendida'])->exists();
    }

    public function consultas(string $paciente, array $filtros): LengthAwarePaginator
    {
        return ConsultaMedica::query()->join('citas as c', 'c.id_cita', '=', 'consultas_medicas.id_cita')
            ->join('medicos as m', 'm.id_medico', '=', 'c.id_medico')->where('c.id_paciente', $paciente)
            ->select(['consultas_medicas.id_consulta', 'consultas_medicas.codigo_consulta', 'consultas_medicas.id_cita',
                'c.inicio_at', 'm.id_medico', 'm.codigo_medico', 'm.nombres', 'm.apellidos'])
            ->selectSub(VersionConsulta::query()->select('tipo_consulta')
                ->whereColumn('versiones_consulta.id_consulta', 'consultas_medicas.id_consulta')
                ->orderByDesc('numero_version')->limit(1), 'tipo_consulta')
            ->orderByDesc('c.inicio_at')->orderByDesc('consultas_medicas.id_consulta')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function consulta(string $cita, string $paciente): ?ConsultaMedica
    {
        return ConsultaMedica::query()->select(['id_consulta', 'codigo_consulta', 'id_cita', 'iniciada_en', 'creada_en'])
            ->where('id_cita', $cita)->whereHas('cita', fn (Builder $q) => $q->where('id_paciente', $paciente))->first();
    }

    private function versionesConsulta(string $consulta, string $paciente, bool $interno): Builder
    {
        return VersionConsulta::query()->where('id_consulta', $consulta)
            ->whereHas('consulta.cita', fn (Builder $q) => $q->where('id_paciente', $paciente))
            ->select(['id_version_consulta', 'id_consulta', 'numero_version', 'registrada_en', 'tipo_consulta',
                'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento',
                ...($interno ? ['id_autor', 'motivo_cambio'] : [])]);
    }

    public function versionConsulta(string $consulta, string $paciente, bool $interno): ?VersionConsulta
    {
        return $this->versionesConsulta($consulta, $paciente, $interno)->orderByDesc('numero_version')->first();
    }

    public function historialConsulta(string $consulta, string $paciente, array $filtros): LengthAwarePaginator
    {
        return $this->versionesConsulta($consulta, $paciente, true)->orderByDesc('numero_version')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    private function versionesAntecedentes(string $paciente, bool $interno): Builder
    {
        return VersionAntecedente::query()->where('id_paciente', $paciente)
            ->whereHas('consultaOrigen.cita', fn (Builder $q) => $q->where('id_paciente', $paciente))
            ->select(['id_version_antecedente', 'id_paciente', 'id_consulta_origen', 'numero_version', 'registrada_en',
                'tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes',
                ...($interno ? ['id_autor', 'motivo_cambio'] : [])]);
    }

    public function antecedentes(string $paciente, bool $interno): ?VersionAntecedente
    {
        // Si el origen de la mayor versión es incoherente, no presentar otra anterior como vigente.
        return $this->versionesAntecedentes($paciente, $interno)->where('numero_version',
            VersionAntecedente::query()->selectRaw('MAX(numero_version)')->where('id_paciente', $paciente))->first();
    }

    public function historialAntecedentes(string $paciente, array $filtros): LengthAwarePaginator
    {
        return $this->versionesAntecedentes($paciente, true)->orderByDesc('numero_version')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function estudios(string $consulta, string $paciente, array $filtros): LengthAwarePaginator
    {
        return DB::table('solicitudes_laboratorio as s')->join('consultas_medicas as cm', 'cm.id_consulta', '=', 's.id_consulta')
            ->join('citas as c', 'c.id_cita', '=', 'cm.id_cita')->where('s.id_consulta', $consulta)->where('c.id_paciente', $paciente)
            ->leftJoin('versiones_resultado as v', function ($join): void {
                $join->on('v.id_version_resultado', '=', 's.id_version_actual')->on('v.id_solicitud', '=', 's.id_solicitud');
            })->leftJoin('revisiones_resultado as r', function ($join): void {
                $join->on('r.id_revision', '=', 's.id_revision_publicada')->on('r.id_version_resultado', '=', 'v.id_version_resultado');
            })->select(['s.id_solicitud', 's.estudio_solicitado', 's.solicitada_en', 's.estado_interno',
                'v.numero_version', 'r.decision', 'r.revisada_en', 'r.id_medico as id_revisor', 'c.id_medico as id_responsable'])
            ->selectSub(DB::table('versiones_resultado as ult')->selectRaw('MAX(ult.numero_version)')
                ->whereColumn('ult.id_solicitud', 's.id_solicitud'), 'ultima_version')
            ->orderByDesc('s.solicitada_en')->orderByDesc('s.id_solicitud')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }
}
