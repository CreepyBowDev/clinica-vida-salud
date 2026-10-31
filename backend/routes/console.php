<?php

use Clinica\business\services\recordatorios\DespachoPushService;
use Clinica\business\services\recordatorios\RecordatorioService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
