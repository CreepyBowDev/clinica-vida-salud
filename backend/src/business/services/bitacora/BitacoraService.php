<?php

namespace Clinica\business\services\bitacora;

use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BitacoraService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private BitacoraRepository $bitacora,
    ) {}

    private function administrador(): void
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->administrador();

            return $this->bitacora->listar($filtros);
        });
    }

    public function consultar(int|string $id): Bitacora
    {
        return DB::transaction(function () use ($id): Bitacora {
            $this->administrador();

            return $this->bitacora->porId($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el evento solicitado.');
        });
    }
}
