<?php

namespace Clinica\data\repositories\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\Cita;
use Clinica\data\models\CobroPago;
use Clinica\data\models\DevolucionPago;
use Clinica\data\models\EventoPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\OperacionPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StripePagoRepository
{
    public const ACTIVOS = ['Preparando', 'RequiereAccion', 'Procesando', 'Incierto'];

    public function serializarEvento(string $cuenta, string $evento): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['stripe:test:'.$cuenta.':'.$evento]);
    }

    public function guardar(Model $modelo, array $datos): void
    {
        $modelo->forceFill($datos)->saveOrFail();
    }

    public function obligacion(string $id, ?string $paciente = null): ?ObligacionPago
    {
        return ObligacionPago::query()->when($paciente !== null,
            fn ($q) => $q->whereHas('cita', fn ($c) => $c->where('id_paciente', $paciente)))->find($id);
    }

    /** Orden común con gestión: Cita → obligación → intento/operación. */
    public function bloquear(string $id): ObligacionPago
    {
        $o = ObligacionPago::query()->findOrFail($id);
        Cita::query()->whereKey($o->id_cita)->lockForUpdate()->firstOrFail();

        return ObligacionPago::query()->lockForUpdate()->findOrFail($id);
    }

    public function intento(string $id): ?IntentoPago
    {
        return IntentoPago::query()->find($id);
    }

    public function activo(string $id): ?IntentoPago
    {
        return IntentoPago::query()->where('id_obligacion', $id)->whereIn('estado', self::ACTIVOS)->first();
    }

    public function historialIntentos(string $obligacion): array
    {
        return IntentoPago::query()->with('operaciones')->where('id_obligacion', $obligacion)->get()->all();
    }

    public function cierrePayPalSolicitado(string $intento): bool
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'tipo' => 'Consultar'])
            ->get()->contains(fn ($op) => ($op->datos_solicitud_cifrados['solicitud'] ?? null) === 'Cerrar');
    }

    public function cobro(string $id): ?CobroPago
    {
        return CobroPago::query()->where('id_obligacion', $id)->where('aplicado', true)->first();
    }

    public function cobroReferencia(string $cuenta, string $referencia): ?CobroPago
    {
        return CobroPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta,
            'referencia_cobro' => $referencia])->first();
    }

    public function devolucion(string $id): ?DevolucionPago
    {
        return DevolucionPago::query()->where('id_cobro', $id)->first();
    }

    public function operacion(string $id): ?OperacionPago
    {
        return OperacionPago::query()->find($id);
    }

    public function creacion(string $intento): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'tipo' => 'Crear'])->first();
    }

    public function replay(string $obligacion, string $actor, string $clave): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_obligacion' => $obligacion, 'solicitada_por' => $actor,
            'clave_solicitud' => $clave, 'tipo' => 'Crear'])->first();
    }

    public function solicitudIntento(string $intento, string $actor, string $clave): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'solicitada_por' => $actor,
            'clave_solicitud' => $clave])->whereIn('tipo', ['Cerrar', 'Consultar'])->first();
    }

    public function escritura(string $obligacion): ?OperacionPago
    {
        return OperacionPago::query()->where('id_obligacion', $obligacion)->where('tipo', '<>', 'Consultar')
            ->whereIn('estado', ['Pendiente', 'Procesando', 'Incierta'])->first();
    }

    public function porTipo(string $intento, string $tipo): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'tipo' => $tipo])->orderBy('creada_en')->first();
    }

    public function pendientes(int $lote, string $cuenta): array
    {
        return OperacionPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta])
            ->whereIn('estado', ['Pendiente', 'Incierta', 'Procesando'])
            ->where('proxima_ejecucion_en', '<=', CarbonImmutable::now('UTC'))->orderBy('proxima_ejecucion_en')
            ->limit($lote)->pluck('id_operacion')->all();
    }

    public function intentosRevisables(int $lote, string $cuenta): array
    {
        return IntentoPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta])
            ->whereIn('estado', self::ACTIVOS)->where('proxima_revision_en', '<=', CarbonImmutable::now('UTC'))
            ->orderBy('proxima_revision_en')->limit($lote)->get()->all();
    }

    public function evento(string $cuenta, string $externo): ?EventoPago
    {
        return EventoPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta,
            'id_externo' => $externo])->lockForUpdate()->first();
    }

    public function eventoBloqueado(string $id): EventoPago
    {
        return EventoPago::query()->lockForUpdate()->findOrFail($id);
    }

    public function anuladasSinDespacho(string $proveedor, string $cuenta, int $lote): array
    {
        return ObligacionPago::query()->where('exigible', false)
            ->whereHas('liquidacion', fn ($c) => $c->where(['proveedor' => $proveedor, 'cuenta_contexto' => $cuenta])
                ->where(fn ($q) => $q->whereDoesntHave('devolucion')->orWhereHas('devolucion',
                    fn ($d) => $d->where('estado', 'Pendiente')->whereDoesntHave('operaciones'))))
            ->orderBy('emitida_en')->limit($lote)->pluck('id_obligacion')->all();
    }

    public function eventos(int $lote, string $cuenta): array
    {
        return EventoPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta])
            ->whereIn('estado', ['Pendiente', 'PendienteVinculo', 'Incierto', 'Procesando'])
            ->where('proxima_ejecucion_en', '<=', CarbonImmutable::now('UTC'))->orderBy('proxima_ejecucion_en')
            ->orderBy('id_evento')->limit($lote)->get()->all();
    }

    public function vincular(array $referencias, string $cuenta): ?IntentoPago
    {
        return IntentoPago::query()->where(['proveedor' => 'stripe', 'entorno' => 'test', 'cuenta_contexto' => $cuenta])
            ->where(function ($q) use ($referencias): void {
                $q->whereRaw('false');
                foreach (['intento' => 'id_intento', 'sesion' => 'referencia_inicio', 'pago' => 'referencia_pago'] as $k => $col) {
                    if (! empty($referencias[$k])) {
                        $q->orWhere($col, $referencias[$k]);
                    }
                }
            })->first();
    }
}
