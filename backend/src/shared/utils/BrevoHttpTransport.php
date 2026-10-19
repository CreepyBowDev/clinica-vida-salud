<?php

namespace Clinica\shared\utils;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class BrevoHttpTransport extends AbstractTransport
{
    public function __construct(
        private Factory $http,
        #[\SensitiveParameter] private string $apiKey,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'brevo-http';
    }

    protected function doSend(#[\SensitiveParameter] SentMessage $message): void
    {
        if (trim($this->apiKey) === '') {
            throw new TransportException('El transporte de seguridad no está configurado.');
        }
        $email = $message->getOriginalMessage();
        // Transporte acotado a nuestros mensajes de seguridad, sin copias ni documentos adjuntos.
        if (! $email instanceof Email || count($email->getFrom()) !== 1 || count($email->getTo()) !== 1
            || $email->getCc() !== [] || $email->getBcc() !== [] || $email->getAttachments() !== []
            || ! is_string($email->getHtmlBody()) || $email->getHtmlBody() === '') {
            throw new TransportException('El mensaje de seguridad no es compatible con el transporte.');
        }
        $remitente = $email->getFrom()[0];
        $destinatario = $email->getTo()[0];
        try {
            $response = $this->http->acceptJson()->asJson()
                ->withHeaders(['api-key' => $this->apiKey])
                ->connectTimeout(3)->timeout(10)
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => ['email' => $remitente->getAddress(), 'name' => $remitente->getName()],
                    'to' => [['email' => $destinatario->getAddress()]],
                    'subject' => $email->getSubject(),
                    'htmlContent' => $email->getHtmlBody(),
                ]);
        } catch (ConnectionException) {
            // No encadenar la excepción HTTP: puede contener petición, clave o enlace secreto.
            throw new TransportException('No se confirmó la conexión con el servicio de correo.');
        }
        // Sin throw() ni cuerpo externo en errores; no hay reintentos automáticos ni redirecciones.
        if ($response->status() !== 201) {
            throw new TransportException('El servicio de correo no confirmó la aceptación (HTTP '.$response->status().').');
        }
        $id = $response->json('messageId');
        if (! is_string($id) || trim($id) === '' || strlen($id) > 998 || strpbrk($id, "\r\n") !== false) {
            throw new TransportException('El servicio de correo devolvió una confirmación no válida.');
        }
        $message->setMessageId($id);
    }
}
