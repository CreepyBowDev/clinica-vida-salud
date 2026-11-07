<?php

namespace Clinica\business\services\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\data\models\EnvioRecordatorioPush;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\pacientes\PacienteRepository;
use Clinica\data\repositories\recordatorios\EnvioRecordatorioPushRepository;
use Clinica\data\repositories\recordatorios\RecordatorioRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\TransportePush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class DespachoPushService
{
    // DNS 3 s + HTTP 10 s; margen para criptografía, procesos y SQL. Nunca se reclama de nuevo un lease vencido.
    private const LEASE_SEGUNDOS = 60;

    public function __construct(
        private EnvioRecordatorioPushRepository $envios,
        private RecordatorioRepository $recordatorios,
        private SuscripcionPushRepository $suscripciones,
        private UsuarioRepository $usuarios,
        private PacienteRepository $pacientes,
        private CitaRepository $citas,
        private PlanificacionRepository $planificacion,
        private ContinuidadAdministrativaService $continuidad,
        private AutorizacionPushValidator $autorizacion,
        private BitacoraRepository $bitacora,
        private TransportePush $transporte,
    ) {}

    public function despachar(int $lote = 100): array
    {
        if ($lote < 1 || $lote > 1000) {
            throw new InvalidArgumentException('El tamaño de lote debe estar entre 1 y 1000.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('El despacho requiere ejecutarse fuera de una transacción externa.');
        }
        $r = ['envios_revisados' => 0, 'transportes_invocados' => 0, 'configuracion_invalida' => false];
        $hasta = $this->envios->ultimoId();
        $cursor = '0';
        while ($ids = $this->envios->candidatos($cursor, $hasta, $lote, CarbonImmutable::now('UTC'))) {
            foreach ($ids as $id) {
                $cursor = $id;
                $r['envios_revisados']++;
                $reclamo = $this->preparar($id);
                if ($reclamo === null) {
                    continue;
                }
                // Segunda transacción corta: no confiar en el contexto del claim tras liberar sus locks.
                $salida = $this->preparar($id, $reclamo['token']);
                if ($salida === null) {
                    continue;
                }
                try {
                    $r['transportes_invocados']++;
                    $resultado = $this->transporte->enviar($salida['datos'], $salida['inicio']);
                } catch (Throwable $error) {
                    // Un adaptador que lanza no acredita ausencia de envío, salvo el error previo de configuración.
                    $resultado = ['tipo' => $error instanceof AppError && $error->codigo === 'PUSH_NO_CONFIGURADO'
                        ? 'Configuracion' : 'Incierto', 'retry_after' => null];
                }
                $this->finalizar($id, $reclamo['token'], $resultado);
                if (($resultado['tipo'] ?? null) === 'Configuracion') {
                    $r['configuracion_invalida'] = true;

                    return $r; // No gastar todos los dispositivos del lote con una configuración inválida.
                }
            }
        }

        return $r;
    }

    private function preparar(string $id, ?string $token = null): ?array
    {
        return DB::transaction(function () use ($id, $token): ?array {
            $this->continuidad->coordinar();
            $inicial = $this->envios->localizar($id);
            if ($inicial === null) {
                return null;
            }
            // Orden compartido con generación, perfil, gestión y revocación de cuentas.
            $usuario = $this->usuarios->porId($inicial->id_usuario, true);
            $this->planificacion->coordinar();
            $paciente = $this->pacientes->consultar($inicial->id_paciente);
            $cita = $this->citas->porId($inicial->id_cita, true);
            $aviso = $this->recordatorios->porId($inicial->id_recordatorio);
            $s = $this->suscripciones->porId($inicial->id_suscripcion);
            $e = $this->envios->bloquear($id);
            $ahora = CarbonImmutable::now('UTC'); // Después de todas las esperas.
            if ($e === null || ($token !== null && ($e->estado !== 'Procesando' || $e->codigo_reclamo !== $token))) {
                return null;
            }
            if ($e->estado === 'Procesando' && ! $e->reclamado_hasta->isAfter($ahora)) {
                $this->terminar($e, 'Incierto', 'LEASE_VENCIDO', $ahora);

                return null;
            }
            if ($token === null && ($e->estado !== 'Pendiente' || $e->proximo_intento_en->isAfter($ahora))) {
                return null;
            }
            $vigente = $usuario && $paciente && $cita && $aviso && $s
                && (string) $paciente->id_usuario === (string) $e->id_usuario
                && (string) $cita->id_paciente === (string) $e->id_paciente
                && $this->autorizacion->cuentaHabilitada($usuario, (string) $e->id_paciente)
                && $aviso->invalidado_en === null && $aviso->version_programacion === $cita->version_programacion
                && $aviso->generado_en->lessThanOrEqualTo($ahora)
                && in_array($cita->estado, ['Programada', 'Confirmada'], true)
                && ($aviso->origen === 'Manual' || $ahora->greaterThanOrEqualTo($cita->inicio_at->subHours(24)))
                && $ahora->isBefore($cita->inicio_at)
                && ($paciente->preferencia?->recordatorios_habilitados ?? true)
                && (string) $s->id_usuario === (string) $e->id_usuario && (string) $s->id_paciente === (string) $e->id_paciente
                && $s->version_autorizacion === $e->version_autorizacion && $s->revocada_en === null
                && $s->autorizada_en->lessThanOrEqualTo($ahora) && $s->expira_en->isAfter($ahora)
                && hash_equals($s->huella_cuenta, $this->autorizacion->huella($usuario));
            if (! $vigente) {
                $this->terminar($e, 'Descartado', 'CONTEXTO_NO_VIGENTE', $ahora);

                return null;
            }
            if (config('push.habilitado') !== true) {
                if ($token !== null) {
                    $this->terminar($e, 'Descartado', 'CANAL_DESHABILITADO', $ahora);
                }

                return null;
            }
            if ($token !== null) {
                return ['datos' => $s->datos_cifrados, 'inicio' => $cita->inicio_at];
            }
            if ($e->intentos >= 3) {
                $this->terminar($e, 'Fallido', 'INTENTOS_AGOTADOS', $ahora);

                return null;
            }
            $token = (string) Str::uuid();
            $this->envios->guardar($e, ['estado' => 'Procesando', 'intentos' => $e->intentos + 1,
                'codigo_reclamo' => $token, 'reclamado_hasta' => $ahora->addSeconds(self::LEASE_SEGUNDOS)], $ahora);

            return ['token' => $token];
        });
    }

    private function finalizar(string $id, string $token, array $resultado): void
    {
        DB::transaction(function () use ($id, $token, $resultado): void {
            $this->continuidad->coordinar();
            $inicial = $this->envios->localizar($id);
            if ($inicial === null) {
                return;
            }
            $this->usuarios->porId($inicial->id_usuario, true);
            $s = $this->suscripciones->porId($inicial->id_suscripcion);
            $e = $this->envios->bloquear($id);
            $ahora = CarbonImmutable::now('UTC');
            if ($e === null || $e->estado !== 'Procesando' || $e->codigo_reclamo !== $token) {
                return;
            }
            if (! $e->reclamado_hasta->isAfter($ahora)) {
                $this->terminar($e, 'Incierto', 'LEASE_VENCIDO', $ahora);

                return;
            }
            $tipo = $resultado['tipo'] ?? 'Incierto';
            if ($tipo === 'Obsoleta' && $s && $s->version_autorizacion === $e->version_autorizacion
                && (string) $s->id_usuario === (string) $e->id_usuario && (string) $s->id_paciente === (string) $e->id_paciente
                && $s->revocada_en === null) {
                $this->suscripciones->revocar($s);
                $this->bitacora->suscripcionPushObsoleta((string) $s->getKey(), $s->version_autorizacion, $ahora);
            }
            if (in_array($tipo, ['Reintentable', 'NoEnviado'], true) && $e->intentos < 3) {
                $espera = max($e->intentos === 1 ? 60 : 300, min(4294967295, max(0, (int) ($resultado['retry_after'] ?? 0))));
                $proximo = $ahora->addSeconds($espera);
                // Releer inicio actual; la elegibilidad completa se revisará de nuevo al reclamar.
                $inicio = $this->envios->localizar($id);
                $cita = $this->citas->porId($inicio->id_cita);
                if ($cita && $proximo->isBefore($cita->inicio_at)) {
                    $this->envios->guardar($e, ['estado' => 'Pendiente', 'codigo_reclamo' => null,
                        'reclamado_hasta' => null, 'proximo_intento_en' => $proximo, 'resultado_seguro' => $tipo], $ahora);

                    return;
                }
                $this->terminar($e, 'Descartado', 'SIN_VENTANA_REINTENTO', $ahora);

                return;
            }
            $estado = match ($tipo) {
                'Aceptado' => 'Aceptado', 'Obsoleta' => 'Descartado',
                'Fallido', 'Configuracion', 'Reintentable', 'NoEnviado' => 'Fallido',
                default => 'Incierto',
            };
            $seguro = in_array($tipo, ['Aceptado', 'Obsoleta', 'Fallido', 'Configuracion', 'Reintentable', 'NoEnviado'], true) ? $tipo : 'Incierto';
            $this->terminar($e, $estado, $seguro, $ahora);
        });
    }

    private function terminar(EnvioRecordatorioPush $e, string $estado, string $resultado, CarbonImmutable $ahora): void
    {
        $this->envios->guardar($e, ['estado' => $estado, 'resultado_seguro' => $resultado,
            'codigo_reclamo' => null, 'reclamado_hasta' => null], $ahora);
        $contexto = $this->envios->localizar((string) $e->getKey());
        if ($contexto?->id_administrador !== null) {
            $this->bitacora->despachoManualFinalizado($contexto, $estado, $resultado, $ahora);
        }
    }
}
