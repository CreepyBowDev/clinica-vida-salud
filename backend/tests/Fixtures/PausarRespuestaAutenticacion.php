<?php

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PausarRespuestaAutenticacion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('api/auth/login', 'api/auth/password')) {
            echo "GUARDADA\n";
            fflush(STDOUT);
            if (trim(fgets(STDIN)) !== 'CONTINUAR') {
                throw new RuntimeException('Falta barrera de sincronización de la prueba.');
            }
        }

        return $response;
    }
}
