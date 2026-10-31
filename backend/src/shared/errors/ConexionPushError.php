<?php

namespace Clinica\shared\errors;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

class ConexionPushError extends RuntimeException implements ClientExceptionInterface
{
    public function __construct(public readonly bool $incierto)
    {
        // Nunca encadenar excepciones HTTP: contienen endpoint y autorización.
        parent::__construct('No se confirmó la aceptación del servicio push.');
    }
}
