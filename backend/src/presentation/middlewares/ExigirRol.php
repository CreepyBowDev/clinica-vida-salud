<?php

namespace Clinica\presentation\middlewares;

use Clinica\shared\errors\AppError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigirRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            throw new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.');
        }
        $fijos = ['Administrador', 'Recepcionista', 'Médico', 'Paciente'];
        if (array_diff($roles, $fijos) !== [] || ! in_array($request->user()->rol->nombre, $roles, true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $next($request);
    }
}
