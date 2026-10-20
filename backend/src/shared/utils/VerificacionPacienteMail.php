<?php

namespace Clinica\shared\utils;

use Illuminate\Mail\Mailable;

class VerificacionPacienteMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public readonly string $codigo) {}

    public function build(): static
    {
        return $this->subject('Verifica tu correo — Clínica Vida Salud')
            ->view('emails.verificacion-paciente', ['codigo' => $this->codigo]);
    }
}
