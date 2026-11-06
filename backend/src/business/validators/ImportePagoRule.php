<?php

namespace Clinica\business\validators;

use Clinica\shared\utils\ImportePago;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ImportePagoRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            ImportePago::desdeBob($value);
        } catch (InvalidArgumentException) {
            $fail('Introduce un monto BOB string entre 7.00 y 7000.00, con máximo dos decimales.');
        }
    }
}
