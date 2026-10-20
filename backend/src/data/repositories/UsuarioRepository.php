<?php

namespace Clinica\data\repositories;

use Carbon\CarbonImmutable;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class UsuarioRepository
{
    public function porCorreoBloqueado(string $correo): ?Usuario
    {
        return Usuario::query()->whereRaw('lower(btrim(correo_acceso)) = ?', [$correo])
            ->lockForUpdate()->first()?->load(['rol', 'paciente', 'medico']);
    }

    public function porId(int|string $id, bool $bloquear = false): ?Usuario
    {
        $query = Usuario::query()->whereKey($id);
        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first()?->load(['rol', 'paciente', 'medico']);
    }

    public function guardar(Usuario $usuario): void
    {
        $usuario->saveOrFail();
    }

    public function rolPorNombre(string $nombre): ?Rol
    {
        return Rol::query()->where('nombre', $nombre)->first();
    }

    public function crearPersonal(array $datos): Usuario
    {
        $usuario = new Usuario;
        $usuario->forceFill($datos)->saveOrFail();

        return $usuario->load(['rol', 'paciente', 'medico']);
    }

    public function crearPaciente(int|string $rol, string $correo, string $hash, CarbonImmutable $ahora): Usuario
    {
        $usuario = new Usuario;
        $usuario->forceFill(['id_rol' => $rol, 'correo_acceso' => $correo, 'password_hash' => $hash,
            'estado_cuenta' => 'Activa', 'correo_verificado_en' => $ahora])->saveOrFail();

        return $usuario->load('rol');
    }

    public function coordinarAdministracion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La coordinación administrativa requiere una transacción.');
        }
        // Clave reservada a continuidad administrativa; siempre ANTES de bloquear actores/objetivos.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [736201, 1]);
    }

    public function existeAdministradorNormal(): bool
    {
        return Usuario::query()->whereHas('rol', fn (Builder $rol) => $rol->where('nombre', 'Administrador'))
            ->where('estado_cuenta', 'Activa')->whereNotNull('correo_verificado_en')->whereNotNull('password_hash')
            ->where('cambio_clave_obligatorio', false)
            ->whereRaw('char_length(btrim(nombres_personal)) > 0 AND char_length(btrim(apellidos_personal)) > 0')
            ->exists();
    }

    public function listadoAdministrativo(array $filtros): LengthAwarePaginator
    {
        return Usuario::query()->with(['rol', 'medico'])
            ->where(function (Builder $query): void {
                $query->whereHas('rol', fn (Builder $rol) => $rol->whereIn('nombre', ['Administrador', 'Recepcionista', 'Médico']))
                    ->orWhere(fn (Builder $paciente) => $paciente->whereHas('rol', fn (Builder $rol) => $rol->where('nombre', 'Paciente'))
                        ->whereNotNull('correo_verificado_en')->whereHas('paciente'));
            })
            ->when(isset($filtros['rol']), fn (Builder $q) => $q->whereHas('rol', fn (Builder $r) => $r->where('nombre', $filtros['rol'])))
            ->when(isset($filtros['estado_cuenta']), fn (Builder $q) => $q->where('estado_cuenta', $filtros['estado_cuenta']))
            ->when(isset($filtros['correo_acceso']), fn (Builder $q) => $q->whereRaw('lower(btrim(correo_acceso)) = ?', [$filtros['correo_acceso']]))
            ->orderByDesc('id_usuario')->paginate($filtros['per_page'] ?? 20, ['*'], 'page', $filtros['page'] ?? 1);
    }
}
