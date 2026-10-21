<?php

namespace Clinica\presentation\dtos\bitacora;

use Clinica\shared\utils\ReferenciasBitacora;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BitacoraListadoResource extends JsonResource
{
    private const CAUSAS = ['CLAVE_ACTUAL_INVALIDA', 'ENVIO_SIMULADO', 'ENVIO_NO_VIGENTE', 'ENVIO_NO_CONFIRMADO',
        'CODIGO_INVALIDO', 'REGISTRO_NO_APLICABLE', 'ACTIVACION_CODIGO_INVALIDO', 'REGISTRO_ENVIO_LIMITADO',
        'REGISTRO_CODIGO_INVALIDO', 'INVITACION_INVALIDA', 'RECUPERACION_NO_APLICABLE', 'RECUPERACION_INVALIDA',
        'AUTH_NO_AUTENTICADO', 'ACCESO_DENEGADO', 'PERSONAL_ROL_INVALIDO', 'PERSONAL_VINCULO_INVALIDO',
        'PERSONAL_INVITACION_NO_PERMITIDA', 'CUENTA_TRANSICION_INVALIDA', 'CUENTA_ROL_NO_PERMITIDO'];

    public function toArray(Request $request): array
    {
        $entidad = $this->entidad_referida;
        $registro = is_string($entidad) ? ReferenciasBitacora::registro($entidad, $this->registro_referido) : null;
        $entidadPermitida = in_array($entidad, ReferenciasBitacora::ENTIDADES, true)
            && ($this->registro_referido === null || $registro !== null);

        return [
            'id_evento' => (string) $this->getKey(),
            'tipo_evento' => $this->tipo_evento,
            'tipo_actor' => $this->tipo_actor,
            'id_usuario_responsable' => $this->id_usuario_responsable === null ? null : (string) $this->id_usuario_responsable,
            'rol_responsable' => $this->rol_responsable,
            'id_usuario_objetivo' => $this->id_usuario_objetivo === null ? null : (string) $this->id_usuario_objetivo,
            'rol_objetivo' => $this->rol_objetivo,
            'modulo' => $this->modulo,
            'operacion' => $this->operacion,
            'resultado' => $this->resultado,
            'causa_codigo' => in_array($this->causa_codigo, self::CAUSAS, true) ? $this->causa_codigo : null,
            'ocurrido_en' => $this->ocurrido_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'registrado_en' => $this->registrado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'direccion_ip' => $this->direccion_ip,
            'entidad_referida' => $entidadPermitida ? $entidad : null,
            'registro_referido' => $entidadPermitida ? $registro : null,
            'referencias' => $this->version_formato === 1
                ? ReferenciasBitacora::adicionales($this->tipo_evento, $this->referencias) : (object) [],
        ];
    }
}
