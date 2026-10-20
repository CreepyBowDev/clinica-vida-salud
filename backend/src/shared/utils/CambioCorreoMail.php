<?php

namespace Clinica\shared\utils;

use Illuminate\Mail\Mailable;

class CambioCorreoMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public readonly string $codigo) {}

    public function build(): static
    {
        return $this->subject('Confirma tu nuevo correo — Clínica Vida Salud')
            ->view('emails.cambio-correo', ['codigo' => $this->codigo]);
    }
}
