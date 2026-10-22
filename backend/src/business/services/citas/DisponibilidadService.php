<?php

namespace Clinica\business\services\citas;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\IntervalosAtencion;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\citas\DisponibilidadRepository;
use Clinica\data\repositories\horarios\ConfiguracionCitasRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\PropuestaReserva;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisponibilidadService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private ConfiguracionCitasRepository $configuraciones,
        private DisponibilidadRepository $datos,
        private IntervalosAtencion $intervalos,
        private PropuestaReserva $propuestas,
    ) {}

    private function actor(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, ['Recepcionista', 'Paciente'], true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    public function especialidades(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->actor();

            return $this->datos->especialidades($filtros);
        });
    }

    public function medicos(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->actor();

            return $this->datos->medicos($filtros);
        });
    }

    public function turnos(array $filtros): array
    {
        return DB::transaction(function () use ($filtros): array {
            $actor = $this->actor();
            $propia = $actor->rol->nombre === 'Paciente';
            if (($propia && array_key_exists('id_paciente', $filtros)) || (! $propia && ! isset($filtros['id_paciente']))) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $this->planificacion->coordinar();
            $config = $this->configuraciones->bloqueada();
            $medico = $this->datos->medico((string) $filtros['id_medico']);
            $especialidad = $this->datos->especialidad((string) $filtros['id_especialidad']);
            if (! $medico || ! $especialidad) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            $habilitado = $this->datos->habilitado((string) $medico->getKey(), (string) $especialidad->getKey());
            $sala = $medico->id_consultorio === null ? null : $this->datos->consultorio((string) $medico->id_consultorio);
            if (! $medico->activo || ! $especialidad->activa || ! $habilitado || ! $sala?->activo) {
                throw new AppError('CITA_TURNO_NO_DISPONIBLE', 'La selección ya no está disponible. Consulta los criterios vigentes.');
            }
            $horarios = $this->datos->horarios((string) $medico->getKey(), (string) $sala->getKey());
            $paciente = $this->datos->paciente((string) $actor->getKey(), $propia ? null : (string) $filtros['id_paciente']);
            if (! $paciente) {
                throw new AppError($propia ? 'ACCESO_DENEGADO' : 'RECURSO_NO_ENCONTRADO', 'No se encontró una ficha autorizada para esta consulta.');
            }
            $dia = CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha'], 'America/La_Paz');
            $ocupadas = $this->datos->ocupacion((string) $medico->getKey(), (string) $sala->getKey(), (string) $paciente->getKey(), $dia, $dia->addDay());
            // La espera por planificación, ficha y ocupación puede hacer que un turno ya haya empezado.
            $vigente = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($vigente->rol->nombre !== $actor->rol->nombre) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $ahora = CarbonImmutable::now('UTC');
            if ($dia->toDateString() < $ahora->setTimezone('America/La_Paz')->toDateString()) {
                throw ValidationException::withMessages(['fecha' => ['Selecciona una fecha que no sea pasada en la sede.']]);
            }
            $version = fn ($modelo) => $modelo->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z');
            $contexto = ['actor' => (string) $actor->getKey(), 'rol' => $actor->rol->nombre,
                'paciente' => [(string) $paciente->getKey(), $paciente->activo],
                'medico' => [(string) $medico->getKey(), $version($medico), $medico->nombres, $medico->apellidos],
                'especialidad' => [(string) $especialidad->getKey(), $version($especialidad), $especialidad->nombre, $habilitado],
                'sala' => [(string) $sala->getKey(), $version($sala), $sala->codigo_consultorio, $sala->nombre],
                'configuracion' => [$version($config), $config->duracion_turno_minutos, $config->nombre_sede, $config->direccion_sede]];
            $turnos = [];
            // Proyectar los casts temporales una sola vez, no por candidato/ocupante.
            $ocupacion = $ocupadas->map(fn ($c) => [$c->inicio_at, $c->fin_at]);
            $compartidos = $horarios->filter(fn ($h) => (string) $h->id_medico !== (string) $medico->getKey());
            foreach ($horarios as $horario) {
                if ((string) $horario->id_medico !== (string) $medico->getKey() || $horario->dia_semana !== $dia->isoWeekday()) {
                    continue;
                }
                foreach ($this->intervalos->candidatos($dia, $horario, $config->duracion_turno_minutos) as $candidato) {
                    ['inicio' => $inicio, 'fin' => $fin] = $candidato;
                    if ($inicio->lessThanOrEqualTo($ahora)
                        || $ocupacion->contains(fn ($c) => $inicio->lessThan($c[1]) && $c[0]->lessThan($fin))
                        || $compartidos->contains(fn ($h) => $this->intervalos->ocupaFranja($inicio, $fin, $h))) {
                        continue;
                    }
                    $turno = ['inicio_at' => $inicio->utc()->format('Y-m-d\TH:i:s.u\Z'),
                        'fin_at' => $fin->utc()->format('Y-m-d\TH:i:s.u\Z'), 'hora_inicio' => $inicio->format('H:i:s')];
                    $turnos[] = [...$turno, 'revision_turno' => $this->propuestas->revision(
                        [...$contexto, 'horario' => [(string) $horario->getKey(), $horario->dia_semana, $horario->hora_inicio, $horario->hora_fin]], $turno)];
                }
            }
            usort($turnos, fn ($a, $b) => strcmp($a['inicio_at'], $b['inicio_at']));

            return ['fecha' => $filtros['fecha'], 'zona_horaria' => 'America/La_Paz', 'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                'paciente_reservable' => $paciente->activo, 'duracion_minutos' => $config->duracion_turno_minutos,
                'medico' => ['id_medico' => (string) $medico->getKey(), 'codigo_medico' => $medico->codigo_medico, 'nombres' => $medico->nombres, 'apellidos' => $medico->apellidos],
                'especialidad' => ['id_especialidad' => (string) $especialidad->getKey(), 'nombre' => $especialidad->nombre],
                'consultorio' => ['id_consultorio' => (string) $sala->getKey(), 'codigo_consultorio' => $sala->codigo_consultorio, 'nombre' => $sala->nombre],
                'turnos' => $turnos];
        });
    }
}
