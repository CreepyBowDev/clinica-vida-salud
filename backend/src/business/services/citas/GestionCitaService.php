<?php

namespace Clinica\business\services\citas;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\CoordinacionFinancieraCitaService;
use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GestionCitaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private DisponibilidadService $disponibilidad,
        private CitaRepository $citas,
        private RecordatorioService $recordatorios,
        private BitacoraRepository $bitacora,
        private CoordinacionFinancieraCitaService $finanzas,
    ) {}

    private function actor(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, ['Administrador', 'Recepcionista'], true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $this->planificacion->coordinar();

        return $actor;
    }

    private function cita(string $id, bool $bloquear = false): Cita
    {
        return $this->citas->porId($id, $bloquear)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
    }

    private function impedimentos(Cita $cita): array
    {
        $codigos = [];
        if (! in_array($cita->estado, ['Programada', 'Confirmada'], true)) {
            $codigos[] = 'CITA_GESTION_NO_PERMITIDA';
        }
        if ($cita->inicio_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $codigos[] = 'CITA_PLAZO_GESTION_FINALIZADO';
        }
        if ($this->citas->tieneConsulta((string) $cita->getKey())) {
            $codigos[] = 'CITA_CON_ATENCION_DOCUMENTADA';
        }

        return $codigos;
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

    public function consultar(string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $actor = $this->actor();
            $cita = $this->cita($id, true);
            $this->revalidar($actor);
            $impedimentos = $this->impedimentos($cita);

            return [...$this->detalle($cita), 'zona_horaria' => 'America/La_Paz',
                'consultado_en' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
                'acciones' => ['reprogramar' => $impedimentos === [], 'cancelar' => $impedimentos === []], 'impedimentos' => $impedimentos,
                'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }

    private function revalidar(Usuario $actor): void
    {
        $vigente = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ((string) $vigente->getKey() !== (string) $actor->getKey() || $vigente->rol->nombre !== $actor->rol->nombre) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
    }

    private function originales(Cita $cita, array $datos): void
    {
        if ((string) $cita->version_programacion !== (string) $datos['version_programacion_original']
            || $cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z') !== $datos['actualizada_en_original']) {
            throw new AppError('CITA_EDICION_OBSOLETA', 'La cita cambió. Vuelve a consultar su programación.');
        }
    }

    public function reprogramar(string $id, array $datos, array $contexto): array
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto): array {
                $actor = $this->actor();
                // Sin lock inicial de cita: disponibilidad adquiere recursos antes de la relectura bloqueada.
                $inicial = $this->cita($id);
                $this->originales($inicial, $datos);
                foreach ($this->impedimentos($inicial) as $codigo) {
                    throw new AppError($codigo, 'La cita no admite esta operación.');
                }
                $inicio = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $datos['fecha'].' '.$datos['hora_inicio'], 'America/La_Paz');
                if ($inicio->equalTo($inicial->inicio_at)) {
                    throw new AppError('CITA_REPROGRAMACION_SIN_CAMBIO', 'Selecciona una fecha y hora distintas de la programación actual.');
                }
                try {
                    $vista = $this->disponibilidad->turnosReprogramacion($id, ['fecha' => $datos['fecha']]);
                } catch (ValidationException $error) {
                    if (array_keys($error->errors()) !== ['fecha']) {
                        throw $error;
                    }
                    $this->obsoleta();
                }
                $cita = $this->cita($id, true);
                $this->originales($cita, $datos);
                $turno = collect($vista['turnos'])->firstWhere('hora_inicio', $datos['hora_inicio']);
                if ($turno === null || ! hash_equals($turno['revision_turno'], $datos['revision_turno'])) {
                    $this->obsoleta();
                }
                $this->revalidar($actor);
                foreach ($this->impedimentos($cita) as $codigo) {
                    throw new AppError($codigo, 'La cita no admite esta operación.');
                }
                $ahora = CarbonImmutable::now('UTC');
                if ($cita->inicio_at->lessThanOrEqualTo($ahora)) {
                    throw new AppError('CITA_PLAZO_GESTION_FINALIZADO', 'El plazo para gestionar esta cita ha finalizado.');
                }
                if ($inicio->lessThanOrEqualTo($ahora) || $cita->version_programacion === 2147483647) {
                    $this->obsoleta();
                }
                $antes = $this->detalle($cita);
                $cita->forceFill(['inicio_at' => CarbonImmutable::parse($turno['inicio_at']),
                    'fin_at' => CarbonImmutable::parse($turno['fin_at']), 'duracion_minutos' => $vista['duracion_minutos'],
                    'id_consultorio' => $vista['consultorio']['id_consultorio'], 'sede_atencion' => $vista['sede_atencion'],
                    'consultorio_atencion' => $vista['consultorio']['codigo_consultorio'].' — '.$vista['consultorio']['nombre'],
                    'estado' => 'Programada', 'version_programacion' => $cita->version_programacion + 1,
                    'actualizada_en' => $ahora->greaterThan($cita->actualizada_en) ? $ahora : $cita->actualizada_en->addMicrosecond()]);
                $this->citas->guardar($cita);
                $despues = $this->detalle($cita);
                $cambio = $this->citas->agregarCambio($cita, (string) $actor->getKey(), $antes, $despues, $ahora);
                $finanzas = $this->finanzas->coordinar($cita, $cambio);
                $this->recordatorios->invalidarPorCita($id, $ahora);
                $this->bitacora->citaReprogramada($cita, $actor, $contexto, $antes, $finanzas);

                return [...$despues, 'ultimo_cambio' => $this->ultimoCambio($id)];
            });
        } catch (QueryException $error) {
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

    private function obsoleta(): never
    {
        throw new AppError('CITA_REPROGRAMACION_OBSOLETA', 'Las condiciones revisadas cambiaron. Vuelve a consultar la disponibilidad.');
    }

    public function cancelar(string $id, array $datos, array $contexto): array
    {
        return DB::transaction(function () use ($id, $datos, $contexto): array {
            $actor = $this->actor();
            // No se adquieren recursos de destino después de cita: cancelar solo libera el intervalo.
            $cita = $this->cita($id, true);
            $this->originales($cita, $datos);
            $this->revalidar($actor);
            foreach ($this->impedimentos($cita) as $codigo) {
                throw new AppError($codigo, 'La cita no admite esta operación.');
            }
            $ahora = CarbonImmutable::now('UTC');
            // La consulta de existencia también pudo esperar; comprobar el corte justo antes del UPDATE.
            if ($cita->inicio_at->lessThanOrEqualTo($ahora)) {
                throw new AppError('CITA_PLAZO_GESTION_FINALIZADO', 'El plazo para gestionar esta cita ha finalizado.');
            }
            $motivo = isset($datos['motivo']) ? trim($datos['motivo']) : '';
            $antes = $this->detalle($cita);
            $cita->forceFill(['estado' => 'Cancelada', 'actualizada_en' => $ahora->greaterThan($cita->actualizada_en)
                ? $ahora : $cita->actualizada_en->addMicrosecond()]);
            $this->citas->guardar($cita);
            $despues = $this->detalle($cita);
            $cambio = $this->citas->agregarCambio($cita, (string) $actor->getKey(), $antes, $despues, $ahora,
                'CANCELACION', $motivo === '' ? null : $motivo);
            $finanzas = $this->finanzas->coordinar($cita, $cambio);
            $this->recordatorios->invalidarPorCita($id, $ahora);
            $this->bitacora->citaCancelada($cita, $actor, $contexto, $antes, $finanzas);

            return [...$despues, 'ultimo_cambio' => $this->ultimoCambio($id)];
        });
    }
}
