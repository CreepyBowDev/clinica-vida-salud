<?php

return [
    // Siempre privado; los tests sustituyen únicamente esta raíz por una propia.
    'raiz' => storage_path('app/private/laboratorio'),
    'qpdf' => env('QPDF_BINARY', 'qpdf'),
    'max_bytes' => 10485760,
    'max_dimension_imagen' => 6000,
    'max_pixeles_imagen' => 10000000,
];
