<?php

use Clinica\presentation\controllers\laboratorio\DocumentoLaboratorioController;
use Clinica\presentation\controllers\laboratorio\ResultadoLaboratorioController;
use Clinica\presentation\controllers\laboratorio\RevisionResultadoController;
use Clinica\presentation\controllers\laboratorio\SolicitudLaboratorioController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Médico,Paciente'])->group(function (): void {
        Route::get('consultas/{id}/solicitudes-laboratorio', [ResultadoLaboratorioController::class, 'porConsulta'])->name('laboratorio.consulta');
        Route::get('solicitudes-laboratorio', [ResultadoLaboratorioController::class, 'listar'])->name('laboratorio.lista');
        Route::get('solicitudes-laboratorio/{id}', [ResultadoLaboratorioController::class, 'consultar'])->name('laboratorio.detalle');
        Route::get('solicitudes-laboratorio/{id}/archivo', [ResultadoLaboratorioController::class, 'archivo'])->name('laboratorio.archivo');
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Médico'])->group(function (): void {
        Route::post('consultas/{id}/solicitudes-laboratorio', [SolicitudLaboratorioController::class, 'crear']);
        Route::get('solicitudes-laboratorio/{id}/versiones', [ResultadoLaboratorioController::class, 'versiones'])->name('laboratorio.versiones');
        Route::post('solicitudes-laboratorio/{id}/revisiones', [RevisionResultadoController::class, 'crear'])->name('laboratorio.revisar');
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador'])->group(function (): void {
        Route::post('solicitudes-laboratorio/{id}/documentos', [DocumentoLaboratorioController::class, 'cargar'])->name('laboratorio.cargar');
        Route::post('solicitudes-laboratorio/{id}/correcciones', [DocumentoLaboratorioController::class, 'corregir'])->name('laboratorio.corregir');
    });
