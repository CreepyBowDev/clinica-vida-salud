<?php

namespace Clinica\business\validators;

use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ArchivoLaboratorio;
use Illuminate\Validation\ValidationException;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ArchivoLaboratorioValidator
{
    public function __construct(private ArchivoLaboratorio $archivos) {}

    public function validar(SplFileInfo $archivo, string $nombre): array
    {
        $path = $archivo->getPathname();
        $tamano = is_file($path) && ! is_link($path) ? filesize($path) : false;
        if ($tamano === false || $tamano < 1 || $tamano > config('laboratorio.max_bytes')) {
            $this->invalido();
        }
        $nombre = basename(str_replace('\\', '/', $nombre));
        if ($nombre === '' || mb_strlen($nombre) > 255 || preg_match('/[\p{C}]/u', $nombre)) {
            $this->invalido();
        }
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (! class_exists(\finfo::class)) {
            throw new AppError('LAB_VALIDACION_NO_DISPONIBLE', 'No se pudo comprobar el archivo. Inténtalo más tarde.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $permitidos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (! isset($permitidos[$extension]) || $mime !== $permitidos[$extension]) {
            $this->invalido();
        }
        $hash = hash_file('sha256', $path);
        if ($extension === 'pdf') {
            $this->archivos->comprobarTemporal($archivo, ['tamano_bytes' => $tamano, 'sha256' => $hash], fn ($temporal) => $this->pdf($temporal));
        } else {
            $this->imagen($path, $mime);
        }

        return ['nombre_original' => $nombre, 'tipo_mime' => $mime, 'tamano_bytes' => $tamano,
            'sha256' => $hash, 'extension' => $extension === 'jpeg' ? 'jpg' : $extension];
    }

    private function pdf(string $path): void
    {
        $binary = config('laboratorio.qpdf');
        if (! is_string($binary) || $binary === '' || ((str_contains($binary, '/') || str_contains($binary, '\\'))
            ? ! is_file($binary) : (new ExecutableFinder)->find($binary) === null)) {
            throw new AppError('LAB_VALIDACION_NO_DISPONIBLE', 'No se pudo comprobar el archivo. Inténtalo más tarde.');
        }
        try {
            $encrypted = new Process([config('laboratorio.qpdf'), '--is-encrypted', $path]);
            $encrypted->setTimeout(10)->disableOutput()->run();
            if ($encrypted->getExitCode() !== 2) {
                $this->invalido();
            }
            $check = new Process([config('laboratorio.qpdf'), '--check', $path]);
            $check->setTimeout(10)->disableOutput()->run();
            if (! $check->isSuccessful()) {
                $this->invalido();
            }
            $pages = new Process([config('laboratorio.qpdf'), '--show-npages', $path]);
            $pages->setTimeout(10)->run();
            if (! $pages->isSuccessful() || ! preg_match('/\A[1-9][0-9]*\s*\z/', $pages->getOutput())) {
                $this->invalido();
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AppError('LAB_VALIDACION_NO_DISPONIBLE', 'No se pudo comprobar el archivo. Inténtalo más tarde.');
        }
    }

    private function imagen(string $path, string $mime): void
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new AppError('LAB_VALIDACION_NO_DISPONIBLE', 'No se pudo comprobar el archivo. Inténtalo más tarde.');
        }
        $imagen = null;
        set_error_handler(static function (): never {
            throw new \RuntimeException('Imagen no válida.');
        });
        try {
            $size = getimagesize($path);
            if ($size === false || $size[0] < 1 || $size[1] < 1 || ($size['mime'] ?? null) !== $mime
                || max($size[0], $size[1]) > config('laboratorio.max_dimension_imagen')
                || config('laboratorio.max_pixeles_imagen') < $size[0] * $size[1]) {
                $this->invalido();
            }
            $imagen = imagecreatefromstring(file_get_contents($path));
            if ($imagen === false) {
                $this->invalido();
            }
        } catch (Throwable) {
            $this->invalido();
        } finally {
            $imagen = null;
            restore_error_handler();
        }
    }

    private function invalido(): never
    {
        throw ValidationException::withMessages(['archivo' => ['Selecciona un PDF, JPEG o PNG válido y legible, sin contraseña, de hasta 10 MiB.']]);
    }
}
