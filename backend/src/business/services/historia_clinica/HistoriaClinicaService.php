<?php

namespace Clinica\business\services\historia_clinica;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\historia_clinica\ConsultaMedicaRepository;
use Clinica\data\repositories\historia_clinica\HistoriaClinicaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HistoriaClinicaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private HistoriaClinicaRepository $historias,
        private ConsultaMedicaRepository $consultas,
    ) {}

    private function identidad(Usuario $actor): array
    {
        $rol = $actor->rol->nombre;
        if (! in_array($rol, ['Administrador', 'Médico', 'Paciente'], true)
            || ($rol === 'Médico' && ($actor->medico === null || $actor->paciente !== null
                || (string) $actor->medico->id_usuario !== (string) $actor->getKey()))
            || ($rol === 'Paciente' && ($actor->paciente === null || $actor->medico !== null
                || (string) $actor->paciente->id_usuario !== (string) $actor->getKey()))) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return [(string) $actor->getKey(), $rol,
            $rol === 'Médico' ? (string) $actor->medico->getKey() : null,
            $rol === 'Paciente' ? (string) $actor->paciente->getKey() : null];
    }

    private function autorizar(array $identidad, Paciente $paciente): void
    {
        if (($identidad[1] === 'Paciente' && $identidad[3] !== (string) $paciente->getKey())
            || ($identidad[1] === 'Médico' && ! $this->historias->relacionMedica($identidad[2], (string) $paciente->getKey()))) {
            throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
        }
    }

    private function leer(string $id, bool $porCita, bool $historico, Closure $lectura): array
    {
        return DB::transaction(function () use ($id, $porCita, $historico, $lectura): array {
            $this->continuidad->coordinar();
            $identidad = $this->identidad($this->autenticacion->exigirSesionNormal(bloquear: true));
            if ($historico && $identidad[1] === 'Paciente') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $referencia = $porCita ? $this->historias->referenciaCita($id) : null;
            if ($porCita && $referencia === null) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $idsMedicos = array_unique(array_filter([$identidad[2], $referencia === null ? null : (string) $referencia->id_medico]));
            usort($idsMedicos, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            foreach ($idsMedicos as $medico) {
                $this->medicos->porIdBloqueado($medico);
            }
            $paciente = $this->historias->paciente($porCita ? (string) $referencia->id_paciente : $id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $this->autorizar($identidad, $paciente);
            $cita = $porCita ? $this->historias->cita($id, (string) $paciente->getKey()) : null;
            if ($porCita && ($cita === null || (string) $cita->id_medico !== (string) $referencia->id_medico)) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $resultado = $lectura($paciente, $cita, $identidad[1] !== 'Paciente');
            if ($this->identidad($this->autenticacion->exigirSesionNormal(bloquear: true)) !== $identidad) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->autorizar($identidad, $paciente);

            if (($resultado['vista'] ?? null) === 'consulta' && $identidad[1] !== 'Paciente'
                && ($identidad[1] === 'Administrador' || $identidad[2] === (string) $cita->id_medico)) {
                $impedimentos = [];
                $referenciaAntecedente = $this->consultas->referenciaAntecedente((string) $paciente->getKey());
                if ($referenciaAntecedente !== null && $resultado['antecedentes'] === null) {
                    $impedimentos[] = 'CLINICA_EDICION_OBSOLETA';
                }
                $ahora = CarbonImmutable::now('UTC');
                if (! in_array($cita->estado, ['Programada', 'Confirmada', 'Atendida'], true)) {
                    $impedimentos[] = 'CLINICA_CITA_NO_PERMITIDA';
                }
                if ($ahora->lessThan($cita->inicio_at)) {
                    $impedimentos[] = 'CLINICA_ATENCION_ANTICIPADA';
                }
                $registro = [...$impedimentos, ...($resultado['consulta'] === null ? [] : ['CLINICA_CONSULTA_EXISTENTE'])];
                $actualizacion = [...$impedimentos, ...($resultado['consulta'] !== null && $resultado['version'] !== null
                    ? [] : ['RECURSO_NO_ENCONTRADO'])];
                $resultado['edicion'] = [
                    'originales' => ['version_programacion' => $cita->version_programacion,
                        'actualizada_en' => $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
                        'id_consulta' => $resultado['consulta'] === null ? null : (string) $resultado['consulta']->getKey(),
                        'id_version_consulta' => $resultado['version'] === null ? null : (string) $resultado['version']->getKey(),
                        'id_version_antecedente' => $resultado['antecedentes'] === null ? null : (string) $resultado['antecedentes']->getKey()],
                    'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                    'acciones' => ['registrar' => $registro === [], 'actualizar' => $actualizacion === []],
                    'impedimentos' => ['registrar' => $registro, 'actualizar' => $actualizacion],
                ];
            }

            return [...$resultado, 'interno' => $identidad[1] !== 'Paciente'];
        });
    }

    public function consultar(string $paciente, array $filtros): array
    {
        return $this->leer($paciente, false, false, function (Paciente $paciente, ?Cita $cita, bool $interno) use ($filtros): array {
            $id = (string) $paciente->getKey();

            return ['vista' => 'historia', 'paciente' => $paciente,
                'antecedentes' => $this->historias->antecedentes($id, $interno),
                'paginacion' => $this->historias->consultas($id, $filtros)];
        });
    }

    public function consulta(string $id, array $filtros): array
    {
        return $this->leer($id, true, false, function (Paciente $paciente, Cita $cita, bool $interno) use ($filtros): array {
            $idPaciente = (string) $paciente->getKey();
            $consulta = $this->historias->consulta((string) $cita->getKey(), $idPaciente);
            $estudios = $consulta === null ? new LengthAwarePaginator([], 0, $filtros['per_page'] ?? 20, $filtros['page'] ?? 1)
                : $this->historias->estudios((string) $consulta->getKey(), $idPaciente, $filtros);
            $estudios->through(function ($estudio) use ($interno): array {
                $publicado = $estudio->estado_interno === 'Disponible' && $estudio->decision === 'Autorizar'
                    && $estudio->numero_version !== null && (int) $estudio->numero_version === (int) $estudio->ultima_version
                    && (string) $estudio->id_revisor === (string) $estudio->id_responsable;

                return ['id_solicitud' => (string) $estudio->id_solicitud, 'estudio_solicitado' => $estudio->estudio_solicitado,
                    'solicitada_en' => $estudio->solicitada_en,
                    'estado' => $interno ? $estudio->estado_interno : ($publicado ? 'Disponible' : 'Pendiente'),
                    'resultado' => $publicado ? ['numero_version' => (int) $estudio->numero_version, 'publicado_en' => $estudio->revisada_en] : null];
            });

            return ['vista' => 'consulta', 'paciente' => $paciente, 'cita' => $cita, 'consulta' => $consulta,
                'version' => $consulta === null ? null : $this->historias->versionConsulta((string) $consulta->getKey(), $idPaciente, $interno),
                'antecedentes' => $this->historias->antecedentes($idPaciente, $interno), 'paginacion' => $estudios];
        });
    }

    public function versionesConsulta(string $id, array $filtros): array
    {
        return $this->leer($id, true, true, function (Paciente $paciente, Cita $cita) use ($filtros): array {
            $consulta = $this->historias->consulta((string) $cita->getKey(), (string) $paciente->getKey());

            return ['vista' => 'versiones_consulta', 'paginacion' => $consulta === null
                ? new LengthAwarePaginator([], 0, $filtros['per_page'] ?? 20, $filtros['page'] ?? 1)
                : $this->historias->historialConsulta((string) $consulta->getKey(), (string) $paciente->getKey(), $filtros)];
        });
    }

    public function versionesAntecedentes(string $id, array $filtros): array
    {
        return $this->leer($id, false, true, fn (Paciente $paciente): array => [
            'vista' => 'versiones_antecedentes',
            'paginacion' => $this->historias->historialAntecedentes((string) $paciente->getKey(), $filtros),
        ]);
    }
}
