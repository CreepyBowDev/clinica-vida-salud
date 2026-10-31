<?php

namespace Clinica\business\services\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\SolicitudLaboratorio;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\laboratorio\DocumentoLaboratorioRepository;
use Clinica\data\repositories\laboratorio\ResultadoLaboratorioRepository;
use Clinica\data\repositories\laboratorio\SolicitudLaboratorioRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ArchivoLaboratorio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResultadoLaboratorioService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private HistoriaClinicaRepository $historias,
        private SolicitudLaboratorioRepository $solicitudes,
        private ResultadoLaboratorioRepository $resultados,
        private DocumentoLaboratorioRepository $documentos,
        private ArchivoLaboratorio $archivos,
    ) {}

    public function identidad(Usuario $actor): array
    {
        $rol = $actor->rol->nombre;
        if (! in_array($rol, ['Administrador', 'Médico', 'Paciente'], true)
            || ($rol === 'Médico' && ($actor->medico === null || $actor->paciente !== null || (string) $actor->medico->id_usuario !== (string) $actor->getKey()))
            || ($rol === 'Paciente' && ($actor->paciente === null || $actor->medico !== null || (string) $actor->paciente->id_usuario !== (string) $actor->getKey()))) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return ['usuario' => (string) $actor->getKey(), 'rol' => $rol, 'interno' => $rol !== 'Paciente',
            'medico' => $rol === 'Médico' ? (string) $actor->medico->getKey() : null,
            'paciente' => $rol === 'Paciente' ? (string) $actor->paciente->getKey() : null];
    }

    public function listar(array $filtros): array
    {
        return $this->leer($filtros);
    }

    public function porConsulta(string $consulta, array $filtros): array
    {
        return $this->leer([...$filtros, 'id_consulta' => $consulta], porConsulta: true);
    }

    public function consultar(string $id): array
    {
        return $this->leer([], solicitud: $id);
    }

    public function contextoDocumental(SolicitudLaboratorio $solicitud, string $responsable): array
    {
        $ctx = $this->resultados->contexto($solicitud);
        $vigente = $ctx['vigente'];
        $ultima = $ctx['ultima'];
        $revision = $ctx['revision'];
        $coherente = $vigente !== null && (string) $vigente->id_solicitud === (string) $solicitud->getKey()
            && (string) $vigente->getKey() === (string) $ultima?->getKey();
        $devuelta = $coherente && $revision?->decision === 'Devolver' && (string) $revision->id_medico === $responsable;
        $vaciaReasociada = $solicitud->estado_interno === 'En revisión' && $solicitud->id_version_actual === null && $ultima !== null
            && $this->resultados->ultimaRevision((string) $ultima->getKey())?->decision === 'Devolver'
            && (string) $this->resultados->ultimaRevision((string) $ultima->getKey())?->id_medico === $responsable
            && $this->resultados->fueReasociada((string) $ultima->getKey(), (string) $solicitud->getKey());
        $cargar = $solicitud->id_revision_publicada === null && (($solicitud->estado_interno === 'Pendiente' && $ultima === null && $solicitud->id_version_actual === null) || $vaciaReasociada);
        $corregir = $solicitud->estado_interno === 'En revisión' && $solicitud->id_revision_publicada === null && $devuelta;

        $revisar = $coherente && $solicitud->estado_interno === 'En revisión'
            && $solicitud->id_revision_publicada === null && $revision === null;

        return [...$ctx, 'acciones' => ['cargar' => $cargar, 'corregir' => $corregir, 'reasociar' => $corregir, 'revisar' => $revisar]];
    }

    public function versiones(string $id, array $filtros): array
    {
        return $this->leer($filtros, solicitud: $id, historico: true);
    }

    public function archivo(string $id, string $version): array
    {
        return $this->leer([], solicitud: $id, archivoVersion: $version)['archivo'];
    }

    private function leer(array $filtros, ?string $solicitud = null, bool $porConsulta = false, bool $historico = false, ?string $archivoVersion = null): array
    {
        $abierto = null;
        try {
            return DB::transaction(function () use ($filtros, $solicitud, $porConsulta, $historico, $archivoVersion, &$abierto): array {
                $this->continuidad->coordinar();
                $identidad = $this->identidad($this->autenticacion->exigirSesionNormal(bloquear: true));
                if ($historico && ! $identidad['interno']) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                if ($identidad['rol'] === 'Administrador' && $solicitud === null && ! isset($filtros['id_paciente']) && ! isset($filtros['id_consulta'])) {
                    throw ValidationException::withMessages(['id_paciente' => ['Selecciona un paciente o una consulta.']]);
                }
                if ($identidad['rol'] === 'Paciente' && ($filtros['estado'] ?? null) === 'En revisión') {
                    throw ValidationException::withMessages(['estado' => ['Selecciona Pendiente o Disponible.']]);
                }
                $this->planificacion->coordinar();
                if ($solicitud !== null) {
                    $ref = $this->solicitudes->referenciaSolicitud($solicitud)
                        ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    $filtros['id_consulta'] = (string) $ref->id_consulta;
                }
                $consulta = isset($filtros['id_consulta']) ? $this->solicitudes->referenciaConsulta((string) $filtros['id_consulta']) : null;
                $referencia = $consulta === null ? null : $this->historias->referenciaCita((string) $consulta->id_cita);
                if (isset($filtros['id_consulta']) && ($consulta === null || $referencia === null)) {
                    throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                }
                if ($referencia !== null && (($identidad['medico'] !== null && $identidad['medico'] !== (string) $referencia->id_medico)
                    || ($identidad['paciente'] !== null && $identidad['paciente'] !== (string) $referencia->id_paciente)
                    || (isset($filtros['id_paciente']) && (string) $filtros['id_paciente'] !== (string) $referencia->id_paciente))) {
                    throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                }
                $ids = array_unique(array_filter([$identidad['medico'], $referencia === null ? null : (string) $referencia->id_medico]));
                usort($ids, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
                foreach ($ids as $medico) {
                    $this->medicos->porIdBloqueado($medico);
                }
                $pacienteId = $referencia === null ? ($filtros['id_paciente'] ?? $identidad['paciente']) : (string) $referencia->id_paciente;
                if ($pacienteId !== null) {
                    $paciente = $this->historias->paciente((string) $pacienteId);
                    if ($paciente === null || ($identidad['paciente'] !== null && $identidad['paciente'] !== (string) $paciente->getKey())
                        || ($identidad['medico'] !== null && ! $this->historias->relacionMedica($identidad['medico'], (string) $paciente->getKey()))) {
                        throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    }
                }
                $edicion = null;
                if ($referencia !== null) {
                    $cita = $this->historias->cita((string) $referencia->getKey(), (string) $referencia->id_paciente);
                    if ($cita === null || (string) $cita->id_medico !== (string) $referencia->id_medico) {
                        throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    }
                    if ($porConsulta && $identidad['interno']) {
                        $version = $this->historias->versionConsulta((string) $consulta->getKey(), (string) $cita->id_paciente, true);
                        $impedimentos = [];
                        if ($version === null) {
                            $impedimentos[] = 'LAB_EDICION_OBSOLETA';
                        }
                        if (! in_array($cita->estado, ['Programada', 'Confirmada', 'Atendida'], true) || CarbonImmutable::now('UTC')->lessThan($cita->inicio_at)) {
                            $impedimentos[] = 'LAB_ESTADO_NO_PERMITIDO';
                        }
                        $edicion = ['originales' => ['id_version_consulta' => $version === null ? null : (string) $version->getKey()],
                            'acciones' => ['solicitar' => $impedimentos === []], 'impedimentos' => $impedimentos];
                    }
                }
                $resultado = $solicitud === null ? ['paginacion' => $this->solicitudes->listar($identidad, $filtros)]
                    : ['solicitud' => $this->solicitudes->consultar($solicitud, $identidad)
                        ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.')];
                if ($archivoVersion !== null) {
                    // No revelar archivos/versiones privados al titular antes de publicación.
                    if (! $identidad['interno'] && ! $resultado['solicitud']->publicado) {
                        throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    }
                    $modelo = $this->solicitudes->bloquearOrdenadas([$solicitud])->first();
                    $ctx = $this->contextoDocumental($modelo, (string) $referencia->id_medico);
                    $v = $ctx['vigente'];
                    $r = $ctx['revision'];
                    if ($v === null || (string) $v->getKey() !== $archivoVersion
                        || (string) $v->id_solicitud !== $solicitud || (string) $v->getKey() !== (string) $ctx['ultima']?->getKey()) {
                        throw new AppError('LAB_EDICION_OBSOLETA', 'La versión ya no está disponible. Vuelve a consultar la solicitud.');
                    }
                    $publicado = $modelo->estado_interno === 'Disponible' && $r?->decision === 'Autorizar'
                        && (string) $r->id_medico === (string) $referencia->id_medico
                        && (string) $r->getKey() === (string) $modelo->id_revision_publicada;
                    if (! $identidad['interno'] && ! $publicado) {
                        throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    }
                    if (($modelo->estado_interno === 'Disponible' && ! $publicado)
                        || ($modelo->estado_interno !== 'Disponible' && ($modelo->estado_interno !== 'En revisión' || $modelo->id_revision_publicada !== null))) {
                        throw new AppError('LAB_ESTADO_NO_PERMITIDO', 'El resultado no está disponible para esta operación.');
                    }
                    $documento = $this->documentos->porId((string) $v->id_documento)
                        ?? throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
                    $abierto = $this->archivos->abrir($documento->ruta_privada, $documento->getAttributes());
                    $resultado = ['archivo' => $abierto];
                } elseif ($historico) {
                    $resultado = ['versiones' => $this->resultados->versiones($solicitud, $filtros), 'administrativo' => $identidad['rol'] === 'Administrador'];
                } elseif ($solicitud !== null && $identidad['interno']) {
                    $modelo = $this->solicitudes->bloquearOrdenadas([$solicitud])->first();
                    $ctx = $this->contextoDocumental($modelo, (string) $referencia->id_medico);
                    $v = $ctx['vigente'];
                    $r = $ctx['revision'];
                    $acciones = $identidad['rol'] === 'Administrador' ? $ctx['acciones'] : ['revisar' => $ctx['acciones']['revisar']];
                    $resultado['documentacion'] = ['originales' => $ctx['originales'], 'acciones' => $acciones,
                        'vigente' => $v === null ? null : ['id_version_resultado' => (string) $v->getKey(), 'numero_version' => $v->numero_version],
                        'ultima_revision' => $r === null ? null : ['id_revision' => (string) $r->getKey(), 'decision' => $r->decision, 'motivo' => $r->motivo,
                            'id_medico_responsable' => (string) $r->id_medico,
                            'revisada_por' => $r->revisada_por === null ? null : (string) $r->revisada_por,
                            'revisada_en' => $r->revisada_en->utc()->format('Y-m-d\TH:i:s.u\Z')]];
                }
                if ($this->identidad($this->autenticacion->exigirSesionNormal(bloquear: true)) !== $identidad) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }

                return [...$resultado, 'interno' => $identidad['interno'], 'id_consulta' => $porConsulta ? (string) $consulta->getKey() : null, 'edicion' => $edicion];
            });
        } catch (Throwable $error) {
            if ($abierto !== null && is_resource($abierto['flujo'])) {
                fclose($abierto['flujo']);
            }
            throw $error;
        }
    }
}
