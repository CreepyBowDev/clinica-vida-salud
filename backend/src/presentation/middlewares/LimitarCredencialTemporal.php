<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarCredencialTemporal
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $this->service->evaluarCredencialTemporal($accion, $request->user()->getAuthIdentifier(),
            $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
