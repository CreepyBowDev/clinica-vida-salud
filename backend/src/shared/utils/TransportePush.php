<?php

namespace Clinica\shared\utils;

use Carbon\CarbonImmutable;
use Clinica\business\validators\SuscripcionPushValidator;
use Clinica\shared\errors\AppError;
use DateTimeInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class TransportePush
{
    public function __construct(private ResolucionPush $dns) {}

    // El llamador F6 debe autorizar el destinatario y su snapshot justo antes de invocar.
    // No persiste estados, revoca suscripciones ni reintenta.
    public function enviar(#[\SensitiveParameter] array $suscripcion, DateTimeInterface $inicio): array
    {
        $ahora = CarbonImmutable::now('UTC');
        $restante = $inicio->getTimestamp() - $ahora->getTimestamp()
            + ((int) $inicio->format('u') - (int) $ahora->format('u')) / 1_000_000;
        if ($restante <= 0) {
            return $this->resultado('NoEnviado');
        }
        $publica = config('push.vapid_publica');
        $privada = config('push.vapid_privada');
        $sujeto = config('push.vapid_sujeto');
        if (! config('push.habilitado') || ! (new SuscripcionPushValidator)->clavePublicaValida($publica)
            || ! is_string($privada) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $privada) !== 1
            || ! is_string($sujeto) || ! $this->sujetoValido($sujeto)
            || ! $this->parValido($publica, $privada)) {
            throw new AppError('PUSH_NO_CONFIGURADO', 'El servicio de notificaciones no está configurado.');
        }
        $client = $this->cliente($inicio);
        try {
            $validada = (new SuscripcionPushValidator)->validar($suscripcion);
            $factory = new HttpFactory;
            $push = new WebPush(['VAPID' => ['subject' => $sujeto, 'publicKey' => $publica, 'privateKey' => $privada]],
                ['TTL' => (int) floor($restante), 'urgency' => 'normal', 'batchSize' => 1], $client, $factory, $factory);
            $validada['contentEncoding'] = 'aes128gcm';
            $report = $push->sendOneNotification(Subscription::create($validada),
                '{"titulo":"Clínica Vida Salud","mensaje":"Tienes un aviso disponible. Consulta el sistema."}');
            $response = $report->getResponse();

            return $response === null
                ? $this->resultado($client->falloIncierto === true ? 'Incierto' : 'NoEnviado')
                : $this->clasificar($response);
        } catch (Throwable) {
            // No devolver reportes, excepciones ni cuerpos del proveedor: contienen secretos.
            return $this->resultado($client->falloIncierto === true ? 'Incierto' : 'NoEnviado');
        }
    }

    public function clasificar(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $tipo = match ($status) {
            201 => 'Aceptado',
            404, 410 => 'Obsoleta',
            400, 413 => 'Fallido',
            401, 403 => 'Configuracion',
            429, 503 => 'Reintentable',
            default => 'Incierto',
        };
        $espera = null;
        if ($tipo === 'Reintentable') {
            $valor = $response->getHeaderLine('Retry-After');
            if (preg_match('/\A[0-9]{1,10}\z/', $valor) === 1) {
                $espera = (int) $valor;
            } elseif (strlen($valor) <= 64) {
                $fecha = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $valor, new \DateTimeZone('UTC'));
                if ($fecha !== false && $fecha->format(DATE_RFC7231) === $valor) {
                    $espera = max(0, (int) ceil((float) $fecha->format('U.u') - (float) CarbonImmutable::now('UTC')->format('U.u')));
                }
            }
        }

        return $this->resultado($tipo, $status, $espera);
    }

    protected function cliente(DateTimeInterface $inicio): ClientePushSeguro
    {
        return new ClientePushSeguro($this->dns, $inicio);
    }

    private function resultado(string $tipo, ?int $http = null, ?int $espera = null): array
    {
        return ['tipo' => $tipo, 'http' => $http, 'retry_after' => $espera];
    }

    private function sujetoValido(string $sujeto): bool
    {
        if (str_starts_with($sujeto, 'mailto:')) {
            return filter_var(substr($sujeto, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        $url = parse_url($sujeto);

        return filter_var($sujeto, FILTER_VALIDATE_URL) !== false
            && str_starts_with($sujeto, 'https://') && preg_match('/[\x00-\x20\x7f]/', $sujeto) === 0
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment']);
    }

    private function parValido(string $publica, #[\SensitiveParameter] string $privada): bool
    {
        $raw = base64_decode(strtr($privada, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 32
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $privada) {
            return false;
        }
        // Carga el escalar existente; no genera claves ni escribe archivos.
        $der = hex2bin('30310201010420').$raw.hex2bin('a00a06082a8648ce3d030107');
        $key = @openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".
            chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! $details || ! isset($details['ec']['x'], $details['ec']['y'])) {
            return false;
        }
        $point = "\x04".str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return hash_equals($publica, rtrim(strtr(base64_encode($point), '+/', '-_'), '='));
    }
}
