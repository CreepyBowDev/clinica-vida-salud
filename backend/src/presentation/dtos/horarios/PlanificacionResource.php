<?php

namespace Clinica\presentation\dtos\horarios;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class PlanificacionResource extends JsonResource
{
    private function pagina(Request $request): LengthAwarePaginator
    {
        $pagina = $request->integer('page', 1);
        $tamano = $request->integer('per_page', 20);

        return new LengthAwarePaginator(array_slice($this->resource['elementos'], ($pagina - 1) * $tamano, $tamano),
            count($this->resource['elementos']), $tamano, $pagina, ['path' => $request->url(), 'query' => $request->only(['activo', 'per_page'])]);
    }

    public function toArray(Request $request): array
    {
        // El Service devuelve exclusivamente proyecciones administrativas explícitas.
        if (! array_key_exists('elementos', $this->resource)) {
            return $this->resource;
        }

        return [...$this->resource, 'elementos' => $this->pagina($request)->items()];
    }

    public function with(Request $request): array
    {
        if (! array_key_exists('elementos', $this->resource)) {
            return [];
        }
        $pagina = $this->pagina($request);

        return ['links' => ['first' => $pagina->url(1), 'last' => $pagina->url($pagina->lastPage()),
            'prev' => $pagina->previousPageUrl(), 'next' => $pagina->nextPageUrl()],
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'per_page' => $pagina->perPage(),
                'total' => $pagina->total(), 'from' => $pagina->firstItem(), 'to' => $pagina->lastItem()]];
    }
}
