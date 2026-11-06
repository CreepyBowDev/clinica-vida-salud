<?php

use Clinica\presentation\controllers\pagos\WebhookPayPalController;
use Clinica\presentation\controllers\pagos\WebhookStripeController;
use Illuminate\Support\Facades\Route;

Route::post('/api/webhooks/pagos/stripe', [WebhookStripeController::class, 'recibir']);
Route::post('/api/webhooks/pagos/paypal', [WebhookPayPalController::class, 'recibir']);
