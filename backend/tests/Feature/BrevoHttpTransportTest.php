<?php

namespace Tests\Feature;

use Clinica\business\services\CorreoSeguridadService;
use Clinica\shared\utils\BrevoHttpTransport;
use Clinica\shared\utils\InvitacionPersonalMail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class BrevoHttpTransportTest extends TestCase
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    private const CLAVE = 'clave-api-exclusivamente-ficticia';

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config(['seguridad.correo.mailer' => 'brevo', 'services.brevo.key' => self::CLAVE,
            'seguridad.correo.frontend_url' => 'http://localhost:5173',
            'mail.from.address' => 'seguridad@example.test', 'mail.from.name' => 'Clínica Vida Salud']);
    }

    public static function mensajes(): array
    {
        return [
            ['enviarInvitacion', '/activar-personal', 'Establece tu acceso — Clínica Vida Salud', '24 horas'],
            ['enviarRecuperacion', '/recuperar-clave', 'Recupera tu acceso — Clínica Vida Salud', '60 minutos'],
        ];
    }

    #[DataProvider('mensajes')]
    public function test_security_messages_use_real_laravel_mailer_with_fake_http_and_fixed_destination(string $metodo, string $ruta, string $asunto, string $vigencia): void
    {
        Http::fake(function (Request $request, array $options) use ($ruta, $asunto, $vigencia) {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('api-key', self::CLAVE));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertSame(['email' => 'seguridad@example.test', 'name' => 'Clínica Vida Salud'], $request['sender']);
            $this->assertSame([['email' => 'destinatario@example.test']], $request['to']);
            $this->assertSame($asunto, $request['subject']);
            $this->assertStringContainsString('http://localhost:5173'.$ruta.'#token='.self::TOKEN, $request['htmlContent']);
            $this->assertStringContainsString($vigencia, $request['htmlContent']);
            $this->assertSame(['sender', 'to', 'subject', 'htmlContent'], array_keys($request->data()));
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['messageId' => '<envio-ficticio@example.test>'], 201);
        });
        $resultado = $this->app->make(CorreoSeguridadService::class)->$metodo('destinatario@example.test', self::TOKEN);
        $this->assertSame('aceptado', $resultado);
        $this->assertInstanceOf(BrevoHttpTransport::class, Mail::mailer('brevo')->getSymfonyTransport());
        $this->assertSame('brevo-http', (string) Mail::mailer('brevo')->getSymfonyTransport());
        Http::assertSentCount(1);
    }

    public function test_provider_message_id_is_returned_as_transport_acceptance(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messageId' => '<aceptado@example.test>'], 201)]);
        $sent = Mail::mailer('brevo')->to('destinatario@example.test')
            ->send(new InvitacionPersonalMail('http://localhost:5173/activar-personal#token='.self::TOKEN));
        $this->assertSame('<aceptado@example.test>', $sent->getMessageId());
        Http::assertSentCount(1);
    }

    public static function rechazos(): array
    {
        return [['redireccion', 302], ['remitente', 400], ['credencial', 401], ['limite', 429],
            ['servidor', 503], ['estado_inesperado', 200], ['programacion_no_solicitada', 202]];
    }

    #[DataProvider('rechazos')]
    public function test_rejections_are_sanitized_without_retry_redirect_or_exposed_response(string $caso, int $status): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messageId' => '<no-aceptar>',
            'message' => self::CLAVE.' '.self::TOKEN.' '.$caso], $status, ['Location' => 'https://ajeno.example.test'])]);
        try {
            $this->app->make(CorreoSeguridadService::class)->enviarRecuperacion('destinatario@example.test', self::TOKEN);
            $this->fail('No debe confirmar un envío rechazado por HTTP.');
        } catch (TransportException $error) {
            $this->assertSame('El servicio de correo no confirmó la aceptación (HTTP '.$status.').', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString(self::CLAVE, $error->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $error->getMessage());
            $this->assertStringNotContainsString('destinatario@example.test', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function confirmacionesInvalidas(): array
    {
        return [[[]], [['messageId' => null]], [['messageId' => ['no-es-texto']]], [['messageId' => '']],
            [['messageId' => "id\r\ninyectado"]], ['respuesta no JSON']];
    }

    #[DataProvider('confirmacionesInvalidas')]
    public function test_success_without_valid_message_id_is_not_reported_as_accepted(array|string $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, 201)]);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('El servicio de correo devolvió una confirmación no válida.');
        $this->app->make(CorreoSeguridadService::class)->enviarRecuperacion('destinatario@example.test', self::TOKEN);
    }

    public function test_connection_or_timeout_failure_never_chains_sensitive_http_exception(): void
    {
        $intentos = 0;
        Http::fake(function () use (&$intentos) {
            $intentos++;

            return Http::failedConnection('cURL error 28: '.self::CLAVE.' '.self::TOKEN);
        });
        try {
            $this->app->make(CorreoSeguridadService::class)->enviarInvitacion('destinatario@example.test', self::TOKEN);
            $this->fail('No debe confirmar el envío si la conexión vence o falla.');
        } catch (TransportException $error) {
            $this->assertSame('No se confirmó la conexión con el servicio de correo.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(1, $intentos);
    }

    public function test_missing_api_key_fails_before_any_http_request_even_if_old_smtp_values_exist(): void
    {
        config(['services.brevo.key' => ' ', 'mail.mailers.brevo.username' => 'ficticio', 'mail.mailers.brevo.password' => 'ficticio']);
        try {
            $this->app->make(CorreoSeguridadService::class)->enviarInvitacion('destinatario@example.test', self::TOKEN);
            $this->fail('La integración HTTP requiere una API key.');
        } catch (RuntimeException $error) {
            $this->assertSame('El transporte de seguridad no está configurado.', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_array_local_transport_remains_simulated_and_makes_no_http_requests(): void
    {
        config(['seguridad.correo.mailer' => 'array', 'services.brevo.key' => null]);
        Mail::fake();
        $this->assertSame('simulado', $this->app->make(CorreoSeguridadService::class)
            ->enviarInvitacion('destinatario@example.test', self::TOKEN));
        Mail::assertSent(InvitacionPersonalMail::class, 1);
        Http::assertNothingSent();
    }
}
