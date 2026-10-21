<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\usuarios\AutenticacionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigirSesionNormal
{
    public function __construct(private AutenticacionService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->service->exigirSesionNormal();

        return $next($request);
    }
}
