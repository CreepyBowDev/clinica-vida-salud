<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\usuarios\AutenticacionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigirSesionRestringida
{
    public function __construct(private AutenticacionService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->service->exigirSesionRestringida();

        return $next($request);
    }
}
