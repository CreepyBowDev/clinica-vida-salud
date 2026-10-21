<?php

namespace Clinica\business\services\medicos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\medicos\EspecialidadRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class EspecialidadService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private EspecialidadRepository $especialidades,
        private BitacoraRepository $bitacora,
    ) {}

    private function administrador(): Usuario
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->administrador();

            return $this->especialidades->listar($filtros);
        });
    }

    public function consultar(int|string $id): Especialidad
    {
        return DB::transaction(function () use ($id): Especialidad {
            $this->administrador();

            return $this->especialidades->porIdBloqueado($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la especialidad solicitada.');
        });
    }

    public function crear(array $datos, array $contexto): Especialidad
    {
        try {
            return DB::transaction(function () use ($datos, $contexto): Especialidad {
                $actor = $this->administrador();
                $especialidad = $this->especialidades->crear($datos);
                $this->bitacora->especialidadModificada($especialidad, $actor, $contexto, 'ESPECIALIDAD_CREADA', ['nombre', 'descripcion', 'activa']);

                return $especialidad;
            });
        } catch (QueryException $error) {
            $this->rechazarDuplicado($error);
        }
    }

    public function actualizar(int|string $id, array $datos, array $contexto, bool $estado = false): Especialidad
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto, $estado): Especialidad {
                $actor = $this->administrador();
                $especialidad = $this->especialidades->porIdBloqueado($id)
                    ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la especialidad solicitada.');
                if (! $especialidad->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                    throw new AppError('ESPECIALIDAD_EDICION_OBSOLETA', 'La especialidad cambió desde que la consultaste. Vuelve a cargarla y revisa los cambios.');
                }
                // La entrada HTTP ya fue validada; la lista explícita también protege al Service.
                foreach ($estado ? ['activa'] : ['nombre', 'descripcion'] as $campo) {
                    if (array_key_exists($campo, $datos)) {
                        $especialidad->setAttribute($campo, $datos[$campo]);
                    }
                }
                $campos = array_keys($especialidad->getDirty());
                if ($campos !== []) {
                    $this->especialidades->guardar($especialidad);
                    $this->bitacora->especialidadModificada($especialidad, $actor, $contexto,
                        $estado ? 'ESPECIALIDAD_ESTADO_CAMBIADO' : 'ESPECIALIDAD_ACTUALIZADA', $campos);
                }

                return $especialidad;
            });
        } catch (QueryException $error) {
            $this->rechazarDuplicado($error);
        }
    }

    private function rechazarDuplicado(QueryException $error): never
    {
        // Solo la unicidad conocida del catálogo es un conflicto de nombre, no cualquier fallo SQL.
        if ($error->getCode() === '23505' && str_contains($error->errorInfo[2] ?? '', 'especialidades_nombre_normalizado_uq')) {
            throw new AppError('ESPECIALIDAD_NOMBRE_DUPLICADO', 'Ya existe una especialidad con ese nombre.');
        }
        throw $error;
    }
}
