<?php

namespace Clinica\business\services;

use Clinica\shared\utils\InvitacionPersonalMail;
use Clinica\shared\utils\RecuperacionClaveMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class CorreoSeguridadService
{
    public function enviarInvitacion(string $correo, #[\SensitiveParameter] string $token): string
    {
        return $this->enviar($correo, new InvitacionPersonalMail($this->frontend().'/activar-personal#token='.$token));
    }

    public function enviarRecuperacion(string $correo, #[\SensitiveParameter] string $token): string
    {
        return $this->enviar($correo, new RecuperacionClaveMail($this->frontend().'/recuperar-clave#token='.$token));
    }

    private function enviar(string $correo, Mailable $mensaje): string
    {
        $mailer = config('seguridad.correo.mailer');
        if (! in_array($mailer, ['array', 'brevo'], true)
            || ($mailer === 'array' && ! app()->environment('local', 'testing'))
            || ($mailer === 'brevo' && trim((string) config('services.brevo.key')) === '')) {
            throw new RuntimeException('El transporte de seguridad no está configurado.');
        }
        Mail::mailer($mailer)->to($correo)->send($mensaje);

        return $mailer === 'array' ? 'simulado' : 'aceptado';
    }

    private function frontend(): string
    {
        $frontend = rtrim((string) config('seguridad.correo.frontend_url'), '/');
        $partes = parse_url($frontend);
        if (! is_array($partes) || ! isset($partes['host'], $partes['scheme'])
            || ! in_array($partes['scheme'], ['http', 'https'], true) || isset($partes['user']) || isset($partes['pass'])
            || isset($partes['query']) || isset($partes['fragment'])
            || (app()->environment('production') && $partes['scheme'] !== 'https')) {
            throw new RuntimeException('El destino de seguridad no está configurado.');
        }

        // Fragmento: el secreto no se envía al servidor web en el GET del enlace.
        return $frontend;
    }
}
