<?php

namespace Clinica\business\services\citas;

use Carbon\CarbonImmutable;
use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class AsistenciaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private CitaRepository $citas,
        private MedicoRepository $medicos,
        private RecordatorioService $recordatorios,
        private BitacoraRepository $bitacora,
    ) {}

    private function medicoDelActor(Usuario $actor): ?string
    {
        if ($actor->rol->nombre !== 'Médico') {
            return null;
        }
        if ($actor->medico === null || (string) $actor->medico->id_usuario !== (string) $actor->getKey() || $actor->paciente !== null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return (string) $actor->medico->getKey();
    }

    private function actor(array $roles): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, $roles, true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $this->medicoDelActor($actor);
        $this->planificacion->coordinar();

        return $actor;
    }

    private function revalidar(Usuario $actor): void
    {
        $vigente = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ((string) $vigente->getKey() !== (string) $actor->getKey() || $vigente->rol->nombre !== $actor->rol->nombre
            || $this->medicoDelActor($vigente) !== $this->medicoDelActor($actor)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
    }

    private function citaBloqueada(string $id, Usuario $actor): Cita
    {
        $medico = $this->medicoDelActor($actor);
        $inicial = $this->citas->porIdParaAsistencia($id, $medico)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
        // Siempre Médico antes de cita; no adquirir recursos de destino después de bloquearla.
        $this->medicos->porIdBloqueado((string) $inicial->id_medico);
        $cita = $this->citas->porIdParaAsistencia($id, $medico, true)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
        if ((string) $cita->id_medico !== (string) $inicial->id_medico) {
            throw new AppError('CITA_EDICION_OBSOLETA', 'La cita cambió. Vuelve a consultar su programación.');
        }

        return $cita;
    }

    private function detalle(Cita $cita): array
    {
        $datos = [];
        foreach (['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'] as $campo) {
            $datos[$campo] = (string) $cita->getAttribute($campo);
        }
        foreach (['codigo_cita', 'estado', 'modalidad', 'duracion_minutos', 'sede_atencion', 'consultorio_atencion', 'version_programacion'] as $campo) {
            $datos[$campo] = $cita->getAttribute($campo);
        }
        foreach (['inicio_at', 'fin_at', 'actualizada_en'] as $campo) {
            $datos[$campo] = $cita->getAttribute($campo)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return $datos;
    }

    private function ultimoCambio(string $id): ?array
    {
        $cambio = $this->citas->ultimoCambio($id);

        return $cambio === null ? null : ['id_cambio_cita' => (string) $cambio->getKey(),
            'id_actor' => (string) $cambio->id_actor, 'operacion' => $cambio->operacion,
            'realizado_en' => $cambio->realizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    private function impedimentosLlegada(Cita $cita, bool $consulta, CarbonImmutable $ahora): array
    {
        $codigos = [];
        if ($cita->estado !== 'Programada') {
            $codigos[] = 'CITA_TRANSICION_NO_PERMITIDA';
        }
        if ($consulta) {
            $codigos[] = 'CITA_CON_ATENCION_DOCUMENTADA';
        }
        if ($ahora->setTimezone('America/La_Paz')->toDateString() !== $cita->inicio_at->setTimezone('America/La_Paz')->toDateString()
            || $ahora->greaterThanOrEqualTo($cita->fin_at)) {
            $codigos[] = 'CITA_LLEGADA_FUERA_DE_PLAZO';
        }

        return $codigos;
    }

    private function impedimentosInasistencia(Cita $cita, bool $consulta, CarbonImmutable $ahora): array
    {
        $codigos = [];
        if ($cita->estado !== 'Programada') {
            $codigos[] = 'CITA_TRANSICION_NO_PERMITIDA';
        }
        if ($consulta) {
            $codigos[] = 'CITA_CON_ATENCION_DOCUMENTADA';
        }
        if ($ahora->lessThan($cita->fin_at)) {
            $codigos[] = 'CITA_INASISTENCIA_ANTICIPADA';
        }

        return $codigos;
    }

    private function impedimentosAtencion(Cita $cita, CarbonImmutable $ahora): array
    {
        $codigos = [];
        if (! in_array($cita->estado, ['Programada', 'Confirmada'], true)) {
            $codigos[] = 'CITA_TRANSICION_NO_PERMITIDA';
        }
        if ($ahora->lessThan($cita->inicio_at)) {
            $codigos[] = 'CITA_ATENCION_ANTICIPADA';
        }

        return $codigos;
    }

    public function consultar(string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $actor = $this->actor(['Administrador', 'Recepcionista', 'Médico']);
            $cita = $this->citaBloqueada($id, $actor);
            $consulta = $actor->rol->nombre !== 'Médico' && $this->citas->tieneConsulta($id);
            $this->revalidar($actor);
            $ahora = CarbonImmutable::now('UTC');
            $impedimentos = [
                'confirmar_llegada' => $actor->rol->nombre === 'Médico' ? ['ACCESO_DENEGADO'] : $this->impedimentosLlegada($cita, $consulta, $ahora),
                'registrar_inasistencia' => $actor->rol->nombre === 'Médico' ? ['ACCESO_DENEGADO'] : $this->impedimentosInasistencia($cita, $consulta, $ahora),
                'marcar_atendida' => $actor->rol->nombre === 'Recepcionista' ? ['ACCESO_DENEGADO'] : $this->impedimentosAtencion($cita, $ahora),
            ];

            return [...$this->detalle($cita), 'zona_horaria' => 'America/La_Paz',
                'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                'acciones' => array_map(fn (array $codigos): bool => $codigos === [], $impedimentos),
                'impedimentos' => $impedimentos, 'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }

    public function confirmarLlegada(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $actor = $this->actor(['Administrador', 'Recepcionista']);
            $cita = $this->citaBloqueada($id, $actor);
            $this->revalidar($actor);
            if ((string) $cita->version_programacion !== (string) $datos['version_programacion_original']
                || $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z') !== $datos['actualizada_en_original']) {
                throw new AppError('CITA_EDICION_OBSOLETA', 'La cita cambió. Vuelve a consultar su programación.');
            }
            if (($datos['presencia_verificada'] ?? null) !== true) {
                throw new AppError('CITA_PRESENCIA_NO_VERIFICADA', 'Comprueba presencialmente la presencia e identidad del Paciente.');
            }
            $consulta = $this->citas->tieneConsulta($id);
            $this->revalidar($actor);
            // La consulta y la revalidación pudieron esperar: usar reloj fresco justo antes del UPDATE.
            $ahora = CarbonImmutable::now('UTC');
            foreach ($this->impedimentosLlegada($cita, $consulta, $ahora) as $codigo) {
                throw new AppError($codigo, 'La cita no admite confirmar la llegada en sus condiciones actuales.');
            }
            $antes = $this->detalle($cita);
            $cita->forceFill(['estado' => 'Confirmada', 'actualizada_en' => $ahora->greaterThan($cita->actualizada_en)
                ? $ahora : $cita->actualizada_en->addMicrosecond()]);
            $this->citas->guardar($cita);
            $despues = $this->detalle($cita);
            $this->citas->agregarCambio($cita, (string) $actor->getKey(), $antes,
                [...$despues, 'presencia_verificada' => true], $ahora, 'LLEGADA');
            // Confirmada es llegada, no un estado final: conserva todos los avisos existentes.
            $this->bitacora->citaLlegadaConfirmada($cita, $actor, $contexto, $antes);

            return [...$despues, 'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }

    public function registrarInasistencia(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $actor = $this->actor(['Administrador', 'Recepcionista']);
            $cita = $this->citaBloqueada($id, $actor);
            $this->revalidar($actor);
            if ((string) $cita->version_programacion !== (string) $datos['version_programacion_original']
                || $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z') !== $datos['actualizada_en_original']) {
                throw new AppError('CITA_EDICION_OBSOLETA', 'La cita cambió. Vuelve a consultar su programación.');
            }
            if (($datos['ausencia_verificada'] ?? null) !== true) {
                throw new AppError('CITA_AUSENCIA_NO_VERIFICADA', 'Comprueba la ausencia del Paciente antes de registrar su inasistencia.');
            }
            $consulta = $this->citas->tieneConsulta($id);
            $this->revalidar($actor);
            $ahora = CarbonImmutable::now('UTC');
            foreach ($this->impedimentosInasistencia($cita, $consulta, $ahora) as $codigo) {
                throw new AppError($codigo, 'La cita no admite registrar inasistencia en sus condiciones actuales.');
            }
            $antes = $this->detalle($cita);
            $cita->forceFill(['estado' => 'Inasistencia', 'actualizada_en' => $ahora->greaterThan($cita->actualizada_en)
                ? $ahora : $cita->actualizada_en->addMicrosecond()]);
            $this->citas->guardar($cita);
            $despues = $this->detalle($cita);
            $this->citas->agregarCambio($cita, (string) $actor->getKey(), $antes,
                [...$despues, 'ausencia_verificada' => true], $ahora, 'INASISTENCIA');
            $this->recordatorios->invalidarPorCita($id, $ahora);
            $this->bitacora->citaInasistenciaRegistrada($cita, $actor, $contexto, $antes);

            return [...$despues, 'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }

    public function marcarAtendida(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $actor = $this->actor(['Administrador', 'Médico']);
            // El repositorio acota al Médico autenticado tanto al leer como al bloquear la cita.
            $cita = $this->citaBloqueada($id, $actor);
            $this->revalidar($actor);
            if ((string) $cita->version_programacion !== (string) $datos['version_programacion_original']
                || $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z') !== $datos['actualizada_en_original']) {
                throw new AppError('CITA_EDICION_OBSOLETA', 'La cita cambió. Vuelve a consultar su programación.');
            }
            if (($datos['atencion_realizada'] ?? null) !== true) {
                throw new AppError('CITA_ATENCION_NO_VERIFICADA', 'Comprueba que la atención fue efectivamente realizada.');
            }
            $this->revalidar($actor);
            $ahora = CarbonImmutable::now('UTC');
            foreach ($this->impedimentosAtencion($cita, $ahora) as $codigo) {
                throw new AppError($codigo, 'La cita no admite marcar atención realizada en sus condiciones actuales.');
            }
            $antes = $this->detalle($cita);
            $cita->forceFill(['estado' => 'Atendida', 'actualizada_en' => $ahora->greaterThan($cita->actualizada_en)
                ? $ahora : $cita->actualizada_en->addMicrosecond()]);
            $this->citas->guardar($cita);
            $despues = $this->detalle($cita);
            $this->citas->agregarCambio($cita, (string) $actor->getKey(), $antes,
                [...$despues, 'atencion_realizada' => true], $ahora, 'ATENCION');
            $this->recordatorios->invalidarPorCita($id, $ahora);
            $this->bitacora->citaAtendida($cita, $actor, $contexto, $antes);

            return [...$despues, 'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }
}
