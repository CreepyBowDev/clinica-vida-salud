<?php

namespace Clinica\presentation\controllers;

use Clinica\business\services\RegistroPacienteService;
use Clinica\presentation\dtos\RegistrarPacienteRequest;
use Clinica\presentation\dtos\VerificarRegistroPacienteRequest;
use Clinica\presentation\dtos\VincularPacienteRequest;
use Clinica\shared\utils\RegistroPacienteContexto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistroPacienteController
{
    private function contexto(Request $request): string
    {
        $valor = $request->cookie(RegistroPacienteContexto::COOKIE);

        return RegistroPacienteContexto::valido($valor) ? $valor : bin2hex(random_bytes(32));
    }

    private function recibida(): JsonResponse
    {
        $response = response()->json(['data' => ['message' => 'Solicitud recibida. Si corresponde, recibirás un código. Esta respuesta no confirma el envío; si no lo recibes, solicita reenvío o acude a Recepción para asistencia, acceso o vinculación.']], 202);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }

    public function iniciar(RegistrarPacienteRequest $request, RegistroPacienteService $service): JsonResponse
    {
        $contexto = $this->contexto($request);
        $service->iniciar($request->validated(), $contexto, $request->attributes->get('contexto_seguridad'));

        // EncryptCookies de Sanctum protege el secreto; HttpOnly, host-only y vida independiente de la sesión de login.
        return $this->recibida()->cookie(RegistroPacienteContexto::COOKIE, $contexto, 1440, '/', null,
            (bool) config('session.secure'), true, false, config('session.same_site', 'lax'));
    }

    public function reenviar(Request $request, RegistroPacienteService $service): JsonResponse
    {
        $service->reenviar($this->contexto($request), $request->attributes->get('contexto_seguridad'));

        return $this->recibida();
    }

    public function vincular(VincularPacienteRequest $request, RegistroPacienteService $service): JsonResponse
    {
        $contexto = $this->contexto($request);
        $service->vincular($request->validated(), $contexto, $request->attributes->get('contexto_seguridad'));

        return $this->recibida()->cookie(RegistroPacienteContexto::COOKIE, $contexto, 1440, '/', null,
            (bool) config('session.secure'), true, false, config('session.same_site', 'lax'));
    }

    public function verificar(VerificarRegistroPacienteRequest $request, RegistroPacienteService $service): Response
    {
        $service->verificar($this->contexto($request), $request->validated()['codigo'], $request->attributes->get('contexto_seguridad'));

        return response()->noContent()->withoutCookie(RegistroPacienteContexto::COOKIE, '/', null);
    }
}
