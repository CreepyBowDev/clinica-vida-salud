<?php

use Clinica\presentation\controllers\usuarios\CredencialTemporalController;
use Clinica\presentation\controllers\usuarios\CuentaController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\LimitarCredencialTemporal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('usuarios')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('/', [CuentaController::class, 'index']);
        Route::get('{id}', [CuentaController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/estado', [CuentaController::class, 'estado'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/rol', [CuentaController::class, 'rol'])->where('id', '[1-9][0-9]{0,18}');
        Route::post('{id}/credencial-temporal', [CredencialTemporalController::class, 'emitir'])
            ->middleware(LimitarCredencialTemporal::class.':emitir')->where('id', '[1-9][0-9]{0,18}');
    });
