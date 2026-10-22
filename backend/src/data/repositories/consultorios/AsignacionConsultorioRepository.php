<?php

namespace Clinica\data\repositories\consultorios;

use Carbon\CarbonImmutable;
use Clinica\data\models\CambioCita;
use Clinica\data\models\Cita;
use Clinica\data\models\Consultorio;
use Clinica\data\models\HorarioAtencion;
use Illuminate\Database\Eloquent\Collection;

class AsignacionConsultorioRepository
{
    public function horarios(int|string $medico): Collection
    {
        return HorarioAtencion::query()->where('id_medico', $medico)->where('activo', true)->orderBy('id_horario')->get();
    }

    public function compartidos(int|string $medico, int|string $consultorio): Collection
    {
        // La actividad del Médico no libera horarios activos.
        return HorarioAtencion::query()->where('activo', true)->where('id_medico', '<>', $medico)
            ->whereHas('medico', fn ($q) => $q->where('id_consultorio', $consultorio))->orderBy('id_horario')->get();
    }

    public function citas(int|string $medico, array $consultorios, CarbonImmutable $ahora): Collection
    {
        return Cita::query()->select(['id_cita', 'id_medico', 'id_consultorio', 'estado', 'inicio_at', 'fin_at',
            'duracion_minutos', 'consultorio_atencion', 'version_programacion', 'actualizada_en'])
            ->withExists('consulta')->where('estado', '<>', 'Cancelada')
            ->where('fin_at', '>', $ahora->format('Y-m-d H:i:s.uP'))
            ->where(fn ($q) => $q->where('id_medico', $medico)->orWhereIn('id_consultorio', $consultorios))
            ->orderBy('id_cita')->lockForUpdate()->get();
    }

    public function trasladar(Cita $cita, Consultorio $destino, int|string $actor, CarbonImmutable $ahora): void
    {
        $antes = ['id_consultorio' => (string) $cita->id_consultorio, 'consultorio_atencion' => $cita->consultorio_atencion];
        $despues = ['id_consultorio' => (string) $destino->getKey(),
            'consultorio_atencion' => $destino->codigo_consultorio.' — '.$destino->nombre];
        $cita->forceFill([...$despues, 'actualizada_en' => $ahora->greaterThan($cita->actualizada_en)
            ? $ahora : $cita->actualizada_en->addMicrosecond()])->saveOrFail();
        $cambio = new CambioCita;
        $cambio->forceFill(['id_cita' => $cita->getKey(), 'id_actor' => $actor, 'operacion' => 'CAMBIO_UBICACION',
            'realizado_en' => $ahora, 'estado_anterior' => $cita->estado, 'estado_nuevo' => $cita->estado,
            'datos_anteriores' => (object) $antes, 'datos_nuevos' => (object) $despues])->saveOrFail();
        // El aviso referencia la cita: obtiene esta ubicación sin cambiar su versión/lectura.
    }
}
