<?php

namespace Tests\Fixtures;

use Clinica\data\models\Bitacora;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

class PausarLecturaBitacoraRepository extends BitacoraRepository
{
    private function pausar(): void
    {
        echo "LEIDA\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'CONTINUAR') {
            throw new RuntimeException('Falta barrera de lectura de bitácora.');
        }
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        $resultado = parent::listar($filtros);
        $this->pausar();

        return $resultado;
    }

    public function porId(int|string $id): ?Bitacora
    {
        $resultado = parent::porId($id);
        $this->pausar();

        return $resultado;
    }
}
