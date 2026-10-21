<?php

namespace Clinica\presentation\middlewares;

use Clinica\business\services\usuarios\ControlIntentosService;
use Clinica\shared\utils\RegistroPacienteContexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitarRegistroPaciente
{
    public function __construct(private ControlIntentosService $service) {}

    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $valor = match ($accion) {
            'iniciar' => $request->input('correo_acceso'),
            'vincular' => $request->input('documento_identidad'),
            default => $request->cookie(RegistroPacienteContexto::COOKIE),
        };
        $ambito = is_string($valor) ? ($accion === 'iniciar' ? mb_strtolower(trim($valor, ' '), 'UTF-8')
            : ($accion === 'vincular' ? trim($valor, ' ') : $valor)) : '';
        $this->service->evaluarRegistroPaciente($accion, $ambito, $request->attributes->get('contexto_seguridad'));

        return $next($request);
    }
}
