<?php

use Clinica\presentation\dtos\RespuestaError;
use Clinica\presentation\middlewares\ExigirOrigenSpa;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trimStrings(except: ['documento_identidad']);
        $middleware->prependToGroup('api', ExigirOrigenSpa::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($request->is('api/*')) {
                return RespuestaError::render($error);
            }
        });
        $exceptions->report(function (Throwable $error) {
            if (request()->is('api/*')) {
                Log::error('Fallo inesperado de API.', [
                    'tipo' => $error::class,
                    'correlacion' => request()->attributes->get('contexto_seguridad')['correlacion'] ?? null,
                ]);

                return false;
            }
        });
    })->create();
