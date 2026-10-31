<?php

namespace Clinica\business\services\historia_clinica;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultaMedicaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private CitaRepository $citas,
        private HistoriaClinicaRepository $historias,
        private ConsultaMedicaRepository $consultas,
        private HistoriaClinicaService $lectura,
        private BitacoraRepository $bitacora,
    ) {}

    private function identidad(Usuario $actor): array
    {
        $rol = $actor->rol->nombre;
        if (! in_array($rol, ['Administrador', 'Médico'], true)
            || ($rol === 'Médico' && ($actor->medico === null || $actor->paciente !== null
                || (string) $actor->medico->id_usuario !== (string) $actor->getKey()))) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return [(string) $actor->getKey(), $rol, $rol === 'Médico' ? (string) $actor->medico->getKey() : null];
    }

    private function revalidar(array $identidad): void
    {
        if ($this->identidad($this->autenticacion->exigirSesionNormal(bloquear: true)) !== $identidad) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
    }

    public function registrar(string $id, array $datos, array $contexto): array
    {
        return $this->guardar($id, $datos, $contexto, false);
    }

    public function actualizar(string $id, array $datos, array $contexto): array
    {
        return $this->guardar($id, $datos, $contexto, true);
    }

    private function guardar(string $id, array $datos, array $contexto, bool $actualizar): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto, $actualizar): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $identidad = $this->identidad($actor);
            $this->planificacion->coordinar();
            $referencia = $this->citas->porIdParaAsistencia($id, $identidad[2])
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $this->medicos->porIdBloqueado((string) $referencia->id_medico);
            $paciente = $this->historias->paciente((string) $referencia->id_paciente)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $cita = $this->citas->porIdParaAsistencia($id, $identidad[2], true)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            if ((string) $cita->id_medico !== (string) $referencia->id_medico || (string) $cita->id_paciente !== (string) $paciente->getKey()) {
                throw new AppError('CLINICA_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la atención.');
            }
            $consulta = $this->consultas->porCitaBloqueada($id, (string) $paciente->getKey());
            if ($actualizar && $consulta === null) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $versionConsulta = $consulta === null ? null
                : $this->historias->versionConsulta((string) $consulta->getKey(), (string) $paciente->getKey(), true);
            $antecedente = $this->consultas->referenciaAntecedente((string) $paciente->getKey());
            $vigente = $this->historias->antecedentes((string) $paciente->getKey(), true);
            $this->revalidar($identidad);
            $originales = $datos['originales'];
            if ((string) $cita->version_programacion !== (string) $originales['version_programacion']
                || $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z') !== $originales['actualizada_en']
                || ($consulta === null ? null : (string) $consulta->getKey()) !== ($originales['id_consulta'] === null ? null : (string) $originales['id_consulta'])
                || ($versionConsulta === null ? null : (string) $versionConsulta->getKey()) !== ($originales['id_version_consulta'] === null ? null : (string) $originales['id_version_consulta'])
                || ($antecedente === null ? null : (string) $antecedente->getKey()) !== ($originales['id_version_antecedente'] === null ? null : (string) $originales['id_version_antecedente'])
                || ($antecedente !== null && $vigente === null)) {
                throw new AppError('CLINICA_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la atención.');
            }
            if (! $actualizar && $consulta !== null) {
                throw new AppError('CLINICA_CONSULTA_EXISTENTE', 'La cita ya tiene una consulta. Vuelve a consultarla.');
            }
            if (($actualizar && $versionConsulta === null) || (! $actualizar && $originales['id_version_consulta'] !== null)) {
                throw new AppError('CLINICA_EDICION_OBSOLETA', 'La información cambió. Vuelve a consultar la atención.');
            }
            if (($datos['atencion_real_verificada'] ?? null) !== true) {
                throw new AppError('CLINICA_ATENCION_NO_VERIFICADA', 'Comprueba que la atención real fue iniciada o realizada.');
            }
            $campos = ['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes'];
            $antes = $despues = [];
            foreach ($campos as $campo) {
                $antes[$campo] = $vigente?->getAttribute($campo);
                $despues[$campo] = array_key_exists($campo, $datos['antecedentes'] ?? []) ? $datos['antecedentes'][$campo] : $antes[$campo];
            }
            $cambiarAntecedentes = $antes !== $despues;
            if ($cambiarAntecedentes && $antecedente !== null && $antecedente->numero_version === 2147483647) {
                throw new AppError('CLINICA_LIMITE_VERSION', 'No se puede agregar otra versión clínica.');
            }
            $contenido = [];
            $contenidoAnterior = [];
            foreach (['tipo_consulta', 'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento'] as $campo) {
                $contenidoAnterior[$campo] = $versionConsulta?->getAttribute($campo);
                $contenido[$campo] = array_key_exists($campo, $datos['consulta'] ?? []) ? $datos['consulta'][$campo] : $contenidoAnterior[$campo];
            }
            $cambiarConsulta = ! $actualizar || $contenidoAnterior !== $contenido;
            if ($cambiarConsulta && $versionConsulta !== null && $versionConsulta->numero_version === 2147483647) {
                throw new AppError('CLINICA_LIMITE_VERSION', 'No se puede agregar otra versión clínica.');
            }
            $this->revalidar($identidad);
            // Reloj fresco tras lecturas/esperas: no inferir iniciada_en desde esta hora.
            $ahora = CarbonImmutable::now('UTC');
            if (! in_array($cita->estado, ['Programada', 'Confirmada', 'Atendida'], true)) {
                throw new AppError('CLINICA_CITA_NO_PERMITIDA', 'La cita no admite registrar una atención clínica.');
            }
            if ($ahora->lessThan($cita->inicio_at)) {
                throw new AppError('CLINICA_ATENCION_ANTICIPADA', 'La atención todavía no puede registrarse.');
            }
            $modificado = $cambiarConsulta || $cambiarAntecedentes;
            $motivo = $actualizar && $modificado ? ($datos['motivo_cambio'] ?? null) : null;
            if ($actualizar && $modificado && (! is_string($motivo) || trim($motivo) === '' || mb_strlen($motivo) > 2000)) {
                throw ValidationException::withMessages(['motivo_cambio' => ['Indica un motivo de corrección de hasta 2000 caracteres.']]);
            }
            $consulta ??= $this->consultas->crear($id, $ahora);
            $version = $cambiarConsulta ? $this->consultas->agregarVersion((string) $consulta->getKey(), $identidad[0], $contenido,
                $ahora, ($versionConsulta?->numero_version ?? 0) + 1, $motivo) : null;
            $nueva = $cambiarAntecedentes ? $this->consultas->agregarAntecedentes((string) $paciente->getKey(),
                (string) $consulta->getKey(), $identidad[0], ($antecedente?->numero_version ?? 0) + 1, $despues, $ahora, $motivo) : null;
            if ($version !== null) {
                if ($actualizar) {
                    $this->bitacora->consultaActualizada($cita, $consulta, $version, $versionConsulta, $actor, $contexto);
                } else {
                    $this->bitacora->consultaRegistrada($cita, $consulta, $version, $actor, $contexto);
                }
            }
            if ($nueva !== null) {
                $this->bitacora->antecedentesActualizados($cita, $consulta, $nueva, $antecedente, $actor, $contexto);
            }
            // Materializar respuesta dentro de la transacción: también un fallo de lectura revierte el alta.
            $resultado = $this->lectura->consulta($id, []);
            $this->revalidar($identidad);

            return $actualizar ? [...$resultado, 'modificado' => $modificado] : $resultado;
        });
    }
}
