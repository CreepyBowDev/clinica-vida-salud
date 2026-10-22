<?php

use Clinica\presentation\controllers\citas\ReservaController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class,
    ExigirSesionNormal::class, ExigirRol::class.':Recepcionista,Paciente'])->group(function (): void {
        Route::post('reservas/propuesta', [ReservaController::class, 'propuesta']);
        Route::post('reservas/resultado', [ReservaController::class, 'resultado']);
        Route::post('citas', [ReservaController::class, 'store']);
    });
