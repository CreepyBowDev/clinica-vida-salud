<?php

use Clinica\presentation\controllers\consultorios\ConsultorioController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('consultorios')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('/', [ConsultorioController::class, 'index']);
        Route::post('/', [ConsultorioController::class, 'store']);
        Route::get('{id}', [ConsultorioController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}', [ConsultorioController::class, 'update'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/estado', [ConsultorioController::class, 'estado'])->where('id', '[1-9][0-9]{0,18}');
    });
