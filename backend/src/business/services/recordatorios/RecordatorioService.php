<?php

namespace Clinica\business\services\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\business\validators\SuscripcionPushValidator;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\pacientes\PacienteRepository;
use Clinica\data\repositories\recordatorios\EnvioRecordatorioPushRepository;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecordatorioService
{
    public function __construct(
        private RecordatorioRepository $recordatorios,
        private EnvioRecordatorioPushRepository $envios,
        private SuscripcionPushRepository $suscripciones,
        private UsuarioRepository $usuarios,
        private PacienteRepository $pacientes,
        private CitaRepository $citas,
        private PlanificacionRepository $planificacion,
        private ContinuidadAdministrativaService $continuidad,
        private AutorizacionPushValidator $autorizacion,
        private SuscripcionPushValidator $claves,
        private BitacoraRepository $bitacora,
        private AutenticacionService $autenticacion,
    ) {}

    public function invalidarPorCita(string $id, CarbonImmutable $ahora): void
    {
        // Dentro de la transacción de gestión; invalidar no genera ni envía avisos.
        $this->recordatorios->invalidarPorCita($id, $ahora);
    }

    public function generar(int $lote = 100): array
    {
        if ($lote < 1 || $lote > 1000) {
            throw new InvalidArgumentException('El tamaño de lote debe estar entre 1 y 1000.');
        }
        $resumen = ['citas_revisadas' => 0, 'avisos_creados' => 0, 'avisos_invalidados' => 0, 'envios_preparados' => 0, 'envios_descartados' => 0];
        $hasta = $this->recordatorios->ultimoIdCita();
        $cursor = '0';
        // Keyset y techo fijo: no limitar siempre a los mismos primeros avisos ni escanear altas sin fin.
        while ($ids = $this->recordatorios->candidatas($cursor, $hasta, $lote, CarbonImmutable::now('UTC'))) {
            foreach ($ids as $id) {
                $r = $this->generarParaCita($id);
                $resumen['citas_revisadas']++;
                foreach ($r as $k => $v) {
                    $resumen[$k] += $v;
                }
                $cursor = $id;
            }
        }

        return $resumen;
    }

    public function listar(array $filtros): array
    {
        return DB::transaction(function () use ($filtros): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $idActor = (string) $actor->getKey();
            $rol = $actor->rol->nombre;
            if (! in_array($rol, ['Administrador', 'Paciente'], true)) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            if ($rol === 'Paciente') {
                if (array_key_exists('id_paciente', $filtros)) {
                    throw ValidationException::withMessages(['id_paciente' => ['Esta consulta solo admite avisos propios.']]);
                }
                if ($actor->paciente === null || $actor->medico !== null || (string) $actor->paciente->id_usuario !== $idActor) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $idPaciente = (string) $actor->paciente->getKey();
            } else {
                if (! isset($filtros['id_paciente'])) {
                    throw ValidationException::withMessages(['id_paciente' => ['Selecciona un Paciente para consultar sus avisos.']]);
                }
                $idPaciente = (string) $filtros['id_paciente'];
            }
            $this->planificacion->coordinar();
            $paciente = $this->recordatorios->pacienteParaConsulta($idPaciente)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            if ($rol === 'Paciente' && (string) $paciente->id_usuario !== $idActor) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            // Las esperas de cuenta/planificación/ficha ocurren antes del corte temporal de la consulta.
            $ahora = CarbonImmutable::now('UTC');
            $pagina = $this->recordatorios->listarVigentes($idPaciente, $ahora, $filtros);
            $actual = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ((string) $actual->getKey() !== $idActor || $actual->rol->nombre !== $rol
                || ($rol === 'Paciente' && (string) $actual->paciente?->getKey() !== $idPaciente)) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }

            return ['pagina' => $pagina, 'contexto' => ['zona_horaria' => 'America/La_Paz',
                'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                'filtros' => ['fecha_desde' => $filtros['fecha_desde'] ?? null, 'fecha_hasta' => $filtros['fecha_hasta'] ?? null],
                'paciente' => ['id_paciente' => (string) $paciente->id_paciente, 'codigo_paciente' => $paciente->codigo_paciente,
                    'nombres' => $paciente->nombres, 'apellidos' => $paciente->apellidos]]];
        });
    }

    public function proxima(string $paciente, ?string $operacion = null): array
    {
        return $this->administrar($paciente, function ($actor, $ficha) use ($paciente, $operacion): array {
            $cita = $this->recordatorios->proxima($paciente, CarbonImmutable::now('UTC'));
            $resultado = null;
            if ($operacion !== null && ($aviso = $this->recordatorios->porOperacion($operacion))) {
                $vinculo = $this->recordatorios->localizar((string) $aviso->id_cita);
                if ((string) $aviso->id_administrador === (string) $actor->getKey() && (string) $vinculo?->id_paciente === $paciente) {
                    $resultado = $this->resultadoManual($aviso);
                }
            }

            return ['cita' => $cita === null ? null : [
                'id_cita' => (string) $cita->id_cita, 'revision' => $this->revision($cita),
                'inicio_at' => CarbonImmutable::parse($cita->inicio_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'medico' => trim($cita->medico_nombres.' '.$cita->medico_apellidos),
                'consultorio' => $cita->consultorio_atencion,
                'manual_generado' => $this->recordatorios->manual((string) $cita->id_cita, $cita->version_programacion) !== null,
                'preferencia_habilitada' => $ficha->preferencia?->recordatorios_habilitados ?? true,
            ], 'operacion' => $resultado];
        });
    }

    public function enviarManual(array $datos): array
    {
        return $this->administrar((string) $datos['id_paciente'], function ($actor, $ficha) use ($datos): array {
            // Una repetición exacta devuelve el resultado confirmado, incluso si la cita ya cambió.
            if ($aviso = $this->recordatorios->porOperacion($datos['operacion'])) {
                if ((string) $aviso->id_administrador !== (string) $actor->getKey()
                    || (string) $aviso->id_cita !== (string) $datos['id_cita']
                    || ! hash_equals($aviso->revision_manual, $datos['revision'])
                    || (string) $this->recordatorios->localizar((string) $aviso->id_cita)?->id_paciente !== (string) $datos['id_paciente']) {
                    throw new AppError('RECORDATORIO_OPERACION_CONFLICTO', 'La operación no corresponde a la revisión confirmada.');
                }

                return $this->resultadoManual($aviso);
            }
            $cita = $this->recordatorios->proxima((string) $datos['id_paciente'], CarbonImmutable::now('UTC'));
            if ($cita === null || (string) $cita->id_cita !== (string) $datos['id_cita']
                || ! hash_equals($this->revision($cita), $datos['revision'])) {
                throw new AppError('RECORDATORIO_REVISION_OBSOLETA', 'La próxima cita cambió. Actualiza y confirma nuevamente.');
            }
            if ($this->recordatorios->manual((string) $cita->id_cita, $cita->version_programacion)) {
                throw new AppError('RECORDATORIO_MANUAL_EXISTENTE', 'Esta programación ya tiene un recordatorio manual. Actualiza la consulta.');
            }
            if (! ($ficha->preferencia?->recordatorios_habilitados ?? true)) {
                throw new AppError('RECORDATORIO_PREFERENCIA_DESHABILITADA', 'El Paciente tiene los recordatorios deshabilitados.');
            }
            $this->generarParaCita((string) $cita->id_cita, [
                'origen' => 'Manual', 'id_administrador' => $actor->getKey(),
                'operacion_manual' => $datos['operacion'], 'revision_manual' => $datos['revision'],
                'resultado_push' => 'No_disponible',
            ]);
            $aviso = $this->recordatorios->porOperacion($datos['operacion']);
            if ($aviso === null) {
                throw new AppError('RECORDATORIO_REVISION_OBSOLETA', 'La cita ya no admite el envío. Actualiza y confirma nuevamente.');
            }
            $this->bitacora->recordatorioGenerado($this->citas->porId((string) $cita->id_cita, true), $aviso,
                CarbonImmutable::now('UTC'), $actor);

            return $this->resultadoManual($aviso);
        });
    }

    private function administrar(string $id, callable $accion): array
    {
        return DB::transaction(function () use ($id, $accion): array {
            $this->continuidad->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($actor->rol->nombre !== 'Administrador') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            // El vínculo no se modifica sin continuidad; Usuario siempre antes de planificación/Paciente.
            $inicial = $this->pacientes->consultarSinBloqueo($id);
            if ($inicial?->id_usuario !== null) {
                $this->usuarios->porId($inicial->id_usuario, true);
            }
            $this->planificacion->coordinar();
            $paciente = $this->pacientes->consultar($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');

            $resultado = $accion($actor, $paciente);
            // También caducidad natural de sesión tras esperas; cualquier fallo revierte aviso/auditoría/outbox.
            $actual = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ((string) $actual->getKey() !== (string) $actor->getKey() || $actual->rol->nombre !== 'Administrador') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }

            return $resultado;
        });
    }

    private function revision(object $cita): string
    {
        // Vincula todos los datos revisados, no solo el ID: médico, lugar, estado y programación.
        return hash_hmac('sha256', json_encode($cita, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function resultadoManual($aviso): array
    {
        return ['id_recordatorio' => (string) $aviso->getKey(), 'id_cita' => (string) $aviso->id_cita,
            'aviso_interno' => 'Generado', 'push' => $aviso->resultado_push];
    }

    private function generarParaCita(string $id, array $manual = []): array
    {
        return DB::transaction(function () use ($id, $manual): array {
            $r = ['avisos_creados' => 0, 'avisos_invalidados' => 0, 'envios_preparados' => 0, 'envios_descartados' => 0];
            $this->continuidad->coordinar();
            $inicial = $this->recordatorios->localizar($id);
            if ($inicial === null) {
                return $r;
            }
            // Mismo orden que cuenta/perfil: Usuario antes de planificación/Paciente/cita/suscripción/envío.
            $usuario = $inicial->id_usuario === null ? null : $this->usuarios->porId($inicial->id_usuario, true);
            $this->planificacion->coordinar();
            $paciente = $this->pacientes->consultar($inicial->id_paciente);
            $cita = $this->citas->porId($id, true);
            if (! $paciente || ! $cita || (string) $cita->id_paciente !== (string) $paciente->getKey()) {
                return $r;
            }
            $ahora = CarbonImmutable::now('UTC');
            $r['avisos_invalidados'] = $this->recordatorios->retirarObsoletos($cita, $ahora);
            $preferencia = $paciente->preferencia?->recordatorios_habilitados ?? true;
            $aviso = $manual ? null : $this->recordatorios->porProgramacion($cita);
            $cuenta = $usuario && (string) $paciente->id_usuario === (string) $usuario->getKey()
                && $this->autorizacion->cuentaHabilitada($usuario, (string) $paciente->getKey());
            $habilitadas = [];
            if ($cuenta) {
                $huella = $this->autorizacion->huella($usuario);
                foreach ($this->suscripciones->paraPaciente((string) $usuario->getKey(), (string) $paciente->getKey()) as $s) {
                    if ($s->revocada_en === null && $s->expira_en->isAfter($ahora) && hash_equals($s->huella_cuenta, $huella)) {
                        $habilitadas[(string) $s->getKey()] = $s;
                    }
                }
            }
            $pendientes = $this->envios->pendientesPorCita($id, $manual ? 'Manual' : 'Automatico');
            // Fresco después de todas las esperas de autorización/suscripción/envío.
            $ahora = CarbonImmutable::now('UTC');
            $r['avisos_invalidados'] += $this->recordatorios->retirarObsoletos($cita, $ahora);
            $elegible = $preferencia && in_array($cita->estado, ['Programada', 'Confirmada'], true)
                && ($manual ? $ahora->isBefore($cita->inicio_at)
                    : $ahora->greaterThanOrEqualTo($cita->inicio_at->subHours(24)) && $ahora->isBefore($cita->fin_at));
            if ($elegible && $aviso === null) {
                $aviso = $this->recordatorios->crear($cita, $ahora, $manual);
                if (! $manual) {
                    $this->bitacora->recordatorioGenerado($cita, $aviso, $ahora);
                }
                $r['avisos_creados']++;
            }
            $vigente = $elegible && $aviso !== null && $aviso->invalidado_en === null;
            $push = $vigente && $cuenta && $ahora->isBefore($cita->inicio_at);
            foreach ($pendientes as $e) {
                $s = $habilitadas[(string) $e->id_suscripcion] ?? null;
                if (! $push || (string) $e->id_recordatorio !== (string) $aviso->getKey() || $s === null
                    || ! $s->expira_en->isAfter($ahora) || $e->version_autorizacion !== $s->version_autorizacion
                    || (string) $e->id_usuario !== (string) $usuario->getKey() || (string) $e->id_paciente !== (string) $paciente->getKey()) {
                    $this->envios->descartar($e, $ahora);
                    $r['envios_descartados']++;
                }
            }
            // Canal apagado: no preparar nuevos despachos; los vigentes se conservan sin enviarse.
            if ($push && config('push.habilitado') === true && $this->claves->clavePublicaValida(config('push.vapid_publica'))) {
                foreach ($habilitadas as $s) {
                    $ahora = CarbonImmutable::now('UTC');
                    if (! $ahora->isBefore($cita->inicio_at) || ! $s->expira_en->isAfter($ahora)) {
                        continue;
                    }
                    $r['envios_preparados'] += (int) $this->envios->preparar(['id_recordatorio' => $aviso->getKey(),
                        'id_suscripcion' => $s->getKey(), 'id_usuario' => $usuario->getKey(), 'id_paciente' => $paciente->getKey(),
                        'version_autorizacion' => $s->version_autorizacion, 'estado' => 'Pendiente', 'intentos' => 0,
                        'proximo_intento_en' => $ahora, 'creado_en' => $ahora, 'actualizado_en' => $ahora]);
                }
            }

            if ($manual && $aviso !== null) {
                $this->recordatorios->guardarResultadoManual($aviso, $r['envios_preparados'] > 0 ? 'Pendiente'
                    : ($cuenta && $habilitadas ? 'No_disponible' : 'Sin_dispositivos'));
            }

            return $r;
        });
    }
}
