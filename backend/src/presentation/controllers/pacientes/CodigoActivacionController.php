<?php

namespace Clinica\presentation\controllers\pacientes;

use Clinica\business\services\pacientes\CodigoActivacionService;
use Clinica\presentation\dtos\pacientes\EmitirCodigoActivacionRequest;
use Illuminate\Http\JsonResponse;

class CodigoActivacionController
{
    public function emitir(EmitirCodigoActivacionRequest $request, CodigoActivacionService $service): JsonResponse
    {
        $datos = $request->validated();

        return response()->json(['data' => $service->emitir($datos['id_objetivo'], (bool) $datos['identidad_verificada'],
            $request->attributes->get('contexto_seguridad'))], 201);
    }
}
