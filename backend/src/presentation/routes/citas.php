<?php

use Clinica\presentation\controllers\citas\AsistenciaController;
use Clinica\presentation\controllers\citas\DisponibilidadController;
use Clinica\presentation\controllers\citas\GestionCitaController;
use Clinica\presentation\controllers\citas\ReservaController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Recepcionista'])->group(function (): void {
        Route::patch('citas/{id}/llegada', [AsistenciaController::class, 'confirmarLlegada']);
        Route::patch('citas/{id}/inasistencia', [AsistenciaController::class, 'registrarInasistencia']);
        Route::get('citas/{id}/gestion', [GestionCitaController::class, 'consultar']);
        Route::patch('citas/{id}/reprogramacion', [GestionCitaController::class, 'reprogramar']);
        Route::patch('citas/{id}/cancelacion', [GestionCitaController::class, 'cancelar']);
    });

Route::get('citas/{id}/asistencia', [AsistenciaController::class, 'consultar'])
    ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
        ExigirRol::class.':Administrador,Recepcionista,Médico']);

Route::patch('citas/{id}/atencion', [AsistenciaController::class, 'marcarAtendida'])
    ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
        ExigirRol::class.':Administrador,Médico']);

Route::get('citas/{id}/disponibilidad-reprogramacion', [DisponibilidadController::class, 'reprogramacion'])
    ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
        ExigirRol::class.':Administrador,Recepcionista']);

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class,
    ExigirSesionNormal::class, ExigirRol::class.':Administrador,Recepcionista,Paciente'])->group(function (): void {
        Route::post('reservas/propuesta', [ReservaController::class, 'propuesta']);
        Route::post('reservas/resultado', [ReservaController::class, 'resultado']);
        Route::post('citas', [ReservaController::class, 'store']);
    });
