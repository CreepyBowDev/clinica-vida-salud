<?php

namespace Clinica\data\repositories;

use Carbon\CarbonImmutable;
use Clinica\data\models\CodigoActivacionPaciente;
use Clinica\data\models\Paciente;

class CodigoActivacionRepository
{
    public function pacientePorId(int|string $id): ?Paciente
    {
        return Paciente::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function pacientePorDocumento(string $ci): ?Paciente
    {
        return Paciente::query()->whereRaw('btrim(documento_identidad) = ?', [$ci])->lockForUpdate()->first();
    }

    public function ultimo(int|string $paciente): ?CodigoActivacionPaciente
    {
        return CodigoActivacionPaciente::query()->where('id_paciente', $paciente)
            ->orderByDesc('emitido_en')->orderByDesc('id_codigo_activacion')->lockForUpdate()->first();
    }

    public function porId(int|string $id): ?CodigoActivacionPaciente
    {
        return CodigoActivacionPaciente::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function invalidarAnteriores(int|string $paciente, CarbonImmutable $ahora): void
    {
        CodigoActivacionPaciente::query()->where('id_paciente', $paciente)
            ->whereNull('consumido_en')->whereNull('invalidado_en')->update(['invalidado_en' => $ahora]);
    }

    public function crear(array $datos): CodigoActivacionPaciente
    {
        $codigo = new CodigoActivacionPaciente;
        $codigo->forceFill($datos)->saveOrFail();

        return $codigo;
    }

    public function guardar(CodigoActivacionPaciente $codigo): void
    {
        $codigo->saveOrFail();
    }

    public function vincular(Paciente $paciente, int|string $usuario): void
    {
        $paciente->id_usuario = $usuario;
        $paciente->saveOrFail();
    }
}
