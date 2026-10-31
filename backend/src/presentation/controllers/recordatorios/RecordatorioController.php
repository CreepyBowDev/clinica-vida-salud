<?php

namespace Clinica\presentation\controllers\recordatorios;

use Clinica\business\services\recordatorios\RecordatorioService;
use Clinica\presentation\dtos\recordatorios\ListarRecordatoriosRequest;
use Clinica\presentation\dtos\recordatorios\RecordatorioListadoResource;
use Illuminate\Http\JsonResponse;

class RecordatorioController
{
    public function listar(ListarRecordatoriosRequest $request, RecordatorioService $service): JsonResponse
    {
        $r = $service->listar($request->validated());

        return response()->json(['data' => RecordatorioListadoResource::collection($r['pagina']->getCollection())->resolve($request),
            'meta' => ['current_page' => $r['pagina']->currentPage(), 'per_page' => $r['pagina']->perPage(),
                'total' => $r['pagina']->total(), 'last_page' => max(1, $r['pagina']->lastPage()),
                ...$r['contexto']]])->header('Cache-Control', 'no-store, private');
    }
}
