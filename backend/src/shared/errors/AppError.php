<?php

namespace Clinica\shared\errors;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AppError extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly ?int $esperaSegundos = null,
    ) {
        parent::__construct($mensaje);
    }
}
