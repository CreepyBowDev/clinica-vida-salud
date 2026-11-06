<?php

namespace Clinica\shared\utils;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class PropuestaMonto
{
    public function emitir(array $datos): string
    {
        return Crypt::encryptString(json_encode(['proposito' => 'asignar_monto', 'version' => 1, ...$datos], JSON_THROW_ON_ERROR));
    }

    public function abrir(string $token): array
    {
        try {
            $datos = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($datos) || Validator::make($datos, [
                'proposito' => ['required', 'in:asignar_monto'], 'version' => ['required', 'integer', 'in:1'],
                'id' => ['required', 'uuid'], 'actor' => ['required', 'string'],
                'rol' => ['required', 'in:Administrador,Recepcionista'], 'cita' => ['required', 'string'],
                'revision' => ['required', 'string'], 'monto_bob' => ['required', 'string'],
            ])->fails()) {
                throw new JsonException;
            }

            return $datos;
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['propuesta' => ['La propuesta no es válida. Revisa nuevamente el monto.']]);
        }
    }
}
