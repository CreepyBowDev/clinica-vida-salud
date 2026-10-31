<?php

namespace Tests\Fixtures;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Clinica\data\repositories\horarios\PlanificacionRepository;

// Solo tests: adelanta el reloj determinista después de adquirir el lock real.
class RelojTrasPlanificacionRepository extends PlanificacionRepository
{
    public function __construct(private string $ahora) {}

    public function coordinar(): void
    {
        parent::coordinar();
        Carbon::setTestNow($this->ahora);
        CarbonImmutable::setTestNow($this->ahora);
    }
}
