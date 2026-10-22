<?php

namespace Clinica\presentation\dtos\consultorios;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class ImpactoAsignacionConsultorioResource extends JsonResource
{
    private function pagina(Request $request): LengthAwarePaginator
    {
        $pagina = $request->integer('page', 1);
        $tamano = $request->integer('per_page', 20);

        return new LengthAwarePaginator(array_slice($this->resource['elementos'], ($pagina - 1) * $tamano, $tamano),
            count($this->resource['elementos']), $tamano, $pagina, ['path' => $request->url()]);
    }

    public function toArray(Request $request): array
    {
        return ['puede_confirmar' => $this->resource['puede_confirmar'], 'huella_impacto' => $this->resource['huella_impacto'],
            'totales' => $this->resource['totales'], 'elementos' => $this->pagina($request)->items()];
    }

    public function with(Request $request): array
    {
        $pagina = $this->pagina($request);

        return ['links' => ['first' => $pagina->url(1), 'last' => $pagina->url($pagina->lastPage()),
            'prev' => $pagina->previousPageUrl(), 'next' => $pagina->nextPageUrl()],
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(), 'total' => $pagina->total(), 'from' => $pagina->firstItem(), 'to' => $pagina->lastItem()]];
    }
}
