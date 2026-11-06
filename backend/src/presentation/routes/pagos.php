<?php

use Clinica\presentation\controllers\pagos\ConsultaPagosController;
use Clinica\presentation\controllers\pagos\MontoCitaController;
use Clinica\presentation\controllers\pagos\PagoController;
use Clinica\presentation\middlewares\ExigirRol;
use Clinica\presentation\middlewares\ExigirSesionNormal;
use Clinica\presentation\middlewares\VerificarCuentaHabilitada;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Paciente', 'throttle:pagos-stripe'])->group(function (): void {
        Route::post('pagos/obligaciones/{obligacion}/intentos', [PagoController::class, 'operar']);
        Route::post('pagos/intentos/{intento}/resultado', [PagoController::class, 'operar']);
        Route::post('pagos/intentos/{intento}/cierre', [PagoController::class, 'operar'])->name('pagos.stripe.cierre');
        Route::post('pagos/intentos/{intento}/paypal/captura', [PagoController::class, 'operar'])->name('pagos.paypal.captura');
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Recepcionista', 'throttle:30,1'])->group(function (): void {
        Route::post('citas/{id}/monto/propuesta', [MontoCitaController::class, 'proponer']);
        Route::post('citas/{id}/monto', [MontoCitaController::class, 'confirmar']);
    });

Route::middleware(['auth:sanctum', VerificarCuentaHabilitada::class, ExigirSesionNormal::class,
    ExigirRol::class.':Administrador,Paciente', 'throttle:60,1'])->group(function (): void {
        Route::get('pagos/obligaciones', [ConsultaPagosController::class, 'obligaciones']);
        Route::get('pagos/obligaciones/{obligacion}', [ConsultaPagosController::class, 'detalle']);
        Route::get('pagos/historial', [ConsultaPagosController::class, 'historial']);
    });
