<?php

namespace Clinica\data\repositories\medicos;

use Carbon\CarbonImmutable;
use Clinica\data\models\Medico;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MedicoRepository
{
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Medico::query()->with(['especialidades' => fn ($q) => $q->where('medico_especialidad.habilitada', true)]);
        if (isset($filtros['nombre'])) {
            $query->whereRaw("strpos(lower(nombres || ' ' || apellidos), lower(?)) > 0", [$filtros['nombre']]);
        }
        if (array_key_exists('activo', $filtros)) {
            $query->where('activo', (bool) $filtros['activo']);
        }
        if (isset($filtros['id_especialidad'])) {
            $query->whereHas('especialidades', fn ($q) => $q->where('especialidades.id_especialidad', $filtros['id_especialidad'])
                ->where('medico_especialidad.habilitada', true));
        }

        return $query->orderByDesc('id_medico')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function crear(array $datos, array $especialidades): Medico
    {
        $medico = new Medico;
        $medico->forceFill([...$datos, 'codigo_medico' => 'MED-'.bin2hex(random_bytes(16)),
            'activo' => true, 'actualizado_en' => CarbonImmutable::now('UTC')])->saveOrFail();
        $medico->especialidades()->attach($especialidades);

        return $medico->load('especialidades');
    }

    public function guardar(Medico $medico): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $medico->actualizado_en = $ahora->greaterThan($medico->actualizado_en)
            ? $ahora : $medico->actualizado_en->addMicrosecond();
        $medico->saveOrFail();
    }

    public function cargarEspecialidades(Medico $medico): Medico
    {
        return $medico->load(['especialidades' => fn ($q) => $q->orderBy('especialidades.id_especialidad')]);
    }

    public function porIdBloqueado(int|string $id): ?Medico
    {
        return Medico::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function pares(int|string $id, bool $bloquear = false): array
    {
        $query = DB::table('medico_especialidad')->where('id_medico', $id)->orderBy('id_especialidad');
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->pluck('habilitada', 'id_especialidad')->all();
    }

    public function tieneCitasFuturas(int|string $id, array $especialidades, CarbonImmutable $ahora): bool
    {
        return DB::table('citas')->where('id_medico', $id)->whereIn('id_especialidad', $especialidades)
            ->whereIn('estado', ['Programada', 'Confirmada'])->where('inicio_at', '>=', $ahora->format('Y-m-d H:i:s.uP'))->exists();
    }

    public function guardarHabilitaciones(Medico $medico, array $habilitar, array $retirar, array $existentes): void
    {
        foreach ($habilitar as $id) {
            if (array_key_exists($id, $existentes)) {
                DB::table('medico_especialidad')->where('id_medico', $medico->getKey())->where('id_especialidad', $id)->update(['habilitada' => true]);
            } else {
                DB::table('medico_especialidad')->insert(['id_medico' => $medico->getKey(), 'id_especialidad' => $id, 'habilitada' => true]);
            }
        }
        if ($retirar !== []) {
            DB::table('medico_especialidad')->where('id_medico', $medico->getKey())->whereIn('id_especialidad', $retirar)->update(['habilitada' => false]);
        }
    }

    public function vincular(Medico $medico, int|string $idUsuario): void
    {
        $medico->id_usuario = $idUsuario;
        $medico->saveOrFail();
    }

    public function tieneAtencionesSinCierreComprobable(int|string $id): bool
    {
        // Atendida no acredita cierre documental. Cancelada/Inasistencia sin consulta no generan atención.
        return DB::table('citas')->where('id_medico', $id)
            ->where(function ($q): void {
                $q->whereNotIn('estado', ['Cancelada', 'Inasistencia'])
                    ->orWhereExists(fn ($consulta) => $consulta->selectRaw('1')->from('consultas_medicas')
                        ->whereColumn('consultas_medicas.id_cita', 'citas.id_cita'));
            })->exists();
    }
}
