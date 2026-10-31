<?php

namespace Clinica\shared\utils;

use Carbon\CarbonImmutable;
use Clinica\business\validators\SuscripcionPushValidator;
use Clinica\shared\errors\ConexionPushError;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ClientePushSeguro implements ClientInterface
{
    public ?bool $falloIncierto = null;

    public function __construct(private ResolucionPush $dns, private DateTimeInterface $inicio) {}

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->falloIncierto = false;
        try {
            $endpoint = (string) $request->getUri();
            if ($request->getMethod() !== 'POST' || ! (new SuscripcionPushValidator)->endpointValido($endpoint)
                || $request->getHeaderLine('Host') !== $request->getUri()->getAuthority()) {
                throw new ConexionPushError(false);
            }
            $host = $request->getUri()->getHost();
            $ips = $this->dns->resolver($host);
            if ($ips === [] || count($ips) > 64) {
                throw new ConexionPushError(false);
            }
            foreach ($ips as $ip) {
                if (! is_string($ip) || ! $this->dns->publica($ip)) {
                    throw new ConexionPushError(false);
                }
            }
            // Reloj fresco después de resolver DNS; jamás enviar desde inicio_at.
            $ahora = CarbonImmutable::now('UTC');
            $restante = $this->inicio->getTimestamp() - $ahora->getTimestamp()
                + ((int) $this->inicio->format('u') - (int) $ahora->format('u')) / 1_000_000;
            // cURL trabaja en milisegundos: cero deshabilitaría el timeout.
            $timeout = min(10, floor($restante * 1000) / 1000);
            if ($timeout < 0.001 || ! defined('CURLOPT_PREREQFUNCTION')) {
                throw new ConexionPushError(false);
            }
            $ttl = $request->getHeaderLine('TTL');
            if (preg_match('/\A[0-9]{1,10}\z/', $ttl) !== 1) {
                throw new ConexionPushError(false);
            }
            // Reserva también el presupuesto HTTP: el TTL empieza en el proveedor,
            // no cuando se prepara esta petición. Se permite TTL cero.
            $request = $request->withHeader('TTL', (string) min((int) $ttl, (int) floor(max(0, $restante - $timeout))))
                ->withProtocolVersion('1.1');
            $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $client = new Client(['handler' => $this->handler()]);
            $this->falloIncierto = true;
            $recibidos = 0;
            $response = $client->send($request, [
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'protocols' => ['https'],
                '_curl_retries' => 2, 'expect' => false,
                'proxy' => '', 'connect_timeout' => min(3, $timeout), 'timeout' => $timeout,
                'on_headers' => function (ResponseInterface $response): void {
                    if (strlen(serialize($response->getHeaders())) > 16384) {
                        throw new ConexionPushError(true);
                    }
                },
                // No recoger cuerpos externos: no son necesarios para clasificar el resultado.
                'sink' => FnStream::decorate(Utils::streamFor(''), [
                    'write' => static function (string $data) use (&$recibidos): int {
                        $recibidos += strlen($data);
                        if ($recibidos > 16384) {
                            throw new ConexionPushError(true);
                        }

                        return strlen($data);
                    },
                ]),
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip],
                    CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                    // cURL invoca este guard después de TCP/TLS, antes de enviar HTTP.
                    CURLOPT_PREREQFUNCTION => function ($handle, string $remota) use ($ips): int {
                        if (inet_pton($remota) !== inet_pton($ips[0])
                            || CarbonImmutable::now('UTC')->greaterThanOrEqualTo($this->inicio)) {
                            $this->falloIncierto = false;

                            return 1; // CURL_PREREQFUNC_ABORT: ningún POST emitido.
                        }

                        return 0;
                    },
                ],
            ]);
            $this->falloIncierto = null;

            return $response;
        } catch (Throwable) {
            throw new ConexionPushError($this->falloIncierto === true);
        }
    }

    protected function handler(): callable
    {
        // No fallback a streams, proxies de entorno ni middleware de reintento.
        return new CurlHandler;
    }
}
