<?php

use Clinica\presentation\controllers\historia_clinica\ConsultaMedicaController;
use Clinica\presentation\controllers\historia_clinica\HistoriaClinicaController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Médico,Paciente'])->group(function (): void {
        Route::get('pacientes/{id}/historia-clinica', [HistoriaClinicaController::class, 'historia']);
        Route::get('citas/{id}/consulta', [HistoriaClinicaController::class, 'consulta']);
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Médico'])->group(function (): void {
        Route::post('citas/{id}/consulta', [ConsultaMedicaController::class, 'registrar']);
        Route::patch('citas/{id}/consulta', [ConsultaMedicaController::class, 'actualizar']);
        Route::get('citas/{id}/consulta/versiones', [HistoriaClinicaController::class, 'versionesConsulta']);
        Route::get('pacientes/{id}/historia-clinica/antecedentes/versiones', [HistoriaClinicaController::class, 'versionesAntecedentes']);
    });
