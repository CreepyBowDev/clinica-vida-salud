<?php

namespace Clinica\presentation\controllers\bitacora;

use Clinica\business\services\bitacora\BitacoraService;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Clinica\presentation\dtos\bitacora\BitacoraListadoResource;
use Clinica\presentation\dtos\bitacora\ListarBitacoraRequest;
use Clinica\presentation\dtos\usuarios\CuentaRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BitacoraController
{
    public function show(CuentaRequest $request, BitacoraService $service): BitacoraDetalleResource
    {
        return new BitacoraDetalleResource($service->consultar($request->validated()['id_objetivo']));
    }

    public function index(ListarBitacoraRequest $request, BitacoraService $service): AnonymousResourceCollection
    {
        $filtros = $request->validated();

        return BitacoraListadoResource::collection($service->listar($filtros)->appends($filtros));
    }
}
