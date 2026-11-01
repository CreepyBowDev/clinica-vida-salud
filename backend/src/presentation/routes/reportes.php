<?php

use Clinica\presentation\controllers\reportes\ReporteController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('reportes')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class,
    ExigirSesionNormal::class, ExigirRol::class.':Administrador'])->group(function (): void {
        Route::get('citas/programadas', [ReporteController::class, 'citas'])->defaults('reporte', 'citas/programadas');
        Route::get('citas/por-medico', [ReporteController::class, 'citas'])->defaults('reporte', 'citas/por-medico');
        Route::get('citas/por-especialidad', [ReporteController::class, 'citas'])->defaults('reporte', 'citas/por-especialidad');
        Route::get('citas/por-estado', [ReporteController::class, 'citas'])->defaults('reporte', 'citas/por-estado');
        Route::get('pacientes/registrados', [ReporteController::class, 'pacientes']);
        Route::get('citas/cantidades', [ReporteController::class, 'cantidades']);
        Route::get('agenda-diaria', [ReporteController::class, 'agendaDiaria']);
    });
