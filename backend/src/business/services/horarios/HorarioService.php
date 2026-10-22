<?php

namespace Clinica\business\services\horarios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\IntervalosAtencion;
use Clinica\data\models\HorarioAtencion;
use Clinica\data\models\Medico;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\consultorios\ConsultorioRepository;
use Clinica\data\repositories\horarios\ConfiguracionCitasRepository;
use Clinica\data\repositories\horarios\HorarioRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class HorarioService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private ConfiguracionCitasRepository $configuraciones,
        private MedicoRepository $medicos,
        private ConsultorioRepository $consultorios,
        private HorarioRepository $horarios,
        private IntervalosAtencion $intervalos,
        private BitacoraRepository $bitacora,
    ) {}

    public function ejecutar(int|string $id, string $accion, array $datos, bool $guardar = false, array $contexto = []): array
    {
        return DB::transaction(function () use ($id, $accion, $datos, $guardar, $contexto): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($actor->rol->nombre !== 'Administrador') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $configuracion = $this->configuraciones->bloqueada();
            $medico = $this->medicos->porIdBloqueado($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el médico solicitado.');
            if ($accion !== 'LISTAR' && ! $medico->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                throw new AppError('MEDICO_EDICION_OBSOLETA', 'El médico cambió. Vuelve a cargar sus horarios.');
            }
            $sala = $medico->id_consultorio === null ? null : $this->consultorios->porIdBloqueado($medico->id_consultorio);
            $propios = $this->horarios->propios($id);
            if ($accion === 'LISTAR') {
                $elementos = $propios->filter(fn ($h) => ! array_key_exists('activo', $datos) || $h->activo === (bool) $datos['activo']);

                return ['id_medico' => (string) $id, 'actualizado_en' => $this->version($medico),
                    'duracion_turno_minutos' => $configuracion->duracion_turno_minutos,
                    'elementos' => $elementos->map(fn ($h) => $this->resultado($h, $medico, $configuracion->duracion_turno_minutos))->values()->all()];
            }
            $original = $accion === 'CREAR' ? null : $propios->first(fn ($h) => (string) $h->getKey() === (string) $datos['id_horario']);
            if ($accion !== 'CREAR' && $original === null) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el horario solicitado.');
            }
            $propuesto = $original === null ? new HorarioAtencion : clone $original;
            $propuesto->forceFill($accion === 'ESTADO' ? ['activo' => (bool) $datos['activo']]
                : array_intersect_key($datos, array_flip(['dia_semana', 'hora_inicio', 'hora_fin'])));
            $propuesto->id_medico = $medico->getKey();
            $conflictos = [];
            if ($propuesto->hora_fin <= $propuesto->hora_inicio) {
                $conflictos[] = ['codigo' => 'HORARIO_INTERVALO_INVALIDO', 'id_medico' => (string) $id];
            } else {
                $activos = $propios->filter(fn ($h) => $h->activo && ($original === null || $h->getKey() !== $original->getKey()))->values();
                if ($propuesto->activo) {
                    $activos->push($propuesto);
                    if ($sala === null || ! $sala->activo) {
                        $conflictos[] = ['codigo' => 'CONSULTORIO_NO_DISPONIBLE', 'id_medico' => (string) $id];
                    }
                    if ($this->intervalos->turnos($propuesto, $configuracion->duracion_turno_minutos) < 1) {
                        $conflictos[] = ['codigo' => 'DURACION_INCOMPATIBLE', 'id_medico' => (string) $id];
                    }
                    $otros = $propios->filter(fn ($h) => $h->activo && ($original === null || $h->getKey() !== $original->getKey()));
                    if ($sala !== null) {
                        $otros = $otros->concat($this->horarios->compartidos($id, $sala->getKey()));
                    }
                    foreach ($otros as $otro) {
                        if ($otro->dia_semana === $propuesto->dia_semana && $this->intervalos->superpuestos($propuesto->hora_inicio, $propuesto->hora_fin, $otro->hora_inicio, $otro->hora_fin)) {
                            $conflictos[] = ['codigo' => 'PLANIFICACION_CONFLICTO', 'id_medico' => (string) $otro->id_medico,
                                'id_horario' => (string) $otro->getKey(), 'dia_semana' => $otro->dia_semana,
                                'hora_inicio' => $otro->hora_inicio, 'hora_fin' => $otro->hora_fin];
                        }
                    }
                }
                $ahora = CarbonImmutable::now('UTC');
                foreach ($this->horarios->ocupacion($id, $sala?->getKey(), $ahora) as $cita) {
                    $propia = (string) $cita->id_medico === (string) $id;
                    $sinCobertura = $propia && in_array($cita->estado, ['Programada', 'Confirmada'], true)
                        && ! $this->intervalos->cubierto($cita->inicio_at, $cita->fin_at, $activos);
                    $ocupada = ! $propia && $propuesto->activo && $this->intervalos->ocupaFranja($cita->inicio_at->max($ahora), $cita->fin_at, $propuesto);
                    if ($sinCobertura || $ocupada) {
                        $conflictos[] = ['codigo' => $sinCobertura ? 'HORARIO_CITAS_SIN_COBERTURA' : 'PLANIFICACION_CONFLICTO',
                            'id_cita' => (string) $cita->getKey(), 'id_medico' => (string) $cita->id_medico,
                            'id_consultorio' => (string) $cita->id_consultorio, 'estado' => $cita->estado,
                            'inicio_at' => $cita->inicio_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'fin_at' => $cita->fin_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
                    }
                }
            }
            usort($conflictos, fn ($a, $b) => strcmp($a['inicio_at'] ?? '', $b['inicio_at'] ?? '')
                ?: ((int) ($a['id_medico'] ?? 0) <=> (int) ($b['id_medico'] ?? 0))
                ?: (($a['dia_semana'] ?? 0) <=> ($b['dia_semana'] ?? 0))
                ?: ((int) ($a['id_cita'] ?? $a['id_horario'] ?? 0) <=> (int) ($b['id_cita'] ?? $b['id_horario'] ?? 0)));
            if (! $guardar) {
                return ['puede_confirmar' => $conflictos === [], 'totales' => ['conflictos' => count($conflictos)], 'elementos' => $conflictos];
            }
            if ($conflictos !== []) {
                throw new AppError($conflictos[0]['codigo'], 'El horario es incompatible. Consulta el impacto antes de guardar.');
            }
            if ($original === null || $propuesto->isDirty(['dia_semana', 'hora_inicio', 'hora_fin', 'activo'])) {
                $campos = $original === null ? ['dia_semana', 'hora_inicio', 'hora_fin', 'activo']
                    : array_values(array_intersect(array_keys($propuesto->getDirty()), ['dia_semana', 'hora_inicio', 'hora_fin', 'activo']));
                $this->horarios->guardar($propuesto);
                $this->medicos->guardar($medico);
                $this->bitacora->planificacionModificada($actor, $contexto, match ($accion) {
                    'CREAR' => 'HORARIO_CREADO', 'EDITAR' => 'HORARIO_ACTUALIZADO', 'ESTADO' => 'HORARIO_ESTADO_CAMBIADO',
                }, ['id_medico' => (string) $id, 'id_horario' => (string) $propuesto->getKey()], ['campos' => $campos]);
            }

            return $this->resultado($propuesto, $medico, $configuracion->duracion_turno_minutos);
        });
    }

    private function version(Medico $medico): string
    {
        return $medico->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function resultado(HorarioAtencion $horario, Medico $medico, int $duracion): array
    {
        return ['id_horario' => (string) $horario->getKey(), 'id_medico' => (string) $medico->getKey(),
            'dia_semana' => $horario->dia_semana, 'hora_inicio' => $horario->hora_inicio, 'hora_fin' => $horario->hora_fin,
            'activo' => $horario->activo, 'actualizado_en' => $this->version($medico),
            'turnos_completos' => $this->intervalos->turnos($horario, $duracion)];
    }
}
