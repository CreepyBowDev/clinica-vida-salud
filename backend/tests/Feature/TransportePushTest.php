<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\validators\SuscripcionPushValidator;
use Clinica\shared\errors\AppError;
use Clinica\shared\errors\ConexionPushError;
use Clinica\shared\utils\ClientePushSeguro;
use Clinica\shared\utils\ResolucionPush;
use Clinica\shared\utils\TransportePush;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransportePushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-31 12:00:00.000000 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function dns(array $ips): ResolucionPush
    {
        $dns = $this->createStub(ResolucionPush::class);
        $dns->method('resolver')->willReturn($ips);
        $dns->method('publica')->willReturnCallback((new ResolucionPush)->publica(...));

        return $dns;
    }

    private function cliente(ResolucionPush $dns, callable $handler, string $inicio = '2026-10-31 12:01:00 UTC'): ClientePushSeguro
    {
        return new class($dns, CarbonImmutable::parse($inicio), $handler) extends ClientePushSeguro
        {
            public function __construct($dns, $inicio, private $simulado)
            {
                parent::__construct($dns, $inicio);
            }

            protected function handler(): callable
            {
                return $this->simulado;
            }
        };
    }

    private function request(string $url = 'https://fcm.googleapis.com/push/secreto'): Request
    {
        return new Request('POST', $url, ['TTL' => '86400', 'Authorization' => 'secreto']);
    }

    public static function direcciones(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '0.0.0.0', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254',
            '100.64.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:8.8.8.8', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1',
            '2001:db8::1', '2001::1', '2002:7f00:1::', '64:ff9b::808:808', 'no-es-ip',
        ]);
    }

    #[DataProvider('direcciones')]
    public function test_rechaza_ip_no_publica_y_respuestas_dns_mixtas(string $ip): void
    {
        $this->assertFalse((new ResolucionPush)->publica($ip));
        $handler = new MockHandler([new Response(201)]);
        $client = $this->cliente($this->dns(['8.8.8.8', $ip]), $handler);
        try {
            $client->sendRequest($this->request());
            $this->fail('No debe conectar.');
        } catch (ConexionPushError $error) {
            $this->assertFalse($error->incierto);
            $this->assertNull($error->getPrevious());
            $this->assertSame(1, count($handler));
        }
    }

    public static function destinos(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://fcm.googleapis.com/push', 'https://127.0.0.1/push',
            'https://fcm.googleapis.com.evil.test/push', 'https://fcm.googleapis.com:444/push',
            'https://user:secret@fcm.googleapis.com/push', 'https://fcm.googleapis.com/push#secret',
            'https://fcm.googleapis.com./push', 'https://fcm.googleapis.com/',
        ]);
    }

    #[DataProvider('destinos')]
    public function test_destino_invalido_no_resuelve_dns(string $url): void
    {
        $dns = $this->createMock(ResolucionPush::class);
        $dns->expects($this->never())->method('resolver');
        $client = $this->cliente($dns, new MockHandler([]));
        $this->expectException(ConexionPushError::class);
        $client->sendRequest($this->request($url));
    }

    public function test_validador_rechaza_controles_y_backslash_antes_de_normalizar_uri(): void
    {
        $validator = new SuscripcionPushValidator;
        $this->assertFalse($validator->endpointValido("https://fcm.googleapis.com/push\x01"));
        $this->assertFalse($validator->endpointValido('https://fcm.googleapis.com\\evil.test/push'));
    }

    public static function publicas(): array
    {
        return [['8.8.8.8', '8.8.8.8'], ['2607:f8b0:4005:805::200a', '[2607:f8b0:4005:805::200a]']];
    }

    #[DataProvider('publicas')]
    public function test_pinning_tls_sin_proxy_redirects_reintentos_y_ttl(string $ip, string $pin): void
    {
        $captura = [];
        $handler = function ($request, $options) use (&$captura) {
            $captura = [$request, $options];

            return Create::promiseFor(new Response(201));
        };
        $client = $this->cliente($this->dns([$ip]), $handler, '2026-10-31 12:00:01.999999 UTC');
        $this->assertSame(201, $client->sendRequest($this->request())->getStatusCode());
        [$request, $options] = $captura;
        $this->assertSame('0', $request->getHeaderLine('TTL'));
        $this->assertSame('fcm.googleapis.com', $request->getUri()->getHost());
        $this->assertSame(['fcm.googleapis.com:443:'.$pin], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertTrue($options['verify']);
        $this->assertSame('', $options['proxy']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);
        $this->assertSame(2, $options['_curl_retries']);
        $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
        $this->assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);
        $this->assertLessThanOrEqual(1.999999, $options['timeout']);
        $this->assertSame(10000, $options['sink']->write(str_repeat('x', 10000)));
        try {
            $options['sink']->write(str_repeat('x', 7000));
            $this->fail('Debe limitar el cuerpo acumulado.');
        } catch (ConexionPushError $error) {
            $this->assertTrue($error->incierto);
        }
        $this->assertNull($client->falloIncierto);
        // Construye el handle real sin ejecutarlo: detecta opciones cURL incompatibles.
        $factory = new CurlFactory(1);
        $handle = $factory->create($request, $options);
        $factory->release($handle);
        $guard = $options['curl'][CURLOPT_PREREQFUNCTION];
        $this->assertSame(0, $guard(null, $ip));
        $this->assertSame(1, $guard(null, '127.0.0.1'));
        CarbonImmutable::setTestNow('2026-10-31 12:00:01.999999 UTC');
        $this->assertSame(1, $guard(null, $ip));
        $this->assertFalse($client->falloIncierto);
    }

    public function test_dns_se_revalida_en_cada_envio_sin_reusar_conexion(): void
    {
        $dns = $this->createMock(ResolucionPush::class);
        $dns->expects($this->exactly(2))->method('resolver')->willReturnOnConsecutiveCalls(['8.8.8.8'], ['127.0.0.1']);
        $dns->method('publica')->willReturnCallback((new ResolucionPush)->publica(...));
        $handler = new MockHandler([new Response(201), new Response(201)]);
        $client = $this->cliente($dns, $handler);
        $this->assertSame(201, $client->sendRequest($this->request())->getStatusCode());
        try {
            $client->sendRequest($this->request());
            $this->fail('No debe aceptar rebinding.');
        } catch (ConexionPushError $error) {
            $this->assertFalse($error->incierto);
            $this->assertSame(1, count($handler));
        }
    }

    public static function cortes(): array
    {
        return [['2026-10-31 12:00:00 UTC'], ['2026-10-31 11:59:59.999999 UTC']];
    }

    #[DataProvider('cortes')]
    public function test_no_envia_desde_inicio(string $inicio): void
    {
        $handler = new MockHandler([new Response(201)]);
        $client = $this->cliente($this->dns(['8.8.8.8']), $handler, $inicio);
        try {
            $client->sendRequest($this->request());
            $this->fail('Debe cortar antes del HTTP.');
        } catch (ConexionPushError $error) {
            $this->assertFalse($error->incierto);
            $this->assertSame(1, count($handler));
        }
        $this->assertSame('NoEnviado', (new TransportePush($this->dns([])))->enviar([], CarbonImmutable::parse($inicio))['tipo']);
    }

    public function test_reloj_se_relee_despues_de_dns(): void
    {
        $dns = $this->createStub(ResolucionPush::class);
        $dns->method('resolver')->willReturnCallback(function () {
            CarbonImmutable::setTestNow('2026-10-31 12:01:00 UTC');

            return ['8.8.8.8'];
        });
        $dns->method('publica')->willReturn(true);
        $client = $this->cliente($dns, new MockHandler([]));
        $this->expectException(ConexionPushError::class);
        $client->sendRequest($this->request());
    }

    public function test_timeout_no_reintenta_y_no_expone_secretos(): void
    {
        $request = $this->request();
        $handler = new MockHandler([new ConnectException('secreto', $request), new Response(201)]);
        $client = $this->cliente($this->dns(['8.8.8.8']), $handler);
        try {
            $client->sendRequest($request);
            $this->fail('Debe fallar.');
        } catch (ConexionPushError $error) {
            $this->assertTrue($error->incierto);
            $this->assertStringNotContainsString('secreto', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertSame(1, count($handler));
        }
    }

    public static function peticionesInvalidas(): array
    {
        return [['GET', 'fcm.googleapis.com', '60'], ['POST', 'otro.example.test', '60'],
            ['POST', 'fcm.googleapis.com', '-1'], ['POST', 'fcm.googleapis.com', '1.5']];
    }

    #[DataProvider('peticionesInvalidas')]
    public function test_no_conecta_con_metodo_host_o_ttl_invalido(string $metodo, string $host, string $ttl): void
    {
        $client = $this->cliente($this->dns(['8.8.8.8']), new MockHandler([]));
        $this->expectException(ConexionPushError::class);
        $client->sendRequest($this->request()->withMethod($metodo)->withHeader('Host', $host)->withHeader('TTL', $ttl));
    }

    public function test_resolucion_vacia_y_fallida_son_previas_al_envio(): void
    {
        foreach ([false, true] as $fallar) {
            $dns = $this->createStub(ResolucionPush::class);
            if ($fallar) {
                $dns->method('resolver')->willThrowException(new \RuntimeException('secreto'));
            } else {
                $dns->method('resolver')->willReturn([]);
            }
            try {
                $this->cliente($dns, new MockHandler([]))->sendRequest($this->request());
                $this->fail('No debe enviar.');
            } catch (ConexionPushError $error) {
                $this->assertFalse($error->incierto);
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_respuesta_con_cabeceras_excesivas_se_aborta_sin_eco(): void
    {
        $handler = function ($request, $options) {
            $response = new Response(201, ['X-Secreto' => str_repeat('x', 17000)]);
            $options['on_headers']($response);

            return Create::promiseFor($response);
        };
        $client = $this->cliente($this->dns(['8.8.8.8']), $handler);
        try {
            $client->sendRequest($this->request());
            $this->fail('Debe limitar las cabeceras.');
        } catch (ConexionPushError $error) {
            $this->assertTrue($error->incierto);
            $this->assertStringNotContainsString('X-Secreto', $error->getMessage());
        }
    }

    public function test_no_deshabilita_timeout_en_ventana_menor_a_un_milisegundo(): void
    {
        $client = $this->cliente($this->dns(['8.8.8.8']), new MockHandler([]), '2026-10-31 12:00:00.000999 UTC');
        $this->expectException(ConexionPushError::class);
        $client->sendRequest($this->request());
    }

    public function test_biblioteca_usa_cliente_seguro_sin_claves_ni_payload_criptografico(): void
    {
        // Integración PSR-18 real con minishlink, no acredita cifrado/VAPID.
        $handler = new MockHandler([new Response(307, ['Location' => 'http://127.0.0.1/']), new Response(201)]);
        $client = $this->cliente($this->dns(['8.8.8.8']), $handler);
        $factory = new HttpFactory;
        $push = new WebPush([], ['TTL' => 60], $client, $factory, $factory);
        $report = $push->sendOneNotification(new Subscription('https://fcm.googleapis.com/push/secreto'));
        $this->assertSame(307, $report->getResponse()->getStatusCode());
        $this->assertSame(1, count($handler));
        $this->assertSame('Incierto', (new TransportePush($this->dns([])))->clasificar($report->getResponse())['tipo']);
    }

    public static function estados(): array
    {
        return [[201, 'Aceptado'], [200, 'Incierto'], [202, 'Incierto'], [301, 'Incierto'],
            [400, 'Fallido'], [413, 'Fallido'], [404, 'Obsoleta'], [410, 'Obsoleta'],
            [401, 'Configuracion'], [403, 'Configuracion'], [429, 'Reintentable'], [503, 'Reintentable'], [500, 'Incierto']];
    }

    #[DataProvider('estados')]
    public function test_clasifica_sin_exponer_el_cuerpo(int $status, string $tipo): void
    {
        $result = (new TransportePush($this->dns([])))->clasificar(new Response($status, [], 'secreto'));
        $this->assertSame(['tipo' => $tipo, 'http' => $status, 'retry_after' => null], $result);
    }

    public static function esperas(): array
    {
        return [['60', 60], ['0', 0], ['9999999999', 9999999999], ['-1', null], ['1.5', null],
            ['Sat, 31 Oct 2026 12:05:00 GMT', 300], ['Sat, 31 Oct 2026 11:00:00 GMT', 0],
            ['tomorrow', null], [str_repeat('x', 100), null]];
    }

    #[DataProvider('esperas')]
    public function test_retry_after_solo_se_interpreta_sin_reintento(string $valor, ?int $espera): void
    {
        $transporte = new TransportePush($this->dns([]));
        $this->assertSame($espera, $transporte->clasificar(new Response(429, ['Retry-After' => $valor]))['retry_after']);
        $this->assertNull($transporte->clasificar(new Response(201, ['Retry-After' => $valor]))['retry_after']);
    }

    public function test_configuracion_ausente_no_resuelve_ni_envia(): void
    {
        config(['push.habilitado' => true, 'push.vapid_privada' => null]);
        $dns = $this->createMock(ResolucionPush::class);
        $dns->expects($this->never())->method('resolver');
        try {
            (new TransportePush($dns))->enviar([], CarbonImmutable::now()->addMinute());
            $this->fail('Debe rechazar configuración incompleta.');
        } catch (AppError $error) {
            $this->assertSame('PUSH_NO_CONFIGURADO', $error->codigo);
        }
    }

    public function test_cifrado_y_vapid_reales_con_claves_efimeras_y_http_simulado(): void
    {
        // Generación exclusivamente en memoria autorizada por el usuario; nunca .env/archivos/red.
        $vapid = VAPID::createVapidKeys();
        $browser = VAPID::createVapidKeys();
        $auth = random_bytes(16);
        config(['push.habilitado' => true, 'push.vapid_publica' => $vapid['publicKey'],
            'push.vapid_privada' => $vapid['privateKey'], 'push.vapid_sujeto' => 'mailto:seguridad@example.test']);
        $request = null;
        $handler = function ($value, $options) use (&$request) {
            $request = $value;

            return Create::promiseFor(new Response(201));
        };
        $dns = $this->dns(['8.8.8.8']);
        $client = $this->cliente($dns, $handler);
        $transporte = new class($dns, $client) extends TransportePush
        {
            public function __construct($dns, private ClientePushSeguro $simulado)
            {
                parent::__construct($dns);
            }

            protected function cliente(\DateTimeInterface $inicio): ClientePushSeguro
            {
                return $this->simulado;
            }
        };
        $result = $transporte->enviar(['endpoint' => 'https://fcm.googleapis.com/push/secreto',
            'expirationTime' => null, 'keys' => ['p256dh' => $browser['publicKey'],
                'auth' => rtrim(strtr(base64_encode($auth), '+/', '-_'), '=')]],
            CarbonImmutable::now()->addMinute());
        $this->assertSame(['tipo' => 'Aceptado', 'http' => 201, 'retry_after' => null], $result);
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('normal', $request->getHeaderLine('Urgency'));
        $this->assertSame('50', $request->getHeaderLine('TTL'));
        $this->assertStringNotContainsString('Tienes un aviso', (string) $request->getBody());
        $this->assertGreaterThan(100, $request->getBody()->getSize());
        $authorization = $request->getHeaderLine('Authorization');
        $this->assertMatchesRegularExpression('/\Avapid t=([^,]+), k=([A-Za-z0-9_-]+)\z/', $authorization);
        preg_match('/\Avapid t=([^,]+), k=([A-Za-z0-9_-]+)\z/', $authorization, $parts);
        $this->assertSame($vapid['publicKey'], $parts[2]);
        $jws = (new CompactSerializer)->unserialize($parts[1]);
        $point = base64_decode(strtr($vapid['publicKey'], '-_', '+/'));
        $encode = fn ($bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $key = new JWK(['kty' => 'EC', 'crv' => 'P-256',
            'x' => $encode(substr($point, 1, 32)), 'y' => $encode(substr($point, 33, 32))]);
        $verifier = new JWSVerifier(new AlgorithmManager([
            new ES256,
        ]));
        $this->assertTrue($verifier->verifyWithKey($jws, $key, 0));
        $claims = json_decode($jws->getPayload(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame('mailto:seguridad@example.test', $claims['sub']);
        $this->assertSame(['aud', 'exp', 'sub'], array_keys($claims));
        // Receptor RFC8291 independiente: recupera solo el mensaje genérico.
        $body = (string) $request->getBody();
        $salt = substr($body, 0, 16);
        $this->assertSame(65, ord($body[20]));
        $serverPoint = substr($body, 21, 65);
        $serverPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(
            hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPoint,
        ), 64, "\n")."-----END PUBLIC KEY-----\n";
        $privateDer = hex2bin('30310201010420').base64_decode(strtr($browser['privateKey'], '-_', '+/'))
            .hex2bin('a00a06082a8648ce3d030107');
        $browserKey = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".
            chunk_split(base64_encode($privateDer), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        $shared = openssl_pkey_derive(openssl_pkey_get_public($serverPem), $browserKey);
        $browserPoint = base64_decode(strtr($browser['publicKey'], '-_', '+/'));
        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$browserPoint.$serverPoint."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $ciphertext = substr($body, 86);
        $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA,
            $nonce, substr($ciphertext, -16));
        $this->assertNotFalse($plain);
        $plain = rtrim($plain, "\0");
        $this->assertSame("\x02", substr($plain, -1));
        $this->assertSame(['titulo' => 'Clínica Vida Salud',
            'mensaje' => 'Tienes un aviso disponible. Consulta el sistema.'],
            json_decode(substr($plain, 0, -1), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_par_vapid_incoherente_se_rechaza_sin_dns(): void
    {
        $a = VAPID::createVapidKeys();
        $b = VAPID::createVapidKeys();
        config(['push.habilitado' => true, 'push.vapid_publica' => $a['publicKey'],
            'push.vapid_privada' => $b['privateKey'], 'push.vapid_sujeto' => 'mailto:seguridad@example.test']);
        $dns = $this->createMock(ResolucionPush::class);
        $dns->expects($this->never())->method('resolver');
        $this->expectException(AppError::class);
        (new TransportePush($dns))->enviar([], CarbonImmutable::now()->addMinute());
    }

    public static function resoluciones(): array
    {
        return [['["8.8.8.8"]', true, false, ['8.8.8.8']],
            ['["127.0.0.1"]', true, false, null], ['{}', true, false, null],
            ['{"a":"8.8.8.8"}', true, false, null], ['[1]', true, false, null],
            ['invalid', true, false, null], [str_repeat('x', 8193), true, false, null],
            ['[]', false, false, null], ['', false, true, null]];
    }

    #[DataProvider('resoluciones')]
    public function test_resolver_acotado_sin_dns_real(string $salida, bool $exito, bool $timeout, ?array $esperada): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('setTimeout')->with(3)->willReturnSelf();
        if ($timeout) {
            $process->expects($this->once())->method('run')->willThrowException(new \RuntimeException('secreto'));
        } else {
            $process->expects($this->once())->method('run')->willReturn($exito ? 0 : 1);
            $process->method('getOutput')->willReturn($salida);
            $process->method('isSuccessful')->willReturn($exito);
        }
        $process->expects($esperada === null ? $this->once() : $this->never())->method('stop')->with(0)->willReturn(0);
        $dns = new class($process) extends ResolucionPush
        {
            public function __construct(private Process $simulado) {}

            protected function proceso(string $host): Process
            {
                return $this->simulado;
            }
        };
        if ($esperada !== null) {
            $this->assertSame($esperada, $dns->resolver('fcm.googleapis.com'));
        } else {
            try {
                $dns->resolver('fcm.googleapis.com');
                $this->fail('Debe rechazar la resolución.');
            } catch (\RuntimeException $error) {
                $this->assertNull($error->getPrevious());
                $this->assertStringNotContainsString('secreto', $error->getMessage());
            }
        }
    }

    public function test_subproceso_php_real_con_salida_ficticia_sin_red(): void
    {
        $dns = new class extends ResolucionPush
        {
            protected function proceso(string $host): Process
            {
                return new Process([PHP_BINARY, '-r', 'echo "[\"8.8.8.8\"]";']);
            }
        };
        $this->assertSame(['8.8.8.8'], $dns->resolver('fcm.googleapis.com'));
    }
}
