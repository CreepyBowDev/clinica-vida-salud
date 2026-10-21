<?php

use Clinica\presentation\controllers\medicos\MedicoController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('medicos')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('/', [MedicoController::class, 'index']);
        Route::post('/', [MedicoController::class, 'store']);
        Route::get('{id}', [MedicoController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}', [MedicoController::class, 'update'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/estado', [MedicoController::class, 'estado'])->where('id', '[1-9][0-9]{0,18}');
        Route::put('{id}/especialidades', [MedicoController::class, 'especialidades'])->where('id', '[1-9][0-9]{0,18}');
    });
