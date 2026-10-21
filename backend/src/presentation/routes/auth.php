<?php

use Clinica\presentation\controllers\usuarios\AutenticacionController;
use Clinica\presentation\controllers\usuarios\ClaveController;
use Clinica\presentation\controllers\usuarios\CredencialTemporalController;
use Clinica\presentation\controllers\usuarios\RecuperacionClaveController;
use Clinica\presentation\controllers\usuarios\RegistroPacienteController;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\ExigirSesionRestringida;
use Clinica\presentation\middlewares\LimitarCambioClave;
use Clinica\presentation\middlewares\LimitarCredencialTemporal;
use Clinica\presentation\middlewares\LimitarLogin;
use Clinica\presentation\middlewares\LimitarRecuperacion;
use Clinica\presentation\middlewares\LimitarRegistroPaciente;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('registro', [RegistroPacienteController::class, 'iniciar'])->middleware(LimitarRegistroPaciente::class.':iniciar');
    Route::post('registro/vincular', [RegistroPacienteController::class, 'vincular'])->middleware(LimitarRegistroPaciente::class.':vincular');
    Route::post('registro/reenviar', [RegistroPacienteController::class, 'reenviar'])->middleware(LimitarRegistroPaciente::class.':reenviar');
    Route::post('registro/verificar', [RegistroPacienteController::class, 'verificar'])->middleware(LimitarRegistroPaciente::class.':verificar');
    Route::post('login', [AutenticacionController::class, 'login'])->middleware(LimitarLogin::class);
    Route::get('me', [AutenticacionController::class, 'me'])
        ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class]);
    Route::post('logout', [AutenticacionController::class, 'logout']);
    Route::put('password', [ClaveController::class, 'update'])
        ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class, LimitarCambioClave::class]);
    Route::post('password/forgot', [RecuperacionClaveController::class, 'solicitar'])->middleware(LimitarRecuperacion::class.':solicitar');
    Route::post('password/reset', [RecuperacionClaveController::class, 'restablecer'])->middleware(LimitarRecuperacion::class.':restablecer');
    Route::put('password/temporal', [CredencialTemporalController::class, 'completar'])
        ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionRestringida::class, LimitarCredencialTemporal::class.':completar']);
});
