<?php

namespace Clinica\business\validators;

use Closure;

class PoliticaClave
{
    public static function reglas(): array
    {
        return ['bail', 'required', 'string', 'min:12', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
            if (config('hashing.driver') === 'bcrypt' && (strlen($value) > 72 || str_contains($value, "\0"))) {
                $fail('La contraseña admite hasta 72 bytes y no admite caracteres nulos con el algoritmo actual.');

                return;
            }

            if (! preg_match('/\p{Lu}/u', $value) || ! preg_match('/\p{Ll}/u', $value)
                || ! preg_match('/\p{Nd}/u', $value) || ! preg_match('/[\p{P}\p{S}]/u', $value)) {
                $fail('La contraseña debe incluir al menos una mayúscula, una minúscula, un número y un carácter especial. Los espacios no cuentan como carácter especial.');
            }
        }];
    }
}
