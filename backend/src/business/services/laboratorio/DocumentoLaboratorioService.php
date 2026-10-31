<?php

namespace Clinica\business\services\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\ArchivoLaboratorioValidator;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SplFileInfo;
use Throwable;

class DocumentoLaboratorioService
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
        private ArchivoLaboratorioValidator $validador,
        private ArchivoLaboratorio $archivos,
        private BitacoraRepository $bitacora,
    ) {}

    public function cargar(string $id, array $datos, SplFileInfo $archivo, string $nombre, array $contexto): array
    {
        return $this->guardar($id, $datos, $archivo, $nombre, $contexto, false);
    }

    public function corregir(string $id, array $datos, ?SplFileInfo $archivo, ?string $nombre, array $contexto): array
    {
        return $this->guardar($id, $datos, $archivo, $nombre, $contexto, true);
    }

    private function administrador(bool $bloquear = false): array
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: $bloquear);
        $identidad = $this->lectura->identidad($actor);
        if ($identidad['rol'] !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return [$actor, $identidad];
    }

    private function ordenar(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        usort($ids, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        return $ids;
    }

    private function originales(array $originales, array $actuales): void
    {
        if (($originales['estado_interno'] ?? null) !== $actuales['estado_interno']
            || ($originales['id_version_actual'] === null ? null : (string) $originales['id_version_actual']) !== $actuales['id_version_actual']
            || ($originales['id_ultima_revision'] === null ? null : (string) $originales['id_ultima_revision']) !== $actuales['id_ultima_revision']
            || (string) $originales['ultima_version'] !== (string) $actuales['ultima_version']) {
            throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar las solicitudes.');
        }
    }

    private function guardar(string $id, array $datos, ?SplFileInfo $archivo, ?string $nombre, array $contexto, bool $corregir): array
    {
        [, $identidad] = $this->administrador();
        $reasociar = $corregir && $datos['tipo'] === 'reasociar';
        $metadatos = $archivo === null ? null : $this->validador->validar($archivo, $nombre ?? '');
        $ruta = $metadatos === null ? null : $this->archivos->guardar($archivo, $metadatos);
        $terminoBloque = false;
        $nivelInicial = DB::transactionLevel();
        try {
            return DB::transaction(function () use ($id, $datos, $contexto, $corregir, $reasociar, $metadatos, $ruta, $identidad, &$terminoBloque): array {
                $this->continuidad->coordinar();
                [$actor, $actual] = $this->administrador(true);
                if ($actual !== $identidad) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $this->planificacion->coordinar();
                $destino = $reasociar ? (string) $datos['id_solicitud_destino'] : $id;
                if ($reasociar && $destino === $id) {
                    throw new AppError('LAB_DESTINO_NO_PERMITIDO', 'Selecciona otra solicitud pendiente como destino.');
                }
                $ids = $this->ordenar([$id, $destino]);
                $referencias = $this->solicitudes->contextos($ids);
                if ($referencias->count() !== count($ids)) {
                    throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                }
                foreach ($this->ordenar($referencias->pluck('id_medico')->all()) as $medico) {
                    $this->medicos->porIdBloqueado($medico);
                }
                foreach ($this->ordenar($referencias->pluck('id_paciente')->all()) as $paciente) {
                    if ($this->historias->paciente($paciente) === null) {
                        throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                    }
                }
                foreach ($this->ordenar($referencias->pluck('id_cita')->all()) as $citaId) {
                    $ref = $referencias->firstWhere('id_cita', $citaId);
                    $cita = $this->historias->cita($citaId, (string) $ref->id_paciente);
                    if ($cita === null || (string) $cita->id_medico !== (string) $ref->id_medico) {
                        throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar las solicitudes.');
                    }
                }
                foreach ($this->ordenar($referencias->pluck('id_consulta')->all()) as $consultaId) {
                    $ref = $referencias->firstWhere('id_consulta', $consultaId);
                    $consulta = $this->consultas->porCitaBloqueada((string) $ref->id_cita, (string) $ref->id_paciente);
                    if ($consulta === null || (string) $consulta->getKey() !== $consultaId) {
                        throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar las solicitudes.');
                    }
                }
                $solicitudes = $this->solicitudes->bloquearOrdenadas($ids)->keyBy('id_solicitud');
                foreach ($referencias as $ref) {
                    if (! isset($solicitudes[$ref->id_solicitud]) || (string) $solicitudes[$ref->id_solicitud]->id_consulta !== (string) $ref->id_consulta) {
                        throw new AppError('LAB_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar las solicitudes.');
                    }
                }
                $origen = $solicitudes[$id];
                $refOrigen = $referencias->firstWhere('id_solicitud', $id);
                $ctx = $this->lectura->contextoDocumental($origen, (string) $refOrigen->id_medico);
                $this->originales($datos['originales'], $ctx['originales']);
                if (! $ctx['acciones'][$corregir ? 'corregir' : 'cargar']) {
                    throw new AppError($corregir ? 'LAB_DEVOLUCION_REQUERIDA' : 'LAB_ESTADO_NO_PERMITIDO', 'La solicitud no admite esta operación. Consulta su estado actual.');
                }
                if (($datos['asociacion_verificada'] ?? null) !== '1' && ($datos['asociacion_verificada'] ?? null) !== 1) {
                    throw new AppError('LAB_CORRESPONDENCIA_NO_VERIFICADA', 'Comprueba el paciente y el estudio de la solicitud.');
                }
                $receptor = $origen;
                if ($reasociar) {
                    if (($datos['asociacion_destino_verificada'] ?? null) !== '1' && ($datos['asociacion_destino_verificada'] ?? null) !== 1) {
                        throw new AppError('LAB_CORRESPONDENCIA_NO_VERIFICADA', 'Comprueba el paciente y el estudio de destino.');
                    }
                    $receptor = $solicitudes[$destino];
                    $refDestino = $referencias->firstWhere('id_solicitud', $destino);
                    $ctxDestino = $this->lectura->contextoDocumental($receptor, (string) $refDestino->id_medico);
                    $this->originales($datos['originales_destino'], $ctxDestino['originales']);
                    if ($receptor->estado_interno !== 'Pendiente' || $ctxDestino['ultima'] !== null || $receptor->id_version_actual !== null || $receptor->id_revision_publicada !== null) {
                        throw new AppError('LAB_DESTINO_NO_PERMITIDO', 'El destino debe ser una solicitud pendiente sin documentos.');
                    }
                }
                $ultimo = $this->resultados->ultimaVersion((string) $receptor->getKey());
                if ($ultimo?->numero_version === 2147483647) {
                    throw new AppError('LAB_LIMITE_VERSION', 'No se puede agregar otra versión del resultado.');
                }
                $motivo = $datos['motivo_cambio'] ?? null;
                if (($corregir || $ctx['ultima'] !== null) && (! is_string($motivo) || trim($motivo) === '' || mb_strlen($motivo) > 2000)) {
                    throw ValidationException::withMessages(['motivo_cambio' => ['Indica un motivo de corrección de hasta 2000 caracteres.']]);
                }
                if ($this->administrador(true)[1] !== $identidad) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $ahora = CarbonImmutable::now('UTC');
                if ($reasociar) {
                    $documento = $this->documentos->porId((string) $ctx['vigente']->id_documento)
                        ?? throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
                    $this->archivos->verificar($documento->ruta_privada, $documento->getAttributes());
                } else {
                    if ($ruta === null || $metadatos === null) {
                        throw ValidationException::withMessages(['archivo' => ['Selecciona el documento.']]);
                    }
                    $this->archivos->verificar($ruta, $metadatos);
                    $documento = $this->documentos->crear($ruta, $metadatos, $identidad['usuario'], $ahora);
                }
                $anterior = $corregir ? $ctx['vigente'] : $ctx['ultima'];
                $version = $this->resultados->agregarVersion((string) $receptor->getKey(), (string) $documento->getKey(),
                    ($ultimo?->numero_version ?? 0) + 1, $anterior === null ? null : (string) $anterior->getKey(), $identidad['usuario'],
                    $corregir || $ctx['ultima'] !== null ? $motivo : null, $ahora);
                $this->solicitudes->asignarVersion($receptor, (string) $version->getKey());
                if ($reasociar) {
                    $this->solicitudes->asignarVersion($origen, null);
                }
                $this->bitacora->documentoLaboratorioRegistrado($actor, $contexto,
                    $reasociar ? 'DOCUMENTO_LABORATORIO_REASOCIADO' : ($corregir ? 'DOCUMENTO_LABORATORIO_CORREGIDO' : 'DOCUMENTO_LABORATORIO_CARGADO'),
                    ['id_solicitud' => (string) $origen->getKey(), 'id_solicitud_destino' => (string) $receptor->getKey(),
                        'id_version_resultado' => (string) $version->getKey(), 'id_consulta' => (string) $receptor->id_consulta,
                        'id_paciente' => (string) $referencias->firstWhere('id_solicitud', $destino)->id_paciente,
                        'id_medico' => (string) $referencias->firstWhere('id_solicitud', $destino)->id_medico], $ahora);
                $salida = $this->lectura->consultar($id);
                if ($reasociar) {
                    $salida['destino'] = $this->lectura->consultar($destino)['solicitud'];
                }
                $terminoBloque = true;

                return $salida;
            });
        } catch (Throwable $error) {
            // Nunca retirar bytes ante un commit potencialmente confirmado o un rollback incierto.
            if ($ruta !== null && ! $terminoBloque && $nivelInicial === 0 && DB::transactionLevel() === 0) {
                try {
                    if (! DB::connection()->getPdo()->inTransaction() && ! $this->documentos->rutaReferenciada($ruta)) {
                        $this->archivos->retirarNuevo($ruta);
                    }
                } catch (Throwable) {
                    Log::warning('Limpieza privada de laboratorio pendiente.', ['tipo' => 'ARCHIVO_NUEVO_NO_CONFIRMADO']);
                }
            }
            throw $error;
        }
    }
}
