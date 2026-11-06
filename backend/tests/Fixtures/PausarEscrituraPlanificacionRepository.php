<?php

namespace Tests\Fixtures;

use Clinica\data\models\Cita;
use Clinica\data\models\ConsultaMedica;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\models\VersionAntecedente;
use Clinica\data\models\VersionConsulta;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PausarEscrituraPlanificacionRepository extends BitacoraRepository
{
    public function obligacionEmitida(Cita $cita, ObligacionPago $obligacion, Usuario $actor, array $contexto): void
    {
        parent::obligacionEmitida($cita, $obligacion, $actor, $contexto);
        $this->pausar();
    }

    private bool $clinicaPausada = false;

    private function pausarClinica(): void
    {
        // Ambos snapshots ya se persistieron antes del primer evento; solo una barrera por operación.
        if (! $this->clinicaPausada) {
            $this->clinicaPausada = true;
            $this->pausar();
        }
    }

    public function consultaRegistrada(Cita $cita, ConsultaMedica $consulta, VersionConsulta $version, Usuario $actor, array $contexto): void
    {
        parent::consultaRegistrada($cita, $consulta, $version, $actor, $contexto);
        $this->pausarClinica();
    }

    public function consultaActualizada(Cita $cita, ConsultaMedica $consulta, VersionConsulta $version, VersionConsulta $anterior, Usuario $actor, array $contexto): void
    {
        parent::consultaActualizada($cita, $consulta, $version, $anterior, $actor, $contexto);
        $this->pausarClinica();
    }

    public function antecedentesActualizados(Cita $cita, ConsultaMedica $consulta, VersionAntecedente $version, ?VersionAntecedente $anterior, Usuario $actor, array $contexto): void
    {
        parent::antecedentesActualizados($cita, $consulta, $version, $anterior, $actor, $contexto);
        $this->pausarClinica();
    }

    public function citaLlegadaConfirmada(Cita $cita, Usuario $actor, array $contexto, array $antes): void
    {
        parent::citaLlegadaConfirmada($cita, $actor, $contexto, $antes);
        $this->pausar();
    }

    public function citaInasistenciaRegistrada(Cita $cita, Usuario $actor, array $contexto, array $antes): void
    {
        parent::citaInasistenciaRegistrada($cita, $actor, $contexto, $antes);
        $this->pausar();
    }

    public function citaAtendida(Cita $cita, Usuario $actor, array $contexto, array $antes): void
    {
        parent::citaAtendida($cita, $actor, $contexto, $antes);
        $this->pausar();
    }

    public function citaReprogramada(Cita $cita, Usuario $actor, array $contexto, array $antes, ?array $finanzas = null): void
    {
        parent::citaReprogramada($cita, $actor, $contexto, $antes, $finanzas);
        $this->pausar();
    }

    public function citaCancelada(Cita $cita, Usuario $actor, array $contexto, array $antes, ?array $finanzas = null): void
    {
        parent::citaCancelada($cita, $actor, $contexto, $antes, $finanzas);
        $this->pausar();
    }

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
