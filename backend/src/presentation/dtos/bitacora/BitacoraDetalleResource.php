<?php

namespace Clinica\presentation\dtos\bitacora;

use Clinica\shared\utils\ReferenciasBitacora;
use Illuminate\Http\Request;

class BitacoraDetalleResource extends BitacoraListadoResource
{
    private const CAMPOS_PACIENTE = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo',
        'telefono', 'correo_contacto', 'direccion', 'activo', 'contacto_emergencia', 'recordatorios_habilitados'];

    private const CAMPOS_MEDICO = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto', 'activo', 'especialidades'];

    private const CAMPOS_ESPECIALIDAD = ['nombre', 'descripcion', 'activa'];

    private const CAMPOS_CONSULTORIO = ['codigo_consultorio', 'nombre', 'activo'];

    public function toArray(Request $request): array
    {
        return [...parent::toArray($request),
            'procedencia_ip' => $this->procedencia_ip,
            'correlacion' => $this->correlacion,
            'id_evento_relacionado' => $this->id_evento_relacionado === null ? null : (string) $this->id_evento_relacionado,
            'version_formato' => $this->version_formato,
            'cambios_seguros' => $this->version_formato === 1 ? $this->cambios() : (object) [],
            'metadatos_seguros' => $this->version_formato === 1 ? $this->metadatos() : (object) [],
        ];
    }

    private function cambios(): object
    {
        $origen = $this->cambios_seguros;
        if (! is_object($origen)) {
            return (object) [];
        }
        $salida = [];
        if ($this->tipo_evento === 'DURACION_CITAS_CAMBIADA') {
            foreach (['antes', 'despues'] as $clave) {
                $duracion = $origen->{$clave}->duracion_turno_minutos ?? null;
                if (is_int($duracion) && $duracion > 0 && $duracion <= 2147483647) {
                    $salida[$clave] = (object) ['duracion_turno_minutos' => $duracion];
                }
            }
        }
        if ($this->tipo_evento === 'MEDICO_CONSULTORIO_CAMBIADO') {
            foreach (['antes', 'despues'] as $clave) {
                $estado = $origen->{$clave} ?? null;
                if (is_object($estado) && property_exists($estado, 'id_consultorio')) {
                    $id = ReferenciasBitacora::id($estado->id_consultorio);
                    if ($id !== null || $estado->id_consultorio === null) {
                        $salida[$clave] = (object) ['id_consultorio' => $id];
                    }
                }
            }
            if (is_int($origen->citas_trasladadas ?? null) && $origen->citas_trasladadas >= 0) {
                $salida['citas_trasladadas'] = $origen->citas_trasladadas;
            }
        }
        if ($this->tipo_evento === 'PACIENTE_CREADO' && is_bool($origen->sin_documento ?? null)) {
            $salida['sin_documento'] = $origen->sin_documento;
        }
        $campos = match ($this->tipo_evento) {
            'PACIENTE_ACTUALIZADO', 'PACIENTE_ESTADO_CAMBIADO' => self::CAMPOS_PACIENTE,
            'MEDICO_CREADO', 'MEDICO_ACTUALIZADO', 'MEDICO_ESTADO_CAMBIADO', 'MEDICO_ESPECIALIDADES_CAMBIADAS' => self::CAMPOS_MEDICO,
            'ESPECIALIDAD_CREADA', 'ESPECIALIDAD_ACTUALIZADA', 'ESPECIALIDAD_ESTADO_CAMBIADO' => self::CAMPOS_ESPECIALIDAD,
            'CONSULTORIO_CREADO', 'CONSULTORIO_ACTUALIZADO', 'CONSULTORIO_ESTADO_CAMBIADO' => self::CAMPOS_CONSULTORIO,
            'HORARIO_CREADO', 'HORARIO_ACTUALIZADO', 'HORARIO_ESTADO_CAMBIADO' => ['dia_semana', 'hora_inicio', 'hora_fin', 'activo'],
            default => null,
        };
        if ($campos !== null) {
            $lista = $this->lista($origen->campos ?? null, fn ($valor) => is_string($valor) && in_array($valor, $campos, true) ? $valor : null);
            if ($lista !== null) {
                $salida['campos'] = $lista;
            }
            if ($campos === self::CAMPOS_MEDICO && is_object($origen->habilitaciones ?? null)) {
                $habilitaciones = [];
                foreach (['habilitadas', 'retiradas'] as $clave) {
                    $ids = $this->lista($origen->habilitaciones->{$clave} ?? null, ReferenciasBitacora::id(...));
                    if ($ids !== null) {
                        $habilitaciones[$clave] = $ids;
                    }
                }
                if ($habilitaciones !== []) {
                    $salida['habilitaciones'] = (object) $habilitaciones;
                }
            }
        }
        if (in_array($this->tipo_evento, ['CUENTA_DESACTIVADA', 'CUENTA_REACTIVADA', 'CUENTA_ROL_CAMBIADO'], true)) {
            foreach (['antes', 'despues'] as $clave) {
                $estado = $origen->{$clave} ?? null;
                if (! is_object($estado)) {
                    continue;
                }
                $valores = [];
                if (in_array($estado->rol ?? null, ['Administrador', 'Recepcionista', 'Médico', 'Paciente'], true)) {
                    $valores['rol'] = $estado->rol;
                }
                if (in_array($estado->estado_cuenta ?? null, ['Pendiente', 'Activa', 'Desactivada'], true)) {
                    $valores['estado_cuenta'] = $estado->estado_cuenta;
                }
                if (property_exists($estado, 'id_medico') && $estado->id_medico === null) {
                    $valores['id_medico'] = null;
                } elseif (($id = ReferenciasBitacora::id($estado->id_medico ?? null)) !== null) {
                    $valores['id_medico'] = $id;
                }
                if ($valores !== []) {
                    $salida[$clave] = (object) $valores;
                }
            }
        }
        if (in_array($this->tipo_evento, ReferenciasBitacora::EVENTOS_PACIENTE, true)) {
            foreach (['id_paciente', 'id_codigo_activacion'] as $clave) {
                $id = ReferenciasBitacora::id($origen->{$clave} ?? null);
                if ($id !== null) {
                    $salida[$clave] = $id;
                }
            }
        }

        return (object) $salida;
    }

    private function lista(mixed $origen, callable $validar): ?array
    {
        if (! is_array($origen) || ! array_is_list($origen) || count($origen) > 100) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map($validar, $origen), fn ($valor) => $valor !== null)));
    }

    private function metadatos(): object
    {
        $verificacion = match ($this->tipo_evento) {
            'INSTALACION_ADMIN_INICIAL' => 'AUTORIZACION_INSTALACION',
            'SEED_CUENTA_PRUEBA' => 'SIMULADA_PRUEBAS',
            default => null,
        };
        $origen = $this->metadatos_seguros;
        if ($verificacion === null || ! is_object($origen)) {
            return (object) [];
        }
        $salida = [];
        if (($origen->verificacion_correo ?? null) === $verificacion) {
            $salida['verificacion_correo'] = $verificacion;
        }
        if (($origen->metodo ?? null) === 'SEEDER') {
            $salida['metodo'] = 'SEEDER';
        }

        return (object) $salida;
    }
}
