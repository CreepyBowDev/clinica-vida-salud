<?php

use Clinica\presentation\controllers\citas\DisponibilidadController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('reservas')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class,
    ExigirSesionNormal::class, ExigirRol::class.':Recepcionista,Paciente'])->group(function (): void {
        Route::get('especialidades', [DisponibilidadController::class, 'especialidades']);
        Route::get('medicos', [DisponibilidadController::class, 'medicos']);
        Route::get('disponibilidad', [DisponibilidadController::class, 'turnos']);
    });
