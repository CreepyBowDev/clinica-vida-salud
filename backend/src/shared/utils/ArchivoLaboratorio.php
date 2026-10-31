<?php

namespace Clinica\shared\utils;

use Clinica\shared\errors\AppError;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

class ArchivoLaboratorio
{
    public function comprobarTemporal(SplFileInfo $archivo, array $metadatos, \Closure $comprobar): void
    {
        // En Windows los uploads de prueba tmpfile pueden negar lectura a otros procesos.
        // Copia cerrada, privada y efímera: qpdf nunca recibe una ruta del cliente.
        $path = tempnam($this->directorio(), '.validacion-');
        if ($path === false) {
            throw new \RuntimeException('No se pudo preparar el archivo privado.');
        }
        try {
            if (! copy($archivo->getPathname(), $path) || filesize($path) !== $metadatos['tamano_bytes']
                || ! hash_equals($metadatos['sha256'], (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('No se pudo preparar el archivo privado.');
            }
            $comprobar($path);
        } finally {
            if (! unlink($path)) {
                throw new \RuntimeException('No se pudo retirar el temporal privado.');
            }
        }
    }

    private function directorio(): string
    {
        $root = rtrim(config('laboratorio.raiz'), '/\\');
        $this->comprobarPadres($root);
        if ((! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) || is_link($root)) {
            throw new \RuntimeException('Almacenamiento privado no disponible.');
        }
        $dir = $root.'/documentos';
        if ((! is_dir($dir) && ! mkdir($dir, 0700) && ! is_dir($dir)) || is_link($dir)) {
            throw new \RuntimeException('Almacenamiento privado no disponible.');
        }

        return $dir;
    }

    public function guardar(SplFileInfo $archivo, array $metadatos): string
    {
        $dir = $this->directorio();
        $ruta = 'documentos/'.Str::uuid().'.'.$metadatos['extension'];
        $path = $dir.'/'.basename($ruta);
        $entrada = $salida = null;
        $creado = false;
        try {
            $salida = fopen($path, 'x+b');
            $creado = is_resource($salida);
            if ($creado && ! chmod($path, 0600)) {
                throw new \RuntimeException('No se pudo proteger el archivo privado.');
            }
            $entrada = fopen($archivo->getPathname(), 'rb');
            if ($salida === false || $entrada === false || stream_copy_to_stream($entrada, $salida, $metadatos['tamano_bytes'] + 1) !== $metadatos['tamano_bytes']
                || ! fflush($salida)) {
                throw new \RuntimeException('No se pudo almacenar el archivo.');
            }
            fclose($entrada);
            fclose($salida);
            $entrada = $salida = null;
            $this->verificar($ruta, $metadatos);

            return $ruta;
        } catch (Throwable $error) {
            if (is_resource($entrada)) {
                fclose($entrada);
            }
            if (is_resource($salida)) {
                fclose($salida);
            }
            // La clave acaba de generarse y aún no se entregó a persistencia.
            if ($creado && is_file($path) && ! is_link($path)) {
                unlink($path);
            }
            throw $error;
        }
    }

    public function verificar(string $ruta, array $metadatos): void
    {
        $path = $this->path($ruta);
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || filesize($path) !== (int) $metadatos['tamano_bytes']
            || ! hash_equals(strtolower($metadatos['sha256']), (string) hash_file('sha256', $path))) {
            throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
        }
    }

    public function retirarNuevo(string $ruta): void
    {
        $path = $this->path($ruta);
        if (is_file($path) && ! is_link($path) && ! unlink($path)) {
            throw new \RuntimeException('No se pudo retirar el archivo nuevo.');
        }
    }

    /** @return array{flujo: resource, tipo_mime: string, tamano_bytes: int, extension: string} */
    public function abrir(string $ruta, array $metadatos): array
    {
        $tipos = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $mime = $metadatos['tipo_mime'] ?? '';
        $tamano = (int) ($metadatos['tamano_bytes'] ?? 0);
        $hash = strtolower($metadatos['sha256'] ?? '');
        if (! isset($tipos[$mime]) || $tamano < 1 || $tamano > config('laboratorio.max_bytes')
            || ! preg_match('/\A[a-f0-9]{64}\z/', $hash) || pathinfo($ruta, PATHINFO_EXTENSION) !== $tipos[$mime]) {
            throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
        }
        $path = $this->path($ruta);
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) {
            throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
        }
        $flujo = @fopen($path, 'rb');
        try {
            $stat = is_resource($flujo) ? fstat($flujo) : false;
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $tamano) {
                throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
            }
            // Verifica y sirve EL MISMO descriptor; no reabrir una ruta después de autorizarla.
            $contexto = hash_init('sha256');
            if (hash_update_stream($contexto, $flujo, $tamano + 1) !== $tamano
                || ! hash_equals($hash, hash_final($contexto)) || ! rewind($flujo)) {
                throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
            }

            return ['flujo' => $flujo, 'tipo_mime' => $mime, 'tamano_bytes' => $tamano, 'extension' => $tipos[$mime]];
        } catch (Throwable $error) {
            if (is_resource($flujo)) {
                fclose($flujo);
            }
            throw $error;
        }
    }

    private function path(string $ruta): string
    {
        if (! preg_match('/\Adocumentos\/[a-f0-9-]{36}\.(pdf|jpg|png)\z/', $ruta)) {
            throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
        }
        $root = rtrim(config('laboratorio.raiz'), '/\\');
        $this->comprobarPadres($root);
        if (is_link($root) || is_link($root.'/documentos')) {
            throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
        }

        return $root.'/'.$ruta;
    }

    private function comprobarPadres(string $ruta): void
    {
        for ($p = $ruta; $p !== dirname($p); $p = dirname($p)) {
            if (is_link($p)) {
                throw new AppError('LAB_ARCHIVO_NO_DISPONIBLE', 'El documento no está disponible para esta operación.');
            }
        }
    }
}
