<?php

namespace Clinica\presentation\controllers\recordatorios;

use Clinica\business\services\recordatorios\SuscripcionPushService;
use Clinica\presentation\dtos\recordatorios\SuscripcionPushRequest;
use Clinica\shared\utils\InstalacionPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SuscripcionPushController
{
    public function configuracion(SuscripcionPushRequest $request, SuscripcionPushService $service): JsonResponse
    {
        return response()->json(['data' => $service->configuracion()])->header('Cache-Control', 'no-store, private');
    }

    public function listar(SuscripcionPushRequest $request, SuscripcionPushService $service, InstalacionPush $instalacion): JsonResponse
    {
        return response()->json($service->listar($request->validated(), $instalacion->resumen($instalacion->actual())))->header('Cache-Control', 'no-store, private');
    }

    public function autorizar(SuscripcionPushRequest $request, SuscripcionPushService $service, InstalacionPush $instalacion): JsonResponse
    {
        $token = $instalacion->paraAlta();
        $r = $service->autorizar($request->validated('suscripcion'), $instalacion->resumen($token), $instalacion->vence($token), $request->attributes->get('contexto_seguridad'));
        $instalacion->confirmar($token);

        return response()->json(['data' => $r['data']], $r['creada'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function revocar(SuscripcionPushRequest $request, SuscripcionPushService $service, InstalacionPush $instalacion): Response
    {
        $service->revocar($instalacion->resumen($instalacion->actual()), $request->validated('id_objetivo'), $request->attributes->get('contexto_seguridad'));

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
