<?php

namespace Clinica\shared\utils;

use Illuminate\Mail\Mailable;

class RecuperacionClaveMail extends Mailable
{
    public function __construct(public readonly string $enlace) {}

    public function build(): static
    {
        return $this->subject('Recupera tu acceso — Clínica Vida Salud')
            ->view('emails.recuperacion-clave', ['enlace' => $this->enlace]);
    }
}
