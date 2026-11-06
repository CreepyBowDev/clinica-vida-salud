<?php

namespace Clinica\data\repositories\pagos;

use Carbon\CarbonImmutable;
use Clinica\data\models\CobroPago;
use Clinica\data\models\EventoPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\OperacionPago;
use Clinica\data\models\Usuario;
use Illuminate\Support\Facades\DB;

/** Consultas específicas PayPal; locks/persistencia comunes conservados en el repositorio F4. */
class PayPalPagoRepository
{
    public function capturador(string $id): ?Usuario
    {
        return Usuario::query()->lockForUpdate()->find($id);
    }

    public function cobro(string $contexto, string $id): ?CobroPago
    {
        return CobroPago::query()->where(['proveedor' => 'paypal', 'entorno' => 'sandbox',
            'cuenta_contexto' => $contexto, 'referencia_cobro' => $id])->first();
    }

    public function serializar(string $contexto, string $id): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['paypal:'.$contexto.':'.$id]);
    }

    public function evento(string $contexto, string $id): ?EventoPago
    {
        return EventoPago::query()->where(['proveedor' => 'paypal', 'entorno' => 'sandbox',
            'cuenta_contexto' => $contexto, 'id_externo' => $id])->lockForUpdate()->first();
    }

    public function eventos(string $contexto, int $limite): array
    {
        return EventoPago::query()->where(['proveedor' => 'paypal', 'cuenta_contexto' => $contexto])
            ->whereIn('estado', ['Pendiente', 'PendienteVinculo', 'Incierto', 'Procesando'])
            ->where('proxima_ejecucion_en', '<=', CarbonImmutable::now('UTC'))->orderBy('recepcion_en')->limit($limite)->get()->all();
    }

    public function vincular(string $contexto, array $refs): ?IntentoPago
    {
        return IntentoPago::query()->where(['proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $contexto])
            ->where(function ($q) use ($refs): void {
                $q->whereRaw('false');
                foreach (['intento' => 'id_intento', 'orden' => 'referencia_inicio', 'captura' => 'referencia_pago'] as $k => $col) {
                    if (! empty($refs[$k])) {
                        $q->orWhere($col, $refs[$k]);
                    }
                }
            })->first();
    }

    public function operaciones(string $contexto, int $limite): array
    {
        return OperacionPago::query()->where(['proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $contexto])
            ->whereIn('estado', ['Pendiente', 'Procesando', 'Incierta'])
            ->where('proxima_ejecucion_en', '<=', CarbonImmutable::now('UTC'))->orderBy('creada_en')->limit($limite)->pluck('id_operacion')->all();
    }

    public function revisables(string $contexto, int $limite): array
    {
        return IntentoPago::query()->where(['proveedor' => 'paypal', 'entorno' => 'sandbox', 'cuenta_contexto' => $contexto])
            ->whereIn('estado', StripePagoRepository::ACTIVOS)->where('proxima_revision_en', '<=', CarbonImmutable::now('UTC'))
            ->orderBy('proxima_revision_en')->limit($limite)->get()->all();
    }

    public function solicitud(string $intento, string $actor, string $clave): ?OperacionPago
    {
        return OperacionPago::query()->where(['id_intento' => $intento, 'solicitada_por' => $actor, 'clave_solicitud' => $clave])->first();
    }
}
