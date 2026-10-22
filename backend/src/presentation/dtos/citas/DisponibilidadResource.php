<?php

namespace Clinica\presentation\dtos\citas;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class DisponibilidadResource extends JsonResource
{
    private function pagina(Request $request): LengthAwarePaginator
    {
        $pagina = $request->integer('page', 1);
        $tamano = $request->integer('per_page', 20);

        return new LengthAwarePaginator(array_slice($this->resource['turnos'], ($pagina - 1) * $tamano, $tamano),
            count($this->resource['turnos']), $tamano, $pagina,
            ['path' => $request->url(), 'query' => $request->only(['id_medico', 'id_especialidad', 'id_paciente', 'fecha', 'per_page'])]);
    }

    public function toArray(Request $request): array
    {
        return [...$this->resource, 'turnos' => $this->pagina($request)->items()];
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
