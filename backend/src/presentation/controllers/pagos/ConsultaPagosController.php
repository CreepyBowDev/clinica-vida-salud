<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\ConsultaPagosService;
use Clinica\presentation\dtos\pagos\ConsultaPagosRequest;
use Clinica\presentation\dtos\pagos\DevolucionPagoResource;
use Clinica\presentation\dtos\pagos\IntentoPagoResource;
use Clinica\presentation\dtos\pagos\ObligacionPagoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsultaPagosController
{
    public function obligaciones(ConsultaPagosRequest $request, ConsultaPagosService $service): AnonymousResourceCollection
    {
        $resultado = $service->consultar('obligaciones', $request->validated());
        $resultado['pagina']->withPath($request->url())->appends($request->validated());

        return ObligacionPagoResource::collection($resultado['pagina'])->additional(['meta' => $resultado['meta']]);
    }

    public function historial(ConsultaPagosRequest $request, ConsultaPagosService $service): AnonymousResourceCollection
    {
        $resultado = $service->consultar('historial', $request->validated());
        $resultado['pagina']->withPath($request->url())->appends($request->validated());

        return IntentoPagoResource::collection($resultado['pagina'])->additional(['meta' => $resultado['meta']]);
    }

    public function detalle(ConsultaPagosRequest $request, ConsultaPagosService $service): JsonResponse
    {
        $resultado = $service->consultar('detalle', $request->validated());
        $datos = (new ObligacionPagoResource($resultado['obligacion']))->toArray($request);
        foreach (['intentos' => IntentoPagoResource::class, 'devoluciones' => DevolucionPagoResource::class] as $clave => $resource) {
            $resultado[$clave]->setPageName($clave.'_page')->withPath($request->url())->appends($request->query());
            $datos[$clave] = $resource::collection($resultado[$clave])->additional(['meta' => $resultado['meta']])->response()->getData(true);
        }

        return response()->json(['data' => $datos, 'meta' => $resultado['meta']]);
    }
}
