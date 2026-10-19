<?php

namespace Clinica\shared\utils;

class CredencialTemporal
{
    public static function generar(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $secreto = '';
        for ($i = 0; $i < 20; $i++) {
            $secreto .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $secreto;
    }

    public static function resumen(#[\SensitiveParameter] string $secreto): string
    {
        return hash_hmac('sha256', "credencial-temporal\0".$secreto, (string) config('app.key'));
    }
}
