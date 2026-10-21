<?php

return [
    'administrador' => [
        'correo' => env('CLINICA_ADMIN_CORREO'),
        'nombres' => env('CLINICA_ADMIN_NOMBRES'),
        'apellidos' => env('CLINICA_ADMIN_APELLIDOS'),
        'password' => env('CLINICA_ADMIN_PASSWORD'),
    ],
    'demo_password' => env('CLINICA_DEMO_PASSWORD'),
    'cuentas_prueba_habilitadas' => env('CLINICA_CUENTAS_PRUEBA_HABILITADAS', false),
];
