<?php

namespace Clinica\data\repositories\citas;

use Carbon\CarbonImmutable;
use Clinica\data\models\CambioCita;
use Clinica\data\models\Cita;
use Clinica\data\models\ConsultaMedica;

class CitaRepository
{
    public function guardar(Cita $cita): void
    {
        $cita->saveOrFail();
    }

    public function agregarCambio(Cita $cita, string $actor, array $antes, array $despues, CarbonImmutable $ahora,
        string $operacion = 'REPROGRAMACION', ?string $motivo = null): CambioCita
    {
        $cambio = new CambioCita;
        $cambio->forceFill(['id_cita' => $cita->getKey(), 'id_actor' => $actor, 'operacion' => $operacion, 'motivo' => $motivo,
            'realizado_en' => $ahora, 'estado_anterior' => $antes['estado'], 'estado_nuevo' => $despues['estado'],
            'datos_anteriores' => (object) $antes, 'datos_nuevos' => (object) $despues])->saveOrFail();

        return $cambio;
    }

    public function ultimoCambio(string $id): ?CambioCita
    {
        return CambioCita::query()->where('id_cita', $id)->orderByDesc('realizado_en')->orderByDesc('id_cambio_cita')->first();
    }

    public function porId(string $id, bool $bloquear = false): ?Cita
    {
        return Cita::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function tieneConsulta(string $id): bool
    {
        return ConsultaMedica::query()->where('id_cita', $id)->exists();
    }

    public function porPropuesta(string $uuid, bool $bloquear = false): ?Cita
    {
        return Cita::query()->where('id_propuesta_reserva', $uuid)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function pacienteSolapado(string $paciente, CarbonImmutable $inicio, CarbonImmutable $fin): bool
    {
        return Cita::query()->where('id_paciente', $paciente)->whereIn('estado', ['Programada', 'Confirmada'])
            ->where('inicio_at', '<', $fin->format('Y-m-d H:i:s.uP'))
            ->where('fin_at', '>', $inicio->format('Y-m-d H:i:s.uP'))->exists();
    }

    public function crear(array $datos): Cita
    {
        $cita = new Cita;
        $cita->forceFill($datos)->saveOrFail();

        return $cita;
    }
}
