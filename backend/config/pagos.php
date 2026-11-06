<?php

return [
    // Solo afecta propuestas/reservas nuevas. Nunca reclasifica citas existentes.
    'incorporacion_habilitada' => env('PAGOS_INCORPORACION_HABILITADA', false),
    'paypal' => [
        'habilitado' => env('PAGOS_PAYPAL_HABILITADO', false),
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'app_id' => env('PAYPAL_APP_ID'),
        'merchant_id' => env('PAYPAL_MERCHANT_ID'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'origen_retorno' => env('PAGOS_ORIGEN_RETORNO'),
    ],
    'stripe' => [
        'habilitado' => env('PAGOS_STRIPE_HABILITADO', false),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'cuenta' => env('STRIPE_ACCOUNT_ID'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => '2026-10-28.endive',
        'origen_retorno' => env('PAGOS_ORIGEN_RETORNO'),
    ],
];
