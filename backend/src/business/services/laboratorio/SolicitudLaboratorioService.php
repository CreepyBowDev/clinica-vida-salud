<?php

namespace Clinica\business\services\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\laboratorio\SolicitudLaboratorioRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class SolicitudLaboratorioService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private HistoriaClinicaRepository $historias,
        private ConsultaMedicaRepository $consultas,
        private SolicitudLaboratorioRepository $solicitudes,
        private ResultadoLaboratorioService $lectura,
        private BitacoraRepository $bitacora,
    ) {}

    public function crear(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $identidad = $this->lectura->identidad($actor);
            if (! $identidad['interno']) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $refConsulta = $this->solicitudes->referenciaConsulta($id);
            $ref = $refConsulta === null ? null : $this->historias->referenciaCita((string) $refConsulta->id_cita);
            if ($ref === null || ($identidad['medico'] !== null && $identidad['medico'] !== (string) $ref->id_medico)) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $this->medicos->porIdBloqueado((string) $ref->id_medico);
            $paciente = $this->historias->paciente((string) $ref->id_paciente)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $cita = $this->historias->cita((string) $ref->getKey(), (string) $paciente->getKey());
            $consulta = $this->consultas->porCitaBloqueada((string) $ref->getKey(), (string) $paciente->getKey());
            if ($cita === null || (string) $cita->id_medico !== (string) $ref->id_medico || $consulta === null || (string) $consulta->getKey() !== $id) {
                throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la atención.');
            }
            $version = $this->historias->versionConsulta($id, (string) $paciente->getKey(), true);
            if ($this->lectura->identidad($this->autenticacion->exigirSesionNormal(bloquear: true)) !== $identidad) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            if ($version === null || (string) $version->getKey() !== (string) $datos['originales']['id_version_consulta']) {
                throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la atención.');
            }
            if (($datos['atencion_real_verificada'] ?? null) !== true) {
                throw new AppError('LAB_CORRESPONDENCIA_NO_VERIFICADA', 'Comprueba que la atención real fue iniciada o realizada.');
            }
            $ahora = CarbonImmutable::now('UTC');
            if (! in_array($cita->estado, ['Programada', 'Confirmada', 'Atendida'], true) || $ahora->lessThan($cita->inicio_at)) {
                throw new AppError('LAB_ESTADO_NO_PERMITIDO', 'La atención no admite solicitar estudios en este momento.');
            }
            $solicitud = $this->solicitudes->crear($id, $identidad['usuario'], $datos['estudio_solicitado'], $ahora);
            $this->bitacora->estudioSolicitado($cita, $solicitud, $actor, $contexto);

            return $this->lectura->consultar((string) $solicitud->getKey());
        });
    }
}
