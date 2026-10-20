<?php

return [
    'registro_paciente' => [
        'solicitudes_por_ip' => 20,
        'solicitudes_por_ambito' => 5,
    ],
    'credencial_temporal' => [
        'vigencia_minutos' => 30,
        'espera_reemision_segundos' => 60,
        'emisiones_por_hora' => 5,
        'solicitudes_por_ip' => 20,
        'solicitudes_por_usuario' => 5,
        'ventana_segundos' => 60,
    ],
    'recuperacion' => [
        'solicitudes_por_ip' => 20,
        'solicitudes_por_correo' => 5,
        'solicitudes_por_token' => 5,
        'ventana_segundos' => 60,
        'solicitudes_por_correo_hora' => 5,
    ],
    'correo' => [
        'mailer' => env('SEGURIDAD_MAILER', 'array'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],
    'invitaciones' => [
        'espera_reenvio_segundos' => 60,
        'emisiones_por_hora' => 5,
        'solicitudes_por_ip' => 20,
        'solicitudes_por_ambito' => 5,
        'ventana_segundos' => 60,
    ],
    'cambio_clave' => [
        'solicitudes_por_ip' => 20,
        'solicitudes_por_usuario' => 5,
        'ventana_segundos' => 60,
    ],
    'login' => [
        'solicitudes_por_ip' => 20,
        'solicitudes_por_correo_ip' => 5,
        'ventana_segundos' => 60,
    ],
];
