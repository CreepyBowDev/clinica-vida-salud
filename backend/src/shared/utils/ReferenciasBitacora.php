<?php

namespace Clinica\shared\utils;

class ReferenciasBitacora
{
    public const ENTIDADES = ['Usuario', 'Paciente', 'Medico', 'Especialidad', 'Consultorio', 'HorarioAtencion', 'ConfiguracionClinica', 'Cita', 'CuentaPrueba', 'ConjuntoDemostracion'];

    public const EVENTOS_PACIENTE = ['ACTIVACION_PACIENTE_EMITIDA', 'VINCULACION_PACIENTE_INICIADA', 'PACIENTE_CUENTA_VINCULADA'];

    public static function id(mixed $valor): ?string
    {
        if ((! is_string($valor) && ! is_int($valor)) || ! preg_match('/^[0-9]{1,19}$/D', (string) $valor)) {
            return null;
        }
        $id = ltrim((string) $valor, '0');

        return $id !== '' && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0) ? $id : null;
    }

    public static function registro(string $entidad, mixed $valor): ?string
    {
        if (! in_array($entidad, self::ENTIDADES, true)) {
            return null;
        }
        if (in_array($entidad, ['CuentaPrueba', 'ConjuntoDemostracion'], true)) {
            return is_string($valor) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $valor) ? $valor : null;
        }

        return self::id($valor);
    }

    public static function adicionales(string $tipo, mixed $referencias): object
    {
        if ($tipo === 'CITA_PROGRAMADA' && is_object($referencias)) {
            $salida = [];
            foreach (['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'] as $clave) {
                if (($id = self::id($referencias->{$clave} ?? null)) !== null) {
                    $salida[$clave] = $id;
                }
            }

            return (object) $salida;
        }
        if (in_array($tipo, ['MEDICO_CONSULTORIO_CAMBIADO', 'HORARIO_CREADO', 'HORARIO_ACTUALIZADO', 'HORARIO_ESTADO_CAMBIADO'], true) && is_object($referencias)) {
            $salida = [];
            foreach ($tipo === 'MEDICO_CONSULTORIO_CAMBIADO' ? ['id_medico', 'id_consultorio'] : ['id_medico', 'id_horario'] as $clave) {
                if (($id = self::id($referencias->{$clave} ?? null)) !== null) {
                    $salida[$clave] = $id;
                }
            }

            return (object) $salida;
        }
        $clave = match ($tipo) {
            'PACIENTE_CREADO', 'PACIENTE_ACTUALIZADO', 'PACIENTE_ESTADO_CAMBIADO' => 'id_paciente',
            'MEDICO_CREADO', 'MEDICO_ACTUALIZADO', 'MEDICO_ESTADO_CAMBIADO', 'MEDICO_ESPECIALIDADES_CAMBIADAS' => 'id_medico',
            'ESPECIALIDAD_CREADA', 'ESPECIALIDAD_ACTUALIZADA', 'ESPECIALIDAD_ESTADO_CAMBIADO' => 'id_especialidad',
            'CONSULTORIO_CREADO', 'CONSULTORIO_ACTUALIZADO', 'CONSULTORIO_ESTADO_CAMBIADO' => 'id_consultorio',
            'DURACION_CITAS_CAMBIADA' => 'id_configuracion',
            default => null,
        };
        $id = $clave !== null && is_object($referencias) ? self::id($referencias->{$clave} ?? null) : null;

        return (object) ($id === null ? [] : [$clave => $id]);
    }
}
