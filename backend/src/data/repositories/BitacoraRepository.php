<?php

namespace Clinica\data\repositories;

use Carbon\CarbonImmutable;
use Clinica\data\models\Bitacora;
use Clinica\data\models\Usuario;

class BitacoraRepository
{
    public function autenticacion(string $tipo, string $resultado, array $contexto, ?Usuario $actor = null, ?string $causa = null, ?Usuario $objetivo = null, ?array $cambios = null): void
    {
        $evento = new Bitacora;
        $evento->forceFill([
            'tipo_evento' => $tipo,
            'tipo_actor' => $actor ? 'USUARIO' : 'ANONIMO',
            'id_usuario_responsable' => $actor?->getKey(),
            'id_usuario_objetivo' => $objetivo?->getKey(),
            'rol_objetivo' => $objetivo?->rol->nombre,
            'rol_responsable' => $actor?->rol->nombre,
            'modulo' => 'AUTENTICACION',
            'operacion' => $tipo,
            'resultado' => $resultado,
            'causa_codigo' => $causa,
            'ocurrido_en' => CarbonImmutable::now('UTC'),
            'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'],
            'correlacion' => $contexto['correlacion'],
            'cambios_seguros' => $cambios,
        ])->saveOrFail();
    }
}
