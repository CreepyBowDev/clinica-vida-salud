<?php

use Clinica\presentation\controllers\medicos\EspecialidadController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('especialidades')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('/', [EspecialidadController::class, 'index']);
        Route::post('/', [EspecialidadController::class, 'store']);
        Route::get('{id}', [EspecialidadController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}', [EspecialidadController::class, 'update'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/estado', [EspecialidadController::class, 'estado'])->where('id', '[1-9][0-9]{0,18}');
    });
