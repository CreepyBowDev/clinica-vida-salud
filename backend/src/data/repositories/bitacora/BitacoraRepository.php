<?php

namespace Clinica\data\repositories\bitacora;

use Carbon\CarbonImmutable;
use Clinica\data\models\Bitacora;
use Clinica\data\models\Cita;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Especialidad;
use Clinica\data\models\Medico;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\shared\utils\ReferenciasBitacora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BitacoraRepository
{
    public function porId(int|string $id): ?Bitacora
    {
        // Los eventos son inmutables: solo se bloquea la cuenta del lector en el Service.
        return Bitacora::query()->find($id);
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Bitacora::query();
        foreach (['id_usuario_responsable', 'id_usuario_objetivo', 'tipo_actor', 'modulo', 'operacion', 'tipo_evento', 'resultado', 'direccion_ip'] as $campo) {
            if (isset($filtros[$campo])) {
                $query->where($campo, $filtros[$campo]);
            }
        }
        if (isset($filtros['fecha_desde'])) {
            $query->where('ocurrido_en', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_desde'], 'America/La_Paz')->utc()->format('Y-m-d H:i:s.uP'));
        }
        if (isset($filtros['fecha_hasta'])) {
            $query->where('ocurrido_en', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filtros['fecha_hasta'], 'America/La_Paz')->addDay()->utc()->format('Y-m-d H:i:s.uP'));
        }
        if (isset($filtros['entidad_referida'])) {
            $this->filtrarRecurso($query, $filtros['entidad_referida'], isset($filtros['registro_referido'])
                ? ReferenciasBitacora::registro($filtros['entidad_referida'], $filtros['registro_referido']) : null);
        }

        return $query->orderByDesc('registrado_en')->orderByDesc('id_evento')->paginate($filtros['per_page'] ?? 20,
            ['id_evento', 'tipo_evento', 'tipo_actor', 'id_usuario_responsable', 'rol_responsable', 'id_usuario_objetivo', 'rol_objetivo',
                'modulo', 'operacion', 'resultado', 'causa_codigo', 'ocurrido_en', 'registrado_en', 'direccion_ip',
                'entidad_referida', 'registro_referido', 'referencias', 'version_formato'], 'page', $filtros['page'] ?? 1);
    }

    private function filtrarRecurso(Builder $query, string $entidad, ?string $registro): void
    {
        $query->where(function (Builder $recursos) use ($entidad, $registro): void {
            $recursos->where(function (Builder $fisico) use ($entidad, $registro): void {
                $fisico->where('entidad_referida', $entidad);
                $valor = in_array($entidad, ['CuentaPrueba', 'ConjuntoDemostracion'], true)
                    ? "CASE WHEN registro_referido ~ '^[A-Za-z0-9_-]{1,64}$' THEN registro_referido END"
                    : $this->idSql('registro_referido');
                $this->compararReferencia($fisico, $valor, $registro);
            });
            if ($entidad === 'Usuario') {
                $recursos->orWhere(function (Builder $objetivo) use ($registro): void {
                    $registro === null ? $objetivo->whereNotNull('id_usuario_objetivo') : $objetivo->where('id_usuario_objetivo', $registro);
                });
            }
            $clave = match ($entidad) {
                'Paciente' => 'id_paciente', 'Medico' => 'id_medico', 'Especialidad' => 'id_especialidad', 'Consultorio' => 'id_consultorio',
                'HorarioAtencion' => 'id_horario', 'ConfiguracionClinica' => 'id_configuracion', default => null,
            };
            if ($clave !== null) {
                $recursos->orWhere(function (Builder $referencia) use ($clave, $registro): void {
                    $this->compararReferencia($referencia, $this->idSql("referencias->>'$clave'"), $registro);
                });
            }
            if ($entidad === 'Paciente') {
                $recursos->orWhere(function (Builder $cambio) use ($registro): void {
                    $cambio->whereIn('tipo_evento', ReferenciasBitacora::EVENTOS_PACIENTE);
                    $this->compararReferencia($cambio, $this->idSql("cambios_seguros->>'id_paciente'"), $registro);
                });
            }
        });
    }

    private function compararReferencia(Builder $query, string $valor, ?string $registro): void
    {
        $registro === null ? $query->whereRaw("($valor) IS NOT NULL") : $query->whereRaw("($valor) = ?", [$registro]);
    }

    private function idSql(string $expresion): string
    {
        // Expresiones exclusivamente internas. Sin casts numéricos que puedan fallar con JSON histórico inválido.
        $id = "ltrim(($expresion), '0')";

        return "CASE WHEN ($expresion) ~ '^[0-9]{1,19}$' AND $id <> ''
            AND (length($id) < 19 OR $id COLLATE \"C\" <= '9223372036854775807') THEN $id END";
    }

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

    public function planificacionModificada(Usuario $actor, array $contexto, string $tipo, array $referencias, array $cambios): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => $tipo, 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'HORARIOS', 'operacion' => $tipo, 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => $referencias, 'cambios_seguros' => $cambios])->saveOrFail();
    }

    public function citaProgramada(Cita $cita, Usuario $actor, array $contexto): void
    {
        $referencias = [];
        foreach (['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'] as $campo) {
            $referencias[$campo] = (string) $cita->getAttribute($campo);
        }
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => 'CITA_PROGRAMADA', 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'CITAS', 'operacion' => 'CITA_PROGRAMADA', 'resultado' => 'EXITO',
            'entidad_referida' => 'Cita', 'registro_referido' => (string) $cita->getKey(),
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => $referencias, 'cambios_seguros' => ['origen' => $actor->rol->nombre === 'Paciente' ? 'PROPIA' : 'RECEPCION',
                'estado' => 'Programada', 'duracion_minutos' => $cita->duracion_minutos]])->saveOrFail();
    }

    public function asignacionConsultorio(Medico $medico, Usuario $actor, array $contexto, ?string $anterior, int $trasladadas): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => 'MEDICO_CONSULTORIO_CAMBIADO', 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'CONSULTORIOS', 'operacion' => 'MEDICO_CONSULTORIO_CAMBIADO', 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_medico' => (string) $medico->getKey(), 'id_consultorio' => (string) $medico->id_consultorio],
            'cambios_seguros' => ['antes' => ['id_consultorio' => $anterior],
                'despues' => ['id_consultorio' => (string) $medico->id_consultorio], 'citas_trasladadas' => $trasladadas]])->saveOrFail();
    }

    public function consultorioModificado(Consultorio $consultorio, Usuario $actor, array $contexto, string $tipo, array $campos): void
    {
        $evento = new Bitacora;
        $evento->forceFill(['tipo_evento' => $tipo, 'tipo_actor' => 'USUARIO',
            'id_usuario_responsable' => $actor->getKey(), 'rol_responsable' => $actor->rol->nombre,
            'modulo' => 'CONSULTORIOS', 'operacion' => $tipo, 'resultado' => 'EXITO',
            'ocurrido_en' => CarbonImmutable::now('UTC'), 'direccion_ip' => $contexto['ip'],
            'procedencia_ip' => $contexto['procedencia'], 'correlacion' => $contexto['correlacion'],
            'referencias' => ['id_consultorio' => (string) $consultorio->getKey()],
            'cambios_seguros' => ['campos' => $campos]])->saveOrFail();
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
