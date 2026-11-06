<?php

use Clinica\business\services\pagos\ConciliacionPagosService;
use Clinica\business\services\pagos\EventoPayPalService;
use Clinica\business\services\pagos\EventoStripeService;
use Clinica\business\services\pagos\RecuperacionPayPalService;
use Clinica\business\services\pagos\RecuperacionStripeService;
use Clinica\business\services\recordatorios\DespachoPushService;
use Clinica\business\services\recordatorios\RecordatorioService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pagos:recuperar-stripe {--lote=100}', function (RecuperacionStripeService $service, EventoStripeService $eventos) {
    $lote = (string) $this->option('lote');
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/', $lote) || (int) $lote > 1000) {
        $this->error('El lote debe estar entre 1 y 1000.');

        return 1;
    }
    if (config('pagos.stripe.habilitado') !== true) {
        $this->info('Stripe de prueba deshabilitado; no se procesa trabajo.');

        return 0;
    }
    try {
        $this->info(json_encode([...$service->lote((int) $lote), 'eventos_revisados' => $eventos->lote((int) $lote)], JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable) {
        $this->error('No se pudo completar la recuperación Stripe; el trabajo durable se conserva.');

        return 1;
    }
})->purpose('Recuperar exclusivamente operaciones y eventos Stripe test autorizados');

Artisan::command('pagos:recuperar-paypal {--lote=100}', function (
    RecuperacionPayPalService $service,
    EventoPayPalService $eventos
) {
    $lote = (string) $this->option('lote');
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/', $lote) || (int) $lote > 1000) {
        $this->error('El lote debe estar entre 1 y 1000.');

        return 1;
    }
    if (config('pagos.paypal.habilitado') !== true) {
        $this->info('PayPal Sandbox deshabilitado; no se procesa trabajo.');

        return 0;
    }
    try {
        $this->info(json_encode(['eventos_revisados' => $eventos->lote((int) $lote), ...$service->lote((int) $lote)], JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable) {
        $this->error('No se pudo completar la recuperación PayPal; el trabajo durable se conserva.');

        return 1;
    }
})->purpose('Recuperar exclusivamente operaciones y eventos PayPal Sandbox');

Artisan::command('pagos:conciliar {--lote=100}', function (ConciliacionPagosService $service) {
    $lote = (string) $this->option('lote');
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/', $lote) || (int) $lote > 1000) {
        $this->error('El lote debe estar entre 1 y 1000.');

        return 1;
    }
    $resultado = $service->lote((int) $lote);
    $this->info(json_encode($resultado, JSON_THROW_ON_ERROR));

    return collect($resultado)->contains(fn ($r) => $r['error'] ?? false) ? 1 : 0;
})->purpose('Conciliar exclusivamente Stripe test y PayPal Sandbox habilitados');

Schedule::command('pagos:conciliar')->everyMinute()->withoutOverlapping(10);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('recordatorios:generar {--lote=100}', function (RecordatorioService $service) {
    $lote = (string) $this->option('lote');
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/', $lote) || (int) $lote > 1000) {
        $this->error('El lote debe ser un entero entre 1 y 1000.');

        return 1;
    }
    try {
        $r = $service->generar((int) $lote);
        $this->info(json_encode($r, JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable) {
        // La consola no expone SQL, claves o datos de pacientes. Los lotes confirmados se reconcilian al reejecutar.
        $this->error('No se pudo completar la generación. Revisa el servicio y vuelve a ejecutar el comando.');

        return 1;
    }
})->purpose('Generar avisos internos y preparar despachos pendientes, sin enviarlos');

Schedule::command('recordatorios:generar')->everyMinute()->withoutOverlapping(10);

Artisan::command('recordatorios:despachar {--lote=100}', function (DespachoPushService $service) {
    $lote = (string) $this->option('lote');
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/', $lote) || (int) $lote > 1000) {
        $this->error('El lote debe ser un entero entre 1 y 1000.');

        return 1;
    }
    try {
        $r = $service->despachar((int) $lote);
        $this->info(json_encode($r, JSON_THROW_ON_ERROR));

        return $r['configuracion_invalida'] ? 1 : 0;
    } catch (Throwable) {
        $this->error('No se pudo completar el despacho. Revisa el servicio; no reenvíes resultados inciertos.');

        return 1;
    }
})->purpose('Despachar outbox push autorizado con reintentos acotados, sin repetir resultados inciertos');

Schedule::command('recordatorios:despachar')->everyMinute()->withoutOverlapping(10);
