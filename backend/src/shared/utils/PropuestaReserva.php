<?php

namespace Clinica\shared\utils;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class PropuestaReserva
{
    public function revision(array $contexto, array $turno): string
    {
        // Control de revisión, no credencial ni autorización.
        $clave = hash_hmac('sha256', 'clinica.reservas.revision.v1', Crypt::getKey(), true);

        return hash_hmac('sha256', json_encode(['proposito' => 'revision_turno', 'version' => 1,
            'contexto' => $contexto, 'turno' => $turno], JSON_THROW_ON_ERROR), $clave);
    }

    public function motivo(#[\SensitiveParameter] ?string $motivo): string
    {
        return hash_hmac('sha256', json_encode($motivo, JSON_THROW_ON_ERROR),
            hash_hmac('sha256', 'clinica.reservas.motivo.v1', Crypt::getKey(), true));
    }

    public function emitir(#[\SensitiveParameter] array $datos): string
    {
        return Crypt::encryptString(json_encode(['proposito' => 'reserva_cita', 'version' => 1, ...$datos], JSON_THROW_ON_ERROR));
    }

    public function abrir(#[\SensitiveParameter] string $propuesta): array
    {
        try {
            $datos = json_decode(Crypt::decryptString($propuesta), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->invalida();
        }
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/', 'integer', 'max:9223372036854775807'];
        if (! is_array($datos) || Validator::make($datos, [
            'proposito' => ['required', 'in:reserva_cita'], 'version' => ['required', 'integer', 'in:1'],
            'id_propuesta' => ['required', 'uuid'], 'actor' => $id, 'rol' => ['required', 'in:Recepcionista,Paciente'],
            'id_paciente' => $id, 'seleccion' => ['required', 'array'], 'seleccion.id_medico' => $id,
            'seleccion.id_especialidad' => $id, 'seleccion.fecha' => ['required', 'date_format:Y-m-d', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'seleccion.hora_inicio' => ['required', 'date_format:H:i:s'],
            'revision_turno' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'motivo_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'resumen' => ['required', 'array'], 'resumen.paciente' => ['required', 'array'],
            'resumen.consultorio' => ['required', 'array'], 'resumen.duracion_minutos' => ['required', 'integer', 'min:1'],
        ])->fails()) {
            $this->invalida();
        }

        return $datos;
    }

    public function invalida(): never
    {
        throw ValidationException::withMessages(['propuesta' => ['La propuesta no es válida. Consulta nuevamente la disponibilidad.']]);
    }
}
