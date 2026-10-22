<?php

namespace Clinica\business\services\horarios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\IntervalosAtencion;
use Clinica\data\models\ConfiguracionClinica;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\horarios\ConfiguracionCitasRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class ConfiguracionCitasService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private ConfiguracionCitasRepository $configuraciones,
        private IntervalosAtencion $intervalos,
        private BitacoraRepository $bitacora,
    ) {}

    public function ejecutar(array $datos = [], bool $guardar = false, array $contexto = []): array
    {
        return DB::transaction(function () use ($datos, $guardar, $contexto): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($actor->rol->nombre !== 'Administrador') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $configuracion = $this->configuraciones->bloqueada();
            if ($datos === []) {
                return $this->resultado($configuracion);
            }
            if (! $configuracion->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                throw new AppError('CONFIGURACION_EDICION_OBSOLETA', 'La configuración cambió. Vuelve a cargarla.');
            }
            $duracion = (int) $datos['duracion_turno_minutos'];
            $conflictos = [];
            foreach ($this->configuraciones->horariosActivos() as $horario) {
                if ($this->intervalos->turnos($horario, $duracion) < 1) {
                    $conflictos[] = ['codigo' => 'DURACION_INCOMPATIBLE', 'id_medico' => (string) $horario->id_medico,
                        'id_horario' => (string) $horario->getKey(), 'dia_semana' => $horario->dia_semana,
                        'hora_inicio' => $horario->hora_inicio, 'hora_fin' => $horario->hora_fin];
                }
            }
            if (! $guardar) {
                return ['puede_confirmar' => $conflictos === [], 'totales' => ['conflictos' => count($conflictos)], 'elementos' => $conflictos];
            }
            if ($conflictos !== []) {
                throw new AppError('DURACION_INCOMPATIBLE', 'La duración no cabe en todos los horarios activos. Revisa el impacto.');
            }
            if ($duracion !== $configuracion->duracion_turno_minutos) {
                $anterior = $configuracion->duracion_turno_minutos;
                $this->configuraciones->guardar($configuracion, $duracion);
                $this->bitacora->planificacionModificada($actor, $contexto, 'DURACION_CITAS_CAMBIADA',
                    ['id_configuracion' => '1'], ['antes' => ['duracion_turno_minutos' => $anterior], 'despues' => ['duracion_turno_minutos' => $duracion]]);
            }

            return $this->resultado($configuracion);
        });
    }

    private function resultado(ConfiguracionClinica $configuracion): array
    {
        return ['duracion_turno_minutos' => $configuracion->duracion_turno_minutos, 'zona_horaria' => $configuracion->zona_horaria,
            'actualizado_en' => $configuracion->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
