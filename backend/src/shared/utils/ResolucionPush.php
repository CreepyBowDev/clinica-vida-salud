<?php

namespace Clinica\shared\utils;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ResolucionPush
{
    public function resolver(string $host): array
    {
        if (! in_array($host, config('push.hosts', []), true)) {
            throw new RuntimeException('El servicio push no está admitido.');
        }
        // dns_get_record no admite timeout. Aislarlo permite terminar la consulta
        // bloqueada sin que un futuro lease dependa de los tiempos DNS del SO.
        $process = $this->proceso($host);
        try {
            $process->setTimeout(3);
            $process->run();
            $salida = $process->getOutput();
            if (! $process->isSuccessful() || strlen($salida) > 8192 || ! str_starts_with($salida, '[')) {
                throw new RuntimeException;
            }
            $ips = json_decode($salida, true, 4, JSON_THROW_ON_ERROR);
            if (! is_array($ips) || ! array_is_list($ips) || count($ips) > 64) {
                throw new RuntimeException;
            }
            foreach ($ips as $ip) {
                if (! is_string($ip) || ! $this->publica($ip)) {
                    throw new RuntimeException;
                }
            }

            return $ips;
        } catch (Throwable) {
            $process->stop(0);
            // Sin stderr, comando, datos externos ni excepción encadenada.
            throw new RuntimeException('No se pudo resolver el servicio push.');
        }
    }

    protected function proceso(string $host): Process
    {
        // Solo hostname allowlisted, nunca endpoint/keys. Sin shell ni archivos.
        $script = '$r = @dns_get_record($argv[1], DNS_A | DNS_AAAA);'
            .'if ($r === false || count($r) > 64) { exit(1); }'
            .'$ips = []; foreach ($r as $v) { if (isset($v["ip"])) { $ips[] = $v["ip"]; }'
            .'elseif (isset($v["ipv6"])) { $ips[] = $v["ipv6"]; } }'
            .'echo json_encode($ips, JSON_THROW_ON_ERROR);';

        return new Process([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $script, $host]);
    }

    public function publica(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            // Solo unicast global: excluye mapped, NAT64, locales y túneles especiales.
            $bytes = inet_pton($ip);

            return $bytes !== false && (ord($bytes[0]) & 0xE0) === 0x20
                && ! (substr($bytes, 0, 2) === "\x20\x01" && (ord($bytes[2]) & 0xFE) === 0)
                && substr($bytes, 0, 2) !== "\x20\x02";
        }

        return ord(inet_pton($ip)[0]) < 224;
    }
}
