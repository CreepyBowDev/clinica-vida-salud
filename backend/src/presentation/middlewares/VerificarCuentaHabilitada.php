<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\AutenticacionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarCuentaHabilitada
{
    public function __construct(private AutenticacionService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $this->service->validarSesion();
        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
