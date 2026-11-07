<?php

namespace Clinica\data\repositories\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\Recordatorio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RecordatorioRepository
{
    public function invalidarPorCita(string $id, CarbonImmutable $ahora): void
    {
        Recordatorio::query()->where('id_cita', $id)->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }

    public function ultimoIdCita(): string
    {
        return (string) (DB::table('citas')->max('id_cita') ?? '0');
    }

    public function candidatas(string $despues, string $hasta, int $limite, CarbonImmutable $ahora): array
    {
        return DB::table('citas as c')->where('c.id_cita', '>', $despues)->where('c.id_cita', '<=', $hasta)
            ->where(function ($q) use ($ahora): void {
                $q->where(function ($v) use ($ahora): void {
                    $v->whereIn('c.estado', ['Programada', 'Confirmada'])
                        ->where('c.inicio_at', '<=', $ahora->addHours(24)->format('Y-m-d H:i:s.uP'))
                        ->where('c.fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'));
                })->orWhereExists(function ($v): void {
                    $v->selectRaw('1')->from('recordatorios as r')->whereColumn('r.id_cita', 'c.id_cita')->whereNull('r.invalidado_en');
                })->orWhereExists(function ($v): void {
                    $v->selectRaw('1')->from('envios_recordatorio_push as e')->join('recordatorios as r', 'r.id_recordatorio', '=', 'e.id_recordatorio')
                        ->whereColumn('r.id_cita', 'c.id_cita')->where('e.estado', 'Pendiente');
                });
            })->orderBy('c.id_cita')->limit($limite)->pluck('c.id_cita')->map(fn ($id) => (string) $id)->all();
    }

    public function localizar(string $id): ?object
    {
        return DB::table('citas as c')->join('pacientes as p', 'p.id_paciente', '=', 'c.id_paciente')
            ->where('c.id_cita', $id)->select(['c.id_paciente', 'p.id_usuario'])->first();
    }

    public function retirarObsoletos(Cita $cita, CarbonImmutable $ahora): int
    {
        $q = Recordatorio::query()->where('id_cita', $cita->getKey())->whereNull('invalidado_en');
        if (in_array($cita->estado, ['Programada', 'Confirmada'], true) && $ahora->isBefore($cita->fin_at)) {
            $q->where('version_programacion', '<>', $cita->version_programacion);
        }

        return $q->update(['invalidado_en' => $ahora]);
    }

    public function porProgramacion(Cita $cita): ?Recordatorio
    {
        return Recordatorio::query()->where('id_cita', $cita->getKey())
            ->where('version_programacion', $cita->version_programacion)->where('origen', 'Automatico')->lockForUpdate()->first();
    }

    public function proxima(string $paciente, CarbonImmutable $ahora): ?object
    {
        return DB::table('citas as c')->join('medicos as m', 'm.id_medico', '=', 'c.id_medico')
            ->where('c.id_paciente', $paciente)->whereIn('c.estado', ['Programada', 'Confirmada'])
            ->where('c.inicio_at', '>', $ahora->format('Y-m-d H:i:s.uP'))
            ->orderBy('c.inicio_at')->orderBy('c.id_cita')
            ->select(['c.id_cita', 'c.id_paciente', 'c.version_programacion', 'c.actualizada_en', 'c.inicio_at', 'c.fin_at', 'c.estado',
                'c.id_medico', 'c.id_consultorio', 'c.consultorio_atencion', 'c.sede_atencion',
                'm.nombres as medico_nombres', 'm.apellidos as medico_apellidos'])->first();
    }

    public function manual(string $cita, int $version): ?Recordatorio
    {
        return Recordatorio::query()->where('id_cita', $cita)->where('version_programacion', $version)
            ->where('origen', 'Manual')->lockForUpdate()->first();
    }

    public function porOperacion(string $operacion): ?Recordatorio
    {
        return Recordatorio::query()->where('operacion_manual', $operacion)->lockForUpdate()->first();
    }

    public function guardarResultadoManual(Recordatorio $aviso, string $resultado): void
    {
        $aviso->forceFill(['resultado_push' => $resultado])->saveOrFail();
    }

    public function porId(string $id): ?Recordatorio
    {
        return Recordatorio::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function crear(Cita $cita, CarbonImmutable $ahora, array $manual = []): Recordatorio
    {
        $r = new Recordatorio;
        $r->forceFill(['id_cita' => $cita->getKey(), 'version_programacion' => $cita->version_programacion,
            'generado_en' => $ahora, ...$manual])->saveOrFail();

        return $r;
    }

    public function pacienteParaConsulta(string $id): ?object
    {
        return DB::table('pacientes')->select(['id_paciente', 'id_usuario', 'codigo_paciente', 'nombres', 'apellidos'])
            ->where('id_paciente', $id)->lockForUpdate()->first();
    }

    public function listarVigentes(string $paciente, CarbonImmutable $ahora, array $filtros): LengthAwarePaginator
    {
        $q = DB::table('recordatorios as r')->join('citas as c', 'c.id_cita', '=', 'r.id_cita')
            ->join('medicos as m', 'm.id_medico', '=', 'c.id_medico')
            ->join('especialidades as e', 'e.id_especialidad', '=', 'c.id_especialidad')
            ->leftJoin('preferencias_paciente as p', 'p.id_paciente', '=', 'c.id_paciente')
            ->where('c.id_paciente', $paciente)->whereColumn('r.version_programacion', 'c.version_programacion')
            ->whereNull('r.invalidado_en')->where('r.generado_en', '<=', $ahora->format('Y-m-d H:i:s.uP'))
            ->whereIn('c.estado', ['Programada', 'Confirmada'])
            ->where(fn ($q) => $q->where('r.origen', 'Manual')
                ->orWhere('c.inicio_at', '<=', $ahora->addHours(24)->format('Y-m-d H:i:s.uP')))
            ->where('c.fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'))
            ->where(fn ($v) => $v->whereNull('p.id_preferencia')->orWhere('p.recordatorios_habilitados', true))
            ->select(['r.id_recordatorio', 'r.id_cita', 'r.version_programacion', 'r.generado_en',
                'c.codigo_cita', 'c.inicio_at', 'c.fin_at', 'c.estado', 'c.modalidad', 'c.sede_atencion', 'c.consultorio_atencion',
                'c.id_consultorio', 'c.id_medico', 'm.codigo_medico', 'm.nombres as medico_nombres', 'm.apellidos as medico_apellidos',
                'c.id_especialidad', 'e.nombre as especialidad_nombre']);
        if (isset($filtros['fecha_desde'])) {
            $q->where('c.inicio_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_desde'], 'America/La_Paz')->utc()->format('Y-m-d H:i:s.uP'));
        }
        if (isset($filtros['fecha_hasta'])) {
            $q->where('c.inicio_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_hasta'], 'America/La_Paz')->addDay()->utc()->format('Y-m-d H:i:s.uP'));
        }

        return $q->orderBy('c.inicio_at')->orderBy('r.id_recordatorio')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }
}
