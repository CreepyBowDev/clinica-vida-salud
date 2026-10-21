<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\usuarios\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarCodigoActivacion
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->service->evaluarRegistroPaciente('emitir-recepcion', (string) $request->user()->getKey(),
            $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
