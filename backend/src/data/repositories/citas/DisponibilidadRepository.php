<?php

namespace Clinica\data\repositories\citas;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Especialidad;
use Clinica\data\models\HorarioAtencion;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DisponibilidadRepository
{
    public function especialidades(array $filtros): LengthAwarePaginator
    {
        $query = Especialidad::query()->select(['id_especialidad', 'nombre'])->where('activa', true);
        if (isset($filtros['nombre'])) {
            $query->whereRaw('strpos(lower(nombre), lower(?)) > 0', [$filtros['nombre']]);
        }

        return $query->orderBy('nombre')->orderBy('id_especialidad')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1)
            ->through(fn ($e) => ['id_especialidad' => (string) $e->getKey(), 'nombre' => $e->nombre]);
    }

    public function medicos(array $filtros): LengthAwarePaginator
    {
        $query = Medico::query()->select(['id_medico', 'id_consultorio', 'codigo_medico', 'nombres', 'apellidos'])
            ->with('consultorio:id_consultorio,codigo_consultorio,nombre')
            ->where('activo', true)->whereHas('especialidades', fn ($q) => $q->where('especialidades.id_especialidad', $filtros['id_especialidad'])
            ->where('especialidades.activa', true)->where('medico_especialidad.habilitada', true))
            ->whereHas('horarios', fn ($q) => $q->where('activo', true))
            ->whereHas('consultorio', function ($q) use ($filtros): void {
                $q->where('activo', true);
                if (isset($filtros['consultorio'])) {
                    $q->whereRaw("strpos(lower(codigo_consultorio || ' ' || nombre), lower(?)) > 0", [$filtros['consultorio']]);
                }
            });
        if (isset($filtros['nombre'])) {
            $query->whereRaw("strpos(lower(nombres || ' ' || apellidos), lower(?)) > 0", [$filtros['nombre']]);
        }

        return $query->orderBy('apellidos')->orderBy('nombres')->orderBy('id_medico')
            ->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1)
            ->through(fn ($m) => ['id_medico' => (string) $m->getKey(), 'codigo_medico' => $m->codigo_medico,
                'nombres' => $m->nombres, 'apellidos' => $m->apellidos,
                'consultorio' => ['id_consultorio' => (string) $m->id_consultorio,
                    'codigo_consultorio' => $m->consultorio->codigo_consultorio, 'nombre' => $m->consultorio->nombre]]);
    }

    public function medico(string $id): ?Medico
    {
        return Medico::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function especialidad(string $id): ?Especialidad
    {
        return Especialidad::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function habilitado(string $medico, string $especialidad): bool
    {
        return (bool) DB::table('medico_especialidad')->where('id_medico', $medico)->where('id_especialidad', $especialidad)
            ->lockForUpdate()->value('habilitada');
    }

    public function consultorio(string $id): ?Consultorio
    {
        return Consultorio::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function horarios(string $medico, string $consultorio): Collection
    {
        return HorarioAtencion::query()->where('activo', true)->where(function ($q) use ($medico, $consultorio): void {
            $q->where('id_medico', $medico)->orWhereHas('medico', fn ($m) => $m->where('id_consultorio', $consultorio));
        })->orderBy('id_horario')->lockForUpdate()->get();
    }

    public function paciente(string $actor, ?string $id): ?Paciente
    {
        return Paciente::query()->select(['id_paciente', 'id_usuario', 'activo', 'codigo_paciente', 'nombres', 'apellidos'])
            ->when($id === null, fn ($q) => $q->where('id_usuario', $actor), fn ($q) => $q->whereKey($id))
            ->lockForUpdate()->first();
    }

    public function ocupacion(string $medico, string $consultorio, string $paciente, CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        return Cita::query()->select(['id_cita', 'inicio_at', 'fin_at'])
            ->where('inicio_at', '<', $hasta->format('Y-m-d H:i:s.uP'))->where('fin_at', '>', $desde->format('Y-m-d H:i:s.uP'))
            ->where(function ($q) use ($medico, $consultorio, $paciente): void {
                $q->where(fn ($r) => $r->where('estado', '<>', 'Cancelada')
                    ->where(fn ($s) => $s->where('id_medico', $medico)->orWhere('id_consultorio', $consultorio)))
                    ->orWhere(fn ($r) => $r->where('id_paciente', $paciente)->whereIn('estado', ['Programada', 'Confirmada']));
            })->orderBy('id_cita')->lockForUpdate()->get();
    }
}
