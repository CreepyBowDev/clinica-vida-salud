<?php

namespace Clinica\business\validators;

use Clinica\data\models\Usuario;

class AutorizacionPushValidator
{
    public function huella(Usuario $usuario): string
    {
        return hash_hmac('sha256', json_encode([(string) $usuario->getKey(), (string) $usuario->id_rol,
            (string) $usuario->paciente?->getKey(), $usuario->password_hash, $usuario->correo_verificado_en?->format('c')]), (string) config('app.key'));
    }

    public function cuentaHabilitada(?Usuario $usuario, string $paciente): bool
    {
        return $usuario !== null && $usuario->estado_cuenta === 'Activa'
            && $usuario->rol?->nombre === 'Paciente' && ! $usuario->cambio_clave_obligatorio
            && $usuario->password_hash !== null && $usuario->correo_verificado_en !== null
            && (string) $usuario->paciente?->getKey() === $paciente;
    }
}
