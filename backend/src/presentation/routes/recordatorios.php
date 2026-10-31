<?php

use Clinica\presentation\controllers\recordatorios\RecordatorioController;
use Clinica\presentation\controllers\recordatorios\SuscripcionPushController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::get('recordatorios', [RecordatorioController::class, 'listar'])->middleware(['auth:sanctum',
    VerificarCuentaHabilitada::class, ExigirSesionNormal::class, ExigirRol::class.':Administrador,Paciente'])->name('recordatorios.lista');

Route::prefix('push')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Paciente', 'throttle:60,1'])->group(function (): void {
        Route::get('configuracion', [SuscripcionPushController::class, 'configuracion'])->name('push.configuracion');
        Route::get('suscripciones', [SuscripcionPushController::class, 'listar'])->name('push.lista');
        Route::put('suscripcion', [SuscripcionPushController::class, 'autorizar'])->name('push.autorizar');
        Route::delete('suscripcion', [SuscripcionPushController::class, 'revocar'])->name('push.revocar');
        Route::delete('suscripciones/{id}', [SuscripcionPushController::class, 'revocar'])->name('push.revocar-id');
    });
