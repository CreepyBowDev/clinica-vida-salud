<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarInvitaciones
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $token = $request->input('token');
        $ambito = $accion === 'administrar' ? (string) $request->user()->getAuthIdentifier()
            : (is_string($token) && strlen($token) === 64 ? $token : '<invalido>');
        $this->service->evaluarInvitacion($accion, $ambito, $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
