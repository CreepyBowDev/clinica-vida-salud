<?php

namespace Clinica\business\services\consultorios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\consultorios\ConsultorioRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ConsultorioService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private ConsultorioRepository $consultorios,
        private BitacoraRepository $bitacora,
    ) {}

    private function administrador(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    private function consultorio(int|string $id): Consultorio
    {
        return $this->consultorios->porIdBloqueado($id)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el consultorio solicitado.');
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->administrador();

            return $this->consultorios->listar($filtros);
        });
    }

    public function consultar(int|string $id): Consultorio
    {
        return DB::transaction(function () use ($id): Consultorio {
            $this->administrador();
            $this->planificacion->coordinar();
            $consultorio = $this->consultorio($id);

            return $this->consultorios->resumen($consultorio, CarbonImmutable::now('UTC'));
        });
    }

    public function crear(array $datos, array $contexto): Consultorio
    {
        try {
            return DB::transaction(function () use ($datos, $contexto): Consultorio {
                $actor = $this->administrador();
                $this->planificacion->coordinar();
                $consultorio = $this->consultorios->crear($datos);
                $this->bitacora->consultorioModificado($consultorio, $actor, $contexto, 'CONSULTORIO_CREADO', ['codigo_consultorio', 'nombre', 'activo']);

                return $consultorio;
            });
        } catch (QueryException $error) {
            $this->rechazarDuplicado($error);
        }
    }

    public function actualizar(int|string $id, array $datos, array $contexto, bool $estado = false): Consultorio
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto, $estado): Consultorio {
                $actor = $this->administrador();
                $this->planificacion->coordinar();
                $consultorio = $this->consultorio($id);
                if (! $consultorio->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                    throw new AppError('CONSULTORIO_EDICION_OBSOLETA', 'El consultorio cambió desde que lo consultaste. Vuelve a cargarlo y revisa los cambios.');
                }
                if ($estado && ! (bool) $datos['activo']) {
                    $this->consultorios->resumen($consultorio, CarbonImmutable::now('UTC'));
                    if ($consultorio->medicos_count > 0) {
                        throw new AppError('CONSULTORIO_CON_ASIGNACIONES', 'El consultorio tiene médicos asignados. Resuelve las asignaciones antes de desactivarlo.');
                    }
                    if ($consultorio->obligaciones_count > 0) {
                        throw new AppError('CONSULTORIO_CON_OBLIGACIONES', 'El consultorio tiene citas vigentes. Resuélvelas mediante las operaciones autorizadas antes de desactivarlo.');
                    }
                }
                foreach ($estado ? ['activo'] : ['codigo_consultorio', 'nombre'] as $campo) {
                    if (array_key_exists($campo, $datos)) {
                        $consultorio->setAttribute($campo, $datos[$campo]);
                    }
                }
                $campos = array_keys($consultorio->getDirty());
                if ($campos !== []) {
                    $this->consultorios->guardar($consultorio);
                    $this->bitacora->consultorioModificado($consultorio, $actor, $contexto,
                        $estado ? 'CONSULTORIO_ESTADO_CAMBIADO' : 'CONSULTORIO_ACTUALIZADO', $campos);
                }

                return $consultorio;
            });
        } catch (QueryException $error) {
            $this->rechazarDuplicado($error);
        }
    }

    private function rechazarDuplicado(QueryException $error): never
    {
        if ($error->getCode() === '23505' && str_contains($error->errorInfo[2] ?? '', 'consultorios_codigo_uq')) {
            throw new AppError('CONSULTORIO_CODIGO_DUPLICADO', 'Ya existe un consultorio con ese código.');
        }
        throw $error;
    }
}
