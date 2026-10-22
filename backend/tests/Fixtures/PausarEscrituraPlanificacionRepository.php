<?php

namespace Tests\Fixtures;

use Clinica\data\models\Cita;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PausarEscrituraPlanificacionRepository extends BitacoraRepository
{
    public function citaProgramada(Cita $cita, Usuario $actor, array $contexto): void
    {
        parent::citaProgramada($cita, $actor, $contexto);
        $this->pausar();
    }

    public function medicoModificado(Medico $medico, Usuario $actor, array $contexto, string $tipo, array $campos, array $habilitaciones = []): void
    {
        parent::medicoModificado($medico, $actor, $contexto, $tipo, $campos, $habilitaciones);
        $this->pausar();
    }

    public function especialidadModificada(Especialidad $especialidad, Usuario $actor, array $contexto, string $tipo, array $campos): void
    {
        parent::especialidadModificada($especialidad, $actor, $contexto, $tipo, $campos);
        $this->pausar();
    }

    public function pacienteModificado(Paciente $paciente, Usuario $actor, array $contexto, array $campos, ?string $motivo, bool $estado = false): void
    {
        parent::pacienteModificado($paciente, $actor, $contexto, $campos, $motivo, $estado);
        $this->pausar();
    }

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
