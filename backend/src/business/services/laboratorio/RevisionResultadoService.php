<?php

namespace Clinica\business\services\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\laboratorio\DocumentoLaboratorioRepository;
use Clinica\data\repositories\laboratorio\ResultadoLaboratorioRepository;
use Clinica\data\repositories\laboratorio\SolicitudLaboratorioRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ArchivoLaboratorio;
use Illuminate\Support\Facades\DB;

class RevisionResultadoService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private HistoriaClinicaRepository $historias,
        private ConsultaMedicaRepository $consultas,
        private SolicitudLaboratorioRepository $solicitudes,
        private ResultadoLaboratorioRepository $resultados,
        private DocumentoLaboratorioRepository $documentos,
        private ResultadoLaboratorioService $lectura,
        private ArchivoLaboratorio $archivos,
        private BitacoraRepository $bitacora,
    ) {}

    public function revisar(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $identidad = $this->lectura->identidad($actor);
            if (! $identidad['interno']) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $ref = $this->solicitudes->contextos([$id])->first();
            if ($ref === null || ($identidad['medico'] !== null && $identidad['medico'] !== (string) $ref->id_medico)) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $this->medicos->porIdBloqueado((string) $ref->id_medico);
            $paciente = $this->historias->paciente((string) $ref->id_paciente);
            $cita = $this->historias->cita((string) $ref->id_cita, (string) $ref->id_paciente);
            $consulta = $this->consultas->porCitaBloqueada((string) $ref->id_cita, (string) $ref->id_paciente);
            $solicitud = $this->solicitudes->bloquearOrdenadas([$id])->first();
            if ($paciente === null || $cita === null || (string) $cita->id_medico !== (string) $ref->id_medico
                || $consulta === null || (string) $consulta->getKey() !== (string) $ref->id_consulta
                || $solicitud === null || (string) $solicitud->id_consulta !== (string) $ref->id_consulta) {
                throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la solicitud.');
            }
            $ctx = $this->lectura->contextoDocumental($solicitud, (string) $ref->id_medico);
            foreach ($ctx['originales'] as $campo => $valor) {
                $original = $datos['originales'][$campo];
                if (($valor === null) !== ($original === null) || ($valor !== null && (string) $valor !== (string) $original)) {
                    throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la solicitud.');
                }
            }
            if (! $ctx['acciones']['revisar']) {
                throw new AppError('LAB_ESTADO_NO_PERMITIDO', 'La solicitud no admite revisión. Comprueba su versión y estado actual.');
            }
            if (($datos['correspondencia_verificada'] ?? null) !== true) {
                throw new AppError('LAB_CORRESPONDENCIA_NO_VERIFICADA', 'Comprueba la legibilidad y correspondencia del documento.');
            }
            $documento = $this->documentos->porId((string) $ctx['vigente']->id_documento)
                ?? throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
            $this->archivos->verificar($documento->ruta_privada, $documento->getAttributes());
            $ahora = CarbonImmutable::now('UTC');
            if ($ctx['revision'] !== null && $ahora->lessThan($ctx['revision']->revisada_en)) {
                $ahora = $ctx['revision']->revisada_en;
            }
            $revision = $this->resultados->agregarRevision((string) $ctx['vigente']->getKey(), (string) $ref->id_medico,
                $identidad['usuario'], $datos['decision'], $datos['decision'] === 'Devolver' ? $datos['motivo'] : null, $ahora);
            if ($datos['decision'] === 'Autorizar') {
                $this->solicitudes->publicar($solicitud, (string) $revision->getKey());
            }
            $this->bitacora->resultadoLaboratorioRevisado($actor, $contexto, $datos['decision'],
                ['id_solicitud' => $id, 'id_consulta' => (string) $ref->id_consulta, 'id_paciente' => (string) $ref->id_paciente,
                    'id_medico' => (string) $ref->id_medico, 'id_version_resultado' => (string) $ctx['vigente']->getKey(),
                    'id_revision' => (string) $revision->getKey()], $ahora);
            // También protege la respuesta y revocaciones producidas durante persistencia/auditoría.
            if ($this->lectura->identidad($this->autenticacion->exigirSesionNormal(bloquear: true)) !== $identidad) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }

            return $this->lectura->consultar($id);
        });
    }
}
