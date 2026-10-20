<?php

namespace Clinica\shared\utils;

class RegistroPacienteContexto
{
    public const COOKIE = 'clinica_registro_paciente';

    public static function valido(mixed $valor): bool
    {
        return is_string($valor) && preg_match('/^[a-f0-9]{64}$/D', $valor) === 1;
    }

    public static function hash(#[\SensitiveParameter] string $secreto): string
    {
        return hash_hmac('sha256', "registro-paciente\0".$secreto, (string) config('app.key'));
    }

    public static function codigo(int|string $id, #[\SensitiveParameter] string $codigo): string
    {
        return hash_hmac('sha256', "activacion-paciente\0".$id."\0".$codigo, (string) config('app.key'));
    }

    public static function recepcion(int|string $paciente, #[\SensitiveParameter] string $codigo): string
    {
        return hash_hmac('sha256', "recepcion-paciente\0".$paciente."\0".$codigo, (string) config('app.key'));
    }
}
