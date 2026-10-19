<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\AutenticacionService;
use Clinica\presentation\dtos\LoginRequest;
use Clinica\presentation\dtos\UsuarioAutenticadoResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutenticacionController
{
    public function login(LoginRequest $request, AutenticacionService $service): UsuarioAutenticadoResource
    {
        $datos = $request->validated();

        return new UsuarioAutenticadoResource($service->iniciar($datos['correo_acceso'], $datos['password'], $request->attributes->get('contexto_seguridad')));
    }

    public function me(Request $request): UsuarioAutenticadoResource
    {
        return new UsuarioAutenticadoResource($request->user());
    }

    public function logout(Request $request, AutenticacionService $service): Response
    {
        $service->cerrar($request->attributes->get('contexto_seguridad'));

        return response()->noContent();
    }
}
