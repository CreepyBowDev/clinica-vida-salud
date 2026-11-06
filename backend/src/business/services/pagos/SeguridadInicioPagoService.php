<?php

namespace Clinica\business\services\pagos;

use Clinica\data\repositories\pagos\StripePagoRepository;

/** Prueba local durable; no sustituye las consultas autoritativas de los aplicadores. */
class SeguridadInicioPagoService
{
    public function __construct(private StripePagoRepository $repo) {}

    public function disponible(string $obligacion): bool
    {
        if ($this->repo->cobro($obligacion) || $this->repo->activo($obligacion) || $this->repo->escritura($obligacion)) {
            return false;
        }
        foreach ($this->repo->historialIntentos($obligacion) as $i) {
            $crear = $i->operaciones->firstWhere('tipo', 'Crear');
            if ($i->estado !== 'Cerrado' || $i->captura_permitida
                || $crear?->resultado_codigo !== 'CIERRE_SIN_COBRO_VERIFICADO') {
                return false;
            }
            foreach ($i->operaciones as $op) {
                if ($op->tipo !== 'Consultar' && ! in_array($op->estado, ['Exitosa', 'Fallida'], true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
