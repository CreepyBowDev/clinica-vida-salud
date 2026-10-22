<?php

namespace Clinica\business\services\citas;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\citas\DisponibilidadRepository;
use Clinica\data\repositories\horarios\ConfiguracionCitasRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\PropuestaReserva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private DisponibilidadService $disponibilidad,
        private DisponibilidadRepository $recursos,
        private ConfiguracionCitasRepository $configuraciones,
        private CitaRepository $citas,
        private PropuestaReserva $propuestas,
        private BitacoraRepository $bitacora,
    ) {}

    private function actor(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, ['Recepcionista', 'Paciente'], true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $this->planificacion->coordinar();

        return $actor;
    }

    private function ficha(Usuario $actor, ?string $id): Paciente
    {
        $paciente = $this->recursos->paciente((string) $actor->getKey(), $actor->rol->nombre === 'Paciente' ? null : $id);
        if (! $paciente || ($id !== null && (string) $paciente->getKey() !== $id)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $paciente;
    }

    public function proponer(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $actor = $this->actor();
            $vista = $this->seleccionar($datos, $datos['revision_turno']);
            $paciente = $this->ficha($actor, isset($datos['id_paciente']) ? (string) $datos['id_paciente'] : null);
            $resumen = $this->resumen($vista, $paciente);
            $motivo = $this->normalizarMotivo($datos['motivo_breve'] ?? null);
            $uuid = (string) Str::uuid();
            $seleccion = array_intersect_key($datos, array_flip(['id_medico', 'id_especialidad', 'fecha', 'hora_inicio']));
            $seleccion['id_medico'] = (string) $seleccion['id_medico'];
            $seleccion['id_especialidad'] = (string) $seleccion['id_especialidad'];
            $prueba = ['id_propuesta' => $uuid, 'actor' => (string) $actor->getKey(), 'rol' => $actor->rol->nombre,
                'id_paciente' => (string) $paciente->getKey(), 'seleccion' => $seleccion,
                'revision_turno' => $datos['revision_turno'], 'resumen' => $resumen, 'motivo_hash' => $this->propuestas->motivo($motivo)];

            return ['id_propuesta' => $uuid, 'propuesta' => $this->propuestas->emitir($prueba), ...$resumen, 'motivo_breve' => $motivo];
        });
    }

    private function seleccionar(array $datos, string $revision, ?array $anterior = null): array
    {
        try {
            $vista = $this->disponibilidad->turnos($datos);
        } catch (ValidationException $error) {
            if (array_keys($error->errors()) !== ['fecha']) {
                throw $error;
            }
            $this->obsoleta();
        }
        if (! $vista['paciente_reservable']) {
            throw new AppError('CITA_PACIENTE_NO_DISPONIBLE', 'La ficha del Paciente no permite nuevas reservas.');
        }
        $elegido = collect($vista['turnos'])->firstWhere('hora_inicio', $datos['hora_inicio']);
        if ($elegido === null) {
            $this->obsoleta();
        }
        if (! hash_equals($revision, $elegido['revision_turno'])) {
            $cambios = [];
            if ($anterior !== null && $anterior['duracion_minutos'] !== $vista['duracion_minutos']) {
                $cambios[] = 'DURACION';
            }
            if ($anterior !== null && $anterior['consultorio'] !== $vista['consultorio']) {
                $cambios[] = 'CONSULTORIO';
            }
            $this->obsoleta($cambios ?: ['DISPONIBILIDAD']);
        }

        return [...$vista, 'elegido' => $elegido];
    }

    private function resumen(array $vista, Paciente $paciente): array
    {
        $config = $this->configuraciones->bloqueada(); // Ya adquirido por disponibilidad, misma transacción exterior.

        return ['paciente' => ['id_paciente' => (string) $paciente->getKey(), 'codigo_paciente' => $paciente->codigo_paciente,
            'nombres' => $paciente->nombres, 'apellidos' => $paciente->apellidos],
            'medico' => $vista['medico'], 'especialidad' => $vista['especialidad'], 'consultorio' => $vista['consultorio'],
            'inicio_at' => $vista['elegido']['inicio_at'], 'fin_at' => $vista['elegido']['fin_at'],
            'duracion_minutos' => $vista['duracion_minutos'], 'zona_horaria' => $vista['zona_horaria'],
            'modalidad' => 'Presencial', 'sede_atencion' => $config->nombre_sede, 'direccion_sede' => $config->direccion_sede];
    }

    public function confirmar(array $datos, array $contexto): array
    {
        try {
            return $this->operar($datos['propuesta'], true, $this->normalizarMotivo($datos['motivo_breve'] ?? null), $contexto);
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains($error->errorInfo[2] ?? '', 'citas_propuesta_reserva_uq')) {
                // Reconciliar fuera de la transacción abortada; nunca repetir una inserción/auditoría ciegamente.
                $resultado = $this->operar($datos['propuesta'], false);
                if ($resultado['registrada']) {
                    return ['cita' => $resultado['cita'], 'repetida' => true];
                }
            }
            if ($error->getCode() === '23P01' && str_contains($error->errorInfo[2] ?? '', 'citas_paciente_no_solapado_excl')) {
                throw new AppError('CITA_PACIENTE_SOLAPADO', 'El Paciente ya tiene una cita en ese intervalo. Consulta otro turno.');
            }
            if ($error->getCode() === '23P01' && (str_contains($error->errorInfo[2] ?? '', 'citas_medico_no_solapado_excl')
                || str_contains($error->errorInfo[2] ?? '', 'citas_consultorio_no_solapado_excl'))) {
                throw new AppError('CITA_TURNO_NO_DISPONIBLE', 'El turno ya no está disponible. Consulta nuevamente la disponibilidad.');
            }
            throw $error;
        }
    }

    public function resultado(string $propuesta): array
    {
        return $this->operar($propuesta, false);
    }

    private function operar(string $token, bool $guardar, ?string $motivo = null, array $contexto = []): array
    {
        return DB::transaction(function () use ($token, $guardar, $motivo, $contexto): array {
            $actor = $this->actor();
            $prueba = $this->propuestas->abrir($token);
            if ($prueba['actor'] !== (string) $actor->getKey() || $prueba['rol'] !== $actor->rol->nombre) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            if ($guardar && ! hash_equals($prueba['motivo_hash'], $this->propuestas->motivo($motivo))) {
                $this->propuestas->invalida();
            }
            // Lectura sin lock para elegir rama, bajo planificación. Relectura bloqueada tras ficha en replay/resultado.
            $existente = $this->citas->porPropuesta($prueba['id_propuesta']);
            if ($existente !== null || ! $guardar) {
                $this->ficha($actor, $prueba['id_paciente']);
                $cita = $this->citas->porPropuesta($prueba['id_propuesta'], true);
                $this->autenticacion->exigirSesionNormal(bloquear: true);
                if ($cita === null) {
                    return ['registrada' => false];
                }
                if ((string) $cita->creada_por !== (string) $actor->getKey() || (string) $cita->id_paciente !== $prueba['id_paciente']) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }

                return $guardar ? ['cita' => $this->detalle($cita), 'repetida' => true]
                    : ['registrada' => true, 'cita' => $this->detalle($cita)];
            }
            $seleccion = [...$prueba['seleccion'], ...($actor->rol->nombre === 'Recepcionista' ? ['id_paciente' => $prueba['id_paciente']] : [])];
            $vista = $this->seleccionar($seleccion, $prueba['revision_turno'], $prueba['resumen']);
            $paciente = $this->ficha($actor, $prueba['id_paciente']);
            $resumen = $this->resumen($vista, $paciente);
            if ($resumen !== $prueba['resumen']) {
                $this->obsoleta();
            }
            $inicio = CarbonImmutable::parse($resumen['inicio_at']);
            $fin = CarbonImmutable::parse($resumen['fin_at']);
            if ($this->citas->pacienteSolapado((string) $paciente->getKey(), $inicio, $fin)) {
                throw new AppError('CITA_PACIENTE_SOLAPADO', 'El Paciente ya tiene una cita en ese intervalo. Consulta otro turno.');
            }
            $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($inicio->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $this->obsoleta();
            }
            $campos = ['id_propuesta_reserva' => $prueba['id_propuesta'], 'id_paciente' => $paciente->getKey(),
                'id_medico' => $resumen['medico']['id_medico'], 'id_especialidad' => $resumen['especialidad']['id_especialidad'],
                'id_consultorio' => $resumen['consultorio']['id_consultorio'], 'creada_por' => $actor->getKey(),
                'inicio_at' => $inicio, 'fin_at' => $fin, 'duracion_minutos' => $resumen['duracion_minutos'],
                'estado' => 'Programada', 'modalidad' => 'Presencial', 'version_programacion' => 1, 'motivo_breve' => $motivo,
                'sede_atencion' => $resumen['sede_atencion'],
                'consultorio_atencion' => $resumen['consultorio']['codigo_consultorio'].' — '.$resumen['consultorio']['nombre']];
            $cita = $this->insertar($campos);
            $this->bitacora->citaProgramada($cita, $actor, $contexto);

            return ['cita' => $this->detalle($cita), 'repetida' => false];
        });
    }

    private function insertar(array $campos): Cita
    {
        for ($intento = 0; $intento < 3; $intento++) {
            try {
                return DB::transaction(fn () => $this->citas->crear([...$campos, 'codigo_cita' => 'CIT-'.bin2hex(random_bytes(16))]));
            } catch (QueryException $error) {
                if ($error->getCode() !== '23505' || ! str_contains($error->errorInfo[2] ?? '', 'citas_codigo_uq') || $intento === 2) {
                    throw $error;
                }
            }
        }
    }

    private function detalle(Cita $cita): array
    {
        $datos = [];
        foreach (['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'] as $campo) {
            $datos[$campo] = (string) $cita->getAttribute($campo);
        }
        foreach (['codigo_cita', 'estado', 'modalidad', 'duracion_minutos', 'sede_atencion', 'consultorio_atencion', 'motivo_breve', 'version_programacion'] as $campo) {
            $datos[$campo] = $cita->getAttribute($campo);
        }
        foreach (['inicio_at', 'fin_at', 'creada_en', 'actualizada_en'] as $campo) {
            $datos[$campo] = $cita->getAttribute($campo)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return $datos;
    }

    private function normalizarMotivo(?string $motivo): ?string
    {
        $texto = $motivo === null ? '' : trim($motivo);

        return $texto === '' ? null : $texto;
    }

    private function obsoleta(array $cambios = ['DISPONIBILIDAD']): never
    {
        throw new AppError('CITA_PROPUESTA_OBSOLETA', 'Las condiciones revisadas cambiaron. Consulta la disponibilidad y revisa una nueva propuesta.',
            null, ['cambios' => $cambios]);
    }
}
