<?php

namespace Clinica\business\validators;

use Carbon\CarbonImmutable;
use Clinica\data\models\TokenAcceso;
use Clinica\data\models\Usuario;

class CredencialTemporalValidator
{
    public function personalHabilitado(Usuario $usuario): bool
    {
        return $usuario->estado_cuenta === 'Activa' && $usuario->correo_verificado_en !== null
            && $usuario->password_hash !== null && $usuario->paciente === null
            && match ($usuario->rol?->nombre) {
                'Administrador', 'Recepcionista' => filled($usuario->nombres_personal) && filled($usuario->apellidos_personal),
                'Médico' => $usuario->medico !== null,
                default => false,
            };
    }

    public function vigente(Usuario $usuario, ?TokenAcceso $token): bool
    {
        $ahora = CarbonImmutable::now('UTC');

        return $usuario->cambio_clave_obligatorio && $this->personalHabilitado($usuario) && $token !== null
            && (string) $token->id_usuario === (string) $usuario->getKey() && $token->proposito === 'CredencialTemporal'
            && $token->consumido_en === null && $token->invalidado_en === null
            && $ahora->greaterThanOrEqualTo($token->emitido_en) && $ahora->lessThan($token->expira_en);
    }
}
