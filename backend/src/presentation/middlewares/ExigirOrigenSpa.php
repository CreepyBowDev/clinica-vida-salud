<?php

namespace Clinica\presentation\middlewares;

use Clinica\shared\errors\AppError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

class ExigirOrigenSpa
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('contexto_seguridad', [
            'ip' => $request->ip(),
            'procedencia' => $request->isFromTrustedProxy() ? 'PROXY_CONFIABLE' : 'DIRECTA',
            'correlacion' => (string) Str::uuid(),
        ]);
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $origen = $this->origen($origin ?? $referer);
        $permitidos = array_merge(config('cors.allowed_origins', []), [$this->origen(config('app.url'))]);
        if ($origen === null || ! in_array($origen, $permitidos, true)
            || ! EnsureFrontendRequestsAreStateful::fromFrontend($request)
            || ($origin !== null && $origin !== $origen)
            || ($origin !== null && $referer !== null && $this->origen($referer) !== $origen)) {
            throw new AppError('ORIGEN_NO_PERMITIDO', 'El origen de la solicitud no está permitido.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function origen(?string $url): ?string
    {
        $partes = $url !== null ? parse_url($url) : false;
        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])
            || ! in_array($partes['scheme'], ['http', 'https'], true)
            || isset($partes['user']) || isset($partes['pass'])) {
            return null;
        }

        return $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
    }
}
