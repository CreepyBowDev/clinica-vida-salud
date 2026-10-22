<?php

use Clinica\presentation\controllers\horarios\ConfiguracionCitasController;
use Clinica\presentation\controllers\horarios\HorarioController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::prefix('medicos/{id}/horarios')->where(['id' => '[1-9][0-9]{0,18}', 'horario' => '[1-9][0-9]{0,18}'])->group(function (): void {
            Route::get('/', [HorarioController::class, 'index']);
            Route::post('/', [HorarioController::class, 'store']);
            Route::post('impacto', [HorarioController::class, 'impacto']);
            Route::patch('{horario}', [HorarioController::class, 'update']);
            Route::patch('{horario}/estado', [HorarioController::class, 'estado']);
        });
        Route::get('configuracion-citas', [ConfiguracionCitasController::class, 'show']);
        Route::post('configuracion-citas/impacto', [ConfiguracionCitasController::class, 'impacto']);
        Route::patch('configuracion-citas', [ConfiguracionCitasController::class, 'update']);
    });
