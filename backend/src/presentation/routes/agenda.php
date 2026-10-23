<?php

use Clinica\presentation\controllers\agenda\AgendaController;
use Clinica\presentation\controllers\agenda\ConsultaCitaController;
use Clinica\presentation\controllers\agenda\HistorialCitasController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('agenda')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class,
    ExigirSesionNormal::class])->group(function (): void {
        Route::get('medicos', [AgendaController::class, 'medicos'])->middleware(ExigirRol::class.':Administrador,Recepcionista');
        Route::get('/', [AgendaController::class, 'index'])->middleware(ExigirRol::class.':Administrador,Recepcionista,Médico');
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class])->group(function (): void {
    Route::get('mis-citas', [HistorialCitasController::class, 'propias'])->middleware(ExigirRol::class.':Paciente');
    Route::get('pacientes/{id}/citas', [HistorialCitasController::class, 'paciente'])->middleware(ExigirRol::class.':Recepcionista');
    Route::get('citas/{id}', [ConsultaCitaController::class, 'show'])->middleware(ExigirRol::class.':Administrador,Recepcionista,Médico,Paciente');
});
