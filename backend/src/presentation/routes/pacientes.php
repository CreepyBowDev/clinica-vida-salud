<?php

use Clinica\presentation\controllers\CodigoActivacionController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\LimitarCodigoActivacion;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

// Fase 7B: solo emisión de CU-07 necesaria para vincular; el CRUD de fichas es posterior.
Route::post('pacientes/{id}/codigo-activacion', [CodigoActivacionController::class, 'emitir'])
    ->where('id', '[1-9][0-9]{0,18}')
    ->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
        ExigirRol::class.':Recepcionista', LimitarCodigoActivacion::class]);
