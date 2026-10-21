<?php

use Clinica\presentation\controllers\bitacora\BitacoraController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('bitacora')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('/', [BitacoraController::class, 'index']);
        Route::get('{id}', [BitacoraController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
    });
