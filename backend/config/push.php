<?php

return [
    // El canal permanece deshabilitado hasta configurar y verificar el transporte en F5.
    'habilitado' => env('PUSH_ENABLED', false),
    'vapid_publica' => env('PUSH_VAPID_PUBLIC_KEY'),
    'vapid_privada' => env('PUSH_VAPID_PRIVATE_KEY'),
    'vapid_sujeto' => env('PUSH_VAPID_SUBJECT'),
    'cookie' => 'vida_salud_instalacion_push',
    'instalacion_dias' => 180,
    // Hosts exactos: no URLs arbitrarias, subdominios comodín ni resolución DNS durante el alta.
    'hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'],
];
