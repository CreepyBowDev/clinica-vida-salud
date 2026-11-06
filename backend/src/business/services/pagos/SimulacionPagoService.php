<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\CobroPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pagos\SimulacionPagoRepository;
use Clinica\data\repositories\pagos\StripePagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Recorrido académico sin gateways ni evidencia de proveedores. */
class SimulacionPagoService
{
    public function __construct(private StripePagoRepository $repo, private SimulacionPagoRepository $academico,
        private SeguridadInicioPagoService $seguridad, private AutenticacionService $auth,
        private BitacoraRepository $bitacora, private DevolucionCancelacionService $devoluciones,
        private HabilitacionAcademicaService $habilitacion, private ReciboAcademicoService $recibos) {}

    public function habilitada(): bool
    {
        return $this->habilitacion->permite() && $this->academico->disponible();
    }

    private function exigir(): void
    {
        if (! $this->habilitada()) {
            throw new AppError('PAGO_NO_CONFIGURADO', 'El pago académico simulado no está habilitado.');
        }
    }

    public function elegibilidad(ObligacionPago $o, string $actor): array
    {
        $puede = $this->habilitada() && $o->exigible && $o->cita->estado !== 'Cancelada'
            && $this->seguridad->disponible((string) $o->getKey());

        return ['habilitada' => $this->habilitada(), 'puede_iniciar' => $puede,
            'revision' => $puede ? Crypt::encryptString(json_encode(['o' => (string) $o->getKey(), 'a' => $actor,
                'bob' => $o->centavos_bob, 'usd' => $o->centavos_usd,
                'vence' => CarbonImmutable::now('UTC')->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR)) : null];
    }

    private function titular(string $id): array
    {
        $actor = $this->auth->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Paciente' || ! $actor->paciente || $actor->medico !== null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $o = $this->repo->obligacion($id, (string) $actor->paciente->getKey())
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');

        return [$actor, $this->repo->bloquear((string) $o->getKey())];
    }

    private function exigible(ObligacionPago $o): void
    {
        if (! $o->exigible || $o->cita->estado === 'Cancelada') {
            throw new AppError('PAGO_NO_EXIGIBLE', 'La obligación ya no es exigible.');
        }
    }

    public function iniciar(array $d): array
    {
        $this->exigir();

        return DB::transaction(function () use ($d): array {
            [$actor, $o] = $this->titular($d['objetivo']);
            $huella = hash('sha256', json_encode([$d['modalidad'], $d['escenario'], $d['revision']], JSON_THROW_ON_ERROR));
            if ($op = $this->repo->replay($d['objetivo'], (string) $actor->getKey(), $d['clave'])) {
                if ($op->proveedor !== 'simulado' || ! hash_equals($op->huella_solicitud, $huella)) {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }

                return $this->respuesta($this->repo->intento((string) $op->id_intento), true);
            }
            try {
                $r = json_decode(Crypt::decryptString($d['revision']), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $r = [];
            }
            if (($r['o'] ?? null) !== $d['objetivo'] || ($r['a'] ?? null) !== (string) $actor->getKey()
                || ($r['bob'] ?? null) !== $o->centavos_bob || ($r['usd'] ?? null) !== $o->centavos_usd
                || ($r['vence'] ?? 0) <= CarbonImmutable::now('UTC')->timestamp) {
                throw new AppError('PAGO_REVISION_OBSOLETA', 'Revisa nuevamente el importe antes de pagar.');
            }
            $this->exigible($o);
            if (! $this->seguridad->disponible((string) $o->getKey())) {
                throw new AppError('PAGO_OPERACION_ACTIVA', 'Existe un pago o una operación pendiente de verificación.');
            }
            $i = new IntentoPago;
            $ahora = CarbonImmutable::now('UTC');
            $this->repo->guardar($i, ['id_intento' => (string) Str::uuid(), 'id_obligacion' => $o->getKey(),
                'iniciado_por' => $actor->getKey(), 'origen' => 'Simulado', 'proveedor' => 'simulado',
                'entorno' => app()->environment('academic') ? 'academic' : 'local', 'cuenta_contexto' => 'academico', 'modalidad' => $d['modalidad'],
                'escenario' => $d['escenario'], 'estado' => 'RequiereAccion', 'creado_en' => $ahora, 'actualizado_en' => $ahora]);
            $this->operacion($i, 'Crear', $actor, $d['clave'], $huella);

            return $this->respuesta($i);
        });
    }

    public function actuar(array $d, string $accion): array
    {
        $this->exigir();

        return DB::transaction(function () use ($d, $accion): array {
            $i = $this->repo->intento($d['objetivo'])
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            [$actor, $o] = $this->titular((string) $i->id_obligacion);
            $i->refresh();
            if ($i->proveedor !== 'simulado' || $i->origen !== 'Simulado' || (string) $i->iniciado_por !== (string) $actor->getKey()) {
                throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'El intento no corresponde a este recorrido académico.');
            }
            $tipo = match ($accion) { 'aprobar' => 'Aprobar', 'confirmar' => 'Confirmar', 'capturar' => 'Capturar', 'cerrar' => 'Cerrar' };
            if ($anterior = $this->academico->solicitud((string) $i->getKey(), (string) $actor->getKey(), $d['clave'])) {
                if ($anterior->tipo !== $tipo) {
                    throw new AppError('PAGO_IDEMPOTENCIA_CONFLICTO', 'La clave ya se utilizó con otros datos.');
                }
                return $this->respuesta($i, true);
            }
            $this->exigible($o);
            if ($i->estado !== 'RequiereAccion' || $this->repo->cobro((string) $o->getKey())
                || $this->repo->escritura((string) $o->getKey())) {
                throw new AppError('PAGO_OPERACION_ACTIVA', 'El intento ya no admite esa operación.');
            }
            $ahora = CarbonImmutable::now('UTC');
            if ($accion === 'cerrar') {
                $this->repo->guardar($i, ['estado' => 'Cerrado', 'actualizado_en' => $ahora]);
                $this->repo->guardar($this->repo->creacion((string) $i->getKey()),
                    ['resultado_codigo' => 'CIERRE_SIN_COBRO_VERIFICADO', 'actualizada_en' => $ahora]);
            } elseif ($accion === 'aprobar') {
                if ($i->modalidad !== 'paypal' || $i->escenario !== 'completado') {
                    throw new AppError('PAGO_CAPTURA_NO_PERMITIDA', 'Este intento no admite aprobación.');
                }
                $this->repo->guardar($i, ['aprobada_en' => $i->aprobada_en ?? $ahora, 'actualizado_en' => $ahora]);
            } else {
                if (($accion === 'capturar') !== ($i->modalidad === 'paypal')) {
                    throw new AppError('PAGO_PROVEEDOR_INCOMPATIBLE', 'La acción no corresponde a esta modalidad.');
                }
                if ($i->modalidad === 'paypal' && ! $i->aprobada_en) {
                    throw new AppError('PAGO_APROBACION_REQUERIDA', 'La aprobación académica debe preceder a la captura.');
                }
                if ($i->escenario === 'completado') {
                    $c = new CobroPago;
                    $uuid = (string) Str::uuid();
                    $this->repo->guardar($c, ['id_cobro' => $uuid, 'id_intento' => $i->getKey(), 'id_obligacion' => $o->getKey(),
                        'proveedor' => 'simulado', 'entorno' => $i->entorno, 'cuenta_contexto' => 'academico',
                        'referencia_cobro' => 'SIM-'.$uuid, 'centavos_usd' => $o->centavos_usd, 'aplicado' => true,
                        'confirmado_en' => $ahora, 'registrado_en' => $ahora]);
                    $this->recibos->emitir($o, $i, $c);
                    $this->repo->guardar($i, ['estado' => 'Exitoso', 'actualizado_en' => $ahora]);
                }
            }
            $this->operacion($i, $tipo, $actor, $d['clave'], hash('sha256', $accion));

            return $this->respuesta($i);
        });
    }

    private function operacion(IntentoPago $i, string $tipo, Usuario $actor, string $clave, string $huella): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $op = new OperacionPago;
        $this->repo->guardar($op, ['id_operacion' => (string) Str::uuid(), 'id_intento' => $i->getKey(),
            'id_obligacion' => $i->id_obligacion, 'proveedor' => 'simulado', 'entorno' => $i->entorno, 'cuenta_contexto' => 'academico',
            'tipo' => $tipo, 'solicitada_por' => $actor->getKey(), 'clave_solicitud' => $clave, 'huella_solicitud' => $huella,
            'estado' => 'Exitosa', 'resultado_codigo' => $tipo === 'Cerrar' ? 'CIERRE_SIN_COBRO_VERIFICADO' : 'SIMULACION_LOCAL_REGISTRADA',
            'proxima_ejecucion_en' => $ahora, 'creada_en' => $ahora, 'actualizada_en' => $ahora]);
        $this->bitacora->pago('PAGO_SIMULADO_'.strtoupper($tipo), (string) $i->id_obligacion,
            ['id_intento' => (string) $i->getKey(), 'origen' => 'Simulado', 'modalidad' => $i->modalidad], $actor);
    }

    private function respuesta(IntentoPago $i, bool $repetida = false): array
    {
        return ['id' => (string) $i->getKey(), 'obligacion_id' => (string) $i->id_obligacion, 'origen' => 'Simulado',
            'estado' => $i->estado, 'modalidad' => $i->modalidad, 'escenario' => $i->escenario,
            'aprobada' => $i->aprobada_en !== null, 'liquidada' => $this->repo->cobro((string) $i->id_obligacion) !== null,
            'repetida' => $repetida];
    }

    /** Coordinación local bajo los locks de la cancelación, aunque se desactive la entrada académica. */
    public function coordinarCancelacion(ObligacionPago $o): void
    {
        if (DB::transactionLevel() === 0 || $o->exigible || ! $o->id_cambio_anulacion
            || $o->cita->estado !== 'Cancelada' || $o->cambioAnulacion->operacion !== 'CANCELACION'
            || (string) $o->cambioAnulacion->id_cita !== (string) $o->id_cita) {
            return;
        }
        $i = $this->repo->activo((string) $o->getKey());
        if ($i?->proveedor === 'simulado') {
            $this->repo->guardar($i, ['estado' => 'Cerrado', 'actualizado_en' => CarbonImmutable::now('UTC')]);
            $this->bitacora->pago('PAGO_SIMULADO_CANCELADO', (string) $o->getKey(), ['id_intento' => (string) $i->getKey()]);
        }
        $c = $this->repo->cobro((string) $o->getKey());
        if ($c?->proveedor === 'simulado') {
            $d = $this->devoluciones->preparar($o, $c);
            if ($d->estado !== 'Exitosa') {
                $this->repo->guardar($d, ['estado' => 'Exitosa', 'actualizada_en' => CarbonImmutable::now('UTC')]);
                $this->bitacora->pago('PAGO_SIMULADO_DEVUELTO', (string) $o->getKey(),
                    ['id_cobro' => (string) $c->getKey(), 'id_devolucion' => (string) $d->getKey(), 'origen' => 'Simulado']);
            }
        }
    }
}
