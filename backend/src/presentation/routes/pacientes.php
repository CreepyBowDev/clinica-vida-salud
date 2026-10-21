<?php

use Clinica\presentation\controllers\pacientes\CodigoActivacionController;
use Clinica\presentation\controllers\pacientes\MiPerfilController;
use Clinica\presentation\controllers\pacientes\PacienteController;
use Clinica\presentation\controllers\usuarios\CambioCorreoController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\LimitarCambioCorreo;
use Clinica\presentation\middlewares\LimitarCodigoActivacion;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('mi-perfil')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Paciente'])->group(function (): void {
        Route::get('/', [MiPerfilController::class, 'show']);
        Route::patch('/', [MiPerfilController::class, 'update']);
        Route::post('correo', [CambioCorreoController::class, 'solicitar'])->middleware(LimitarCambioCorreo::class.':solicitar');
        Route::post('correo/reenviar', [CambioCorreoController::class, 'reenviar'])->middleware(LimitarCambioCorreo::class.':reenviar');
        Route::post('correo/verificar', [CambioCorreoController::class, 'verificar'])->middleware(LimitarCambioCorreo::class.':verificar');
    });

Route::prefix('pacientes')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Recepcionista'])->group(function (): void {
        Route::get('/', [PacienteController::class, 'index']);
        Route::post('/', [PacienteController::class, 'store']);
        Route::get('{id}', [PacienteController::class, 'show'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}', [PacienteController::class, 'update'])->where('id', '[1-9][0-9]{0,18}');
        Route::patch('{id}/estado', [PacienteController::class, 'estado'])->where('id', '[1-9][0-9]{0,18}');
    });

// Emisión de CU-07 necesaria para vincular: contrato conservado.
Route::post('pacientes/{id}/codigo-activacion', [CodigoActivacionController::class, 'emitir'])
    ->where('id', '[1-9][0-9]{0,18}')
    ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
        ExigirRol::class.':Recepcionista', LimitarCodigoActivacion::class]);
