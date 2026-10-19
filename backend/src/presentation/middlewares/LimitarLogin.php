<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarLogin
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $correo = $request->input('correo_acceso');
        $correo = is_string($correo) && mb_strlen($correo) <= 254 ? mb_strtolower(trim($correo, ' '), 'UTF-8') : '<invalido>';
        $this->service->evaluarLogin($correo, $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
