<?php

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PausarCambioClave
{
    public function handle(Request $request, Closure $next): Response
    {
        echo "VALIDADA\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'CONTINUAR') {
            throw new RuntimeException('Falta barrera de cambio de contraseña.');
        }

        return $next($request);
    }
}
