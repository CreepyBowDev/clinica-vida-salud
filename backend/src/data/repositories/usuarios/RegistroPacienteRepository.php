<?php

namespace Clinica\data\repositories\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Paciente;
use Clinica\data\models\RegistroPacientePendiente;
use Clinica\data\models\VerificacionCorreo;
use Illuminate\Support\Facades\DB;
use LogicException;

class RegistroPacienteRepository
{
    public function coordinar(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El registro requiere una transacción.');
        }
        // Exclusión del bloque de registro/vinculación; nunca se mantiene durante el correo externo.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [736202, 1]);
    }

    public function porContexto(string $hash): ?RegistroPacientePendiente
    {
        return RegistroPacientePendiente::query()->where('datos_registro->_contexto_hash', $hash)
            ->orderByDesc('id_registro_pendiente')->lockForUpdate()->first();
    }

    public function porPaciente(int|string $id): iterable
    {
        return RegistroPacientePendiente::query()->where('id_paciente', $id)
            ->whereNull('completado_en')->whereNull('invalidado_en')->lockForUpdate()->get();
    }

    public function reservas(string $ci, string $correo): iterable
    {
        return RegistroPacientePendiente::query()->whereNull('completado_en')->whereNull('invalidado_en')
            ->where(fn ($q) => $q->whereRaw('btrim(documento_identidad) = ?', [$ci])
                ->orWhereRaw('lower(btrim(correo_propuesto)) = ?', [$correo]))->lockForUpdate()->get();
    }

    public function documentoRegistrado(string $ci): bool
    {
        return Paciente::query()->whereRaw('btrim(documento_identidad) = ?', [$ci])->exists();
    }

    public function reservasPorDocumento(string $ci): iterable
    {
        return RegistroPacientePendiente::query()->whereRaw('btrim(documento_identidad) = ?', [$ci])
            ->whereNull('completado_en')->whereNull('invalidado_en')->lockForUpdate()->get();
    }

    public function correoReservado(string $correo, CarbonImmutable $ahora): bool
    {
        return RegistroPacientePendiente::query()->whereRaw('lower(btrim(correo_propuesto)) = ?', [$correo])
            ->whereNull('completado_en')->whereNull('invalidado_en')->where('expira_en', '>', $ahora)->exists();
    }

    public function crear(array $datos): RegistroPacientePendiente
    {
        $registro = new RegistroPacientePendiente;
        $registro->forceFill($datos)->saveOrFail();

        return $registro;
    }

    public function guardar(RegistroPacientePendiente $registro): void
    {
        $registro->saveOrFail();
    }

    public function invalidar(RegistroPacientePendiente $registro, CarbonImmutable $ahora): void
    {
        $registro->forceFill(['invalidado_en' => $ahora, 'password_hash_temporal' => null, 'datos_registro' => null])->saveOrFail();
        $this->invalidarCodigos($registro->getKey(), $ahora);
    }

    public function ultimoCodigo(int|string $id): ?VerificacionCorreo
    {
        return VerificacionCorreo::query()->where('id_registro_pendiente', $id)->where('proposito', 'ActivacionPaciente')
            ->orderByDesc('enviado_en')->orderByDesc('id_verificacion')->lockForUpdate()->first();
    }

    public function invalidarCodigos(int|string $id, CarbonImmutable $ahora): void
    {
        VerificacionCorreo::query()->where('id_registro_pendiente', $id)->where('proposito', 'ActivacionPaciente')
            ->whereNull('invalidado_en')->whereNull('consumido_en')->update(['invalidado_en' => $ahora]);
    }

    public function guardarCodigo(VerificacionCorreo $codigo): void
    {
        $codigo->saveOrFail();
    }

    public function codigoUtilizado(int|string $id, string $hash): bool
    {
        return VerificacionCorreo::query()->where('id_registro_pendiente', $id)
            ->where('proposito', 'ActivacionPaciente')->where('codigo_hash', $hash)->exists();
    }

    public function emitirCodigo(int|string $id, string $correo, string $hash, CarbonImmutable $ahora, CarbonImmutable $expira): void
    {
        $codigo = new VerificacionCorreo;
        $codigo->forceFill(['id_registro_pendiente' => $id, 'proposito' => 'ActivacionPaciente', 'correo_destino' => $correo,
            'codigo_hash' => $hash, 'enviado_en' => $ahora, 'expira_en' => $expira])->saveOrFail();
    }

    public function emisionesHora(int|string $id, string $correo, CarbonImmutable $desde): bool
    {
        $query = VerificacionCorreo::query()->where('proposito', 'ActivacionPaciente')->where('enviado_en', '>', $desde);

        return (clone $query)->where('id_registro_pendiente', $id)->count() >= 5
            || (clone $query)->whereRaw('lower(btrim(correo_destino)) = ?', [$correo])->count() >= 5;
    }

    public function crearPaciente(array $datos): Paciente
    {
        $paciente = new Paciente;
        $paciente->forceFill($datos)->saveOrFail();

        return $paciente;
    }
}
