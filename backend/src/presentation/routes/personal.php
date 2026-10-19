<?php

use Clinica\presentation\controllers\InvitacionPersonalController;
use Clinica\presentation\controllers\PersonalController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\LimitarInvitaciones;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('invitaciones/aceptar', [InvitacionPersonalController::class, 'aceptar'])
        ->middleware(LimitarInvitaciones::class.':aceptar');
});

Route::prefix('personal')->middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador', LimitarInvitaciones::class.':administrar'])->group(function (): void {
        Route::post('/', [PersonalController::class, 'store']);
        Route::post('{id}/invitacion', [PersonalController::class, 'reenviar'])->where('id', '[1-9][0-9]{0,18}');
    });
