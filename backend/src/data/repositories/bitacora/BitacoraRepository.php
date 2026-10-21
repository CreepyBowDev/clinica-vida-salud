<?php

namespace Clinica\data\repositories\bitacora;

use Carbon\CarbonImmutable;
use Clinica\data\models\Bitacora;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;

class BitacoraRepository
{
    public function medicoModificado(Medico $medico, Usuario $actor, array $contexto, string $tipo, array $campos, array $habilitaciones = []): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => $tipo, 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'MEDICOS', 'operacion' => $tipo, 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_medico' => (string) $medico->getKey()],
            'cambios_seguros' => ['campos' => $campos, ...($habilitaciones === [] ? [] : ['habilitaciones' => $habilitaciones])]])->saveOrFail();
    }

    public function especialidadModificada(Especialidad $especialidad, Usuario $actor, array $contexto, string $tipo, array $campos): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => $tipo, 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'ESPECIALIDADES', 'operacion' => $tipo, 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_especialidad' => (string) $especialidad->getKey()],
            'cambios_seguros' => ['campos' => $campos]])->saveOrFail();
    }

    public function pacienteCreado(Paciente $paciente, Usuario $actor, array $contexto): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => 'PACIENTE_CREADO', 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'PACIENTES', 'operacion' => 'PACIENTE_CREADO', 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_paciente' => (string) $paciente->getKey()],
            'cambios_seguros' => ['sin_documento' => $paciente->documento_identidad === null]])->saveOrFail();
    }

    public function pacienteModificado(Paciente $paciente, Usuario $actor, array $contexto, array $campos, ?string $motivo, bool $estado = false): void
    {
        $tipo = $estado ? 'PACIENTE_ESTADO_CAMBIADO' : 'PACIENTE_ACTUALIZADO';
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => $tipo, 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'PACIENTES', 'operacion' => $tipo, 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_paciente' => (string) $paciente->getKey()],
            'cambios_seguros' => ['campos' => $campos],
            'metadatos_seguros' => $motivo === null ? null : ['motivo_administrativo' => $motivo]])->saveOrFail();
    }

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
