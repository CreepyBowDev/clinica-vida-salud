<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\ControlIntentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarRecuperacion
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $valor = $request->input($accion === 'solicitar' ? 'correo_acceso' : 'token');
        $ambito = is_string($valor) && strlen($valor) <= 1024 ? $valor : '<invalido>';
        if ($accion === 'solicitar') {
            $ambito = mb_strtolower(trim($ambito, ' '), 'UTF-8');
        }
        $this->service->evaluarRecuperacion($accion, $ambito, $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
