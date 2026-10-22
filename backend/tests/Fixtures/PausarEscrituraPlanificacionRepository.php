<?php

namespace Tests\Fixtures;

use Clinica\data\models\Medico;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PausarEscrituraPlanificacionRepository extends BitacoraRepository
{
    public function planificacionModificada(Usuario $actor, array $contexto, string $tipo, array $referencias, array $cambios): void
    {
        parent::planificacionModificada($actor, $contexto, $tipo, $referencias, $cambios);
        $this->pausar();
    }

    public function asignacionConsultorio(Medico $medico, Usuario $actor, array $contexto, ?string $anterior, int $trasladadas): void
    {
        parent::asignacionConsultorio($medico, $actor, $contexto, $anterior, $trasladadas);
        $this->pausar();
    }

    private function pausar(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Falta transacción de planificación.');
        }
        echo "ESCRITURA_PENDIENTE\n";
        fflush(STDOUT);
        $decision = trim(fgets(STDIN));
        if ($decision === 'REVERTIR') {
            throw new RuntimeException('Fallo simulado después del evento de planificación.');
        }
        if ($decision !== 'CONFIRMAR') {
            throw new RuntimeException('Falta barrera de confirmación.');
        }
    }
}
