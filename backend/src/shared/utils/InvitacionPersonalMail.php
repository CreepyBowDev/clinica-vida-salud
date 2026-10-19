<?php

namespace Clinica\shared\utils;

use Illuminate\Mail\Mailable;

class InvitacionPersonalMail extends Mailable
{
    public function __construct(public readonly string $enlace) {}

    public function build(): static
    {
        return $this->subject('Establece tu acceso — Clínica Vida Salud')
            ->view('emails.invitacion-personal', ['enlace' => $this->enlace]);
    }
}
