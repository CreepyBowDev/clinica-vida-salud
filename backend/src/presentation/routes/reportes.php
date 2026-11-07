<?php

use Clinica\presentation\controllers\reportes\ReporteController;
use Clinica\presentation\controllers\reportes\ReportePdfController;
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
        Route::middleware('throttle:6,1')->group(function (): void {
            Route::get('citas/programadas/pdf', [ReportePdfController::class, 'citas'])->defaults('reporte', 'citas/programadas')->defaults('exportar_pdf', true);
            Route::get('citas/por-medico/pdf', [ReportePdfController::class, 'citas'])->defaults('reporte', 'citas/por-medico')->defaults('exportar_pdf', true);
            Route::get('citas/por-especialidad/pdf', [ReportePdfController::class, 'citas'])->defaults('reporte', 'citas/por-especialidad')->defaults('exportar_pdf', true);
            Route::get('citas/por-estado/pdf', [ReportePdfController::class, 'citas'])->defaults('reporte', 'citas/por-estado')->defaults('exportar_pdf', true);
            Route::get('pacientes/registrados/pdf', [ReportePdfController::class, 'pacientes'])->defaults('exportar_pdf', true);
            Route::get('citas/cantidades/pdf', [ReportePdfController::class, 'cantidades'])->defaults('exportar_pdf', true);
            Route::get('agenda-diaria/pdf', [ReportePdfController::class, 'agendaDiaria'])->defaults('exportar_pdf', true);
        });
    });
