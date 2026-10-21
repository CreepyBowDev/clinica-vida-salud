<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\usuarios\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarCambioClave
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->service->evaluarCambioClave($request->user()->getAuthIdentifier(), $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
