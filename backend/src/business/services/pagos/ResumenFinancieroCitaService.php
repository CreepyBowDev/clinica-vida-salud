<?php

namespace Clinica\business\services\pagos;

use Clinica\shared\utils\ImportePago;

class ResumenFinancieroCitaService
{
    /** La fila procede de una consulta ya autorizada; el rol nunca se toma de parámetros HTTP. */
    public function proyectar(array $fila, string $rol): array
    {
        if (! in_array($rol, ['Administrador', 'Recepcionista', 'Paciente'], true)) {
            return [];
        }
        $incorporada = (bool) ($fila['incorporada_finanzas'] ?? false);
        $id = $fila['id_obligacion'] ?? null;
        $precio = $id === null ? null : ImportePago::desdeHistorico((int) $fila['centavos_bob'],
            (int) $fila['centavos_usd'], (int) $fila['tasa_numerador'], (int) $fila['tasa_denominador'])->precio();

        return ['incorporada_finanzas' => $incorporada,
            'situacion_precio' => ! $incorporada ? 'NoIncorporada' : ($id === null ? 'PendienteAsignacion' : 'Asignado'),
            'puede_asignar_monto' => config('pagos.incorporacion_habilitada') === true && $incorporada && $id === null
                && $fila['estado'] !== 'Cancelada' && in_array($rol, ['Administrador', 'Recepcionista'], true),
            'obligacion_id' => $id, 'precio' => $precio];
    }
}
