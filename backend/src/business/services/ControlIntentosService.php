<?php

namespace Clinica\business\services;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\ControlIntentosRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class ControlIntentosService
{
    public function __construct(private ControlIntentosRepository $controles, private BitacoraRepository $bitacora) {}

    public function evaluarLogin(string $correo, array $contexto): void
    {
        $this->evaluar([
            ['login:ip:'.$contexto['ip'], (int) config('seguridad.login.solicitudes_por_ip')],
            ['login:correo-ip:'.$contexto['ip']."\0".$correo, (int) config('seguridad.login.solicitudes_por_correo_ip')],
        ], (int) config('seguridad.login.ventana_segundos'), $contexto, 'AUTENTICACION_LIMITADA');
    }

    public function evaluarCambioClave(int|string $id, array $contexto): void
    {
        $this->evaluar([
            ['cambio-clave:ip:'.$contexto['ip'], (int) config('seguridad.cambio_clave.solicitudes_por_ip')],
            ['cambio-clave:usuario:'.$id, (int) config('seguridad.cambio_clave.solicitudes_por_usuario')],
        ], (int) config('seguridad.cambio_clave.ventana_segundos'), $contexto, 'CAMBIO_CLAVE_LIMITADO');
    }

    public function evaluarInvitacion(string $accion, string $ambito, array $contexto): void
    {
        $this->evaluar([
            ['invitacion:'.$accion.':ip:'.$contexto['ip'], (int) config('seguridad.invitaciones.solicitudes_por_ip')],
            ['invitacion:'.$accion.':ambito:'.$ambito, (int) config('seguridad.invitaciones.solicitudes_por_ambito')],
        ], (int) config('seguridad.invitaciones.ventana_segundos'), $contexto, 'INVITACION_LIMITADA');
    }

    private function evaluar(array $ambitos, int $ventana, array $contexto, string $evento): void
    {
        $espera = DB::transaction(function () use ($ambitos, $ventana, $contexto, $evento): ?int {
            foreach ($ambitos as [$ambito, $limite]) {
                $clave = hash_hmac('sha256', $ambito, (string) config('app.key'));
                $control = $this->controles->obtenerBloqueado($clave);
                $ahora = CarbonImmutable::now('UTC');
                $fin = $control->inicio_ventana->addSeconds($ventana);
                if ($ahora->greaterThanOrEqualTo($fin)) {
                    $control->inicio_ventana = $ahora;
                    $control->cantidad_intentos = 0;
                    $control->bloqueado_hasta = null;
                    $fin = $ahora->addSeconds($ventana);
                }
                if ($control->cantidad_intentos >= $limite) {
                    $this->bitacora->autenticacion($evento, 'DENEGADO', $contexto);

                    return max(1, (int) ceil($ahora->diffInSeconds($fin)));
                }
                $control->cantidad_intentos++;
                $this->controles->guardar($control);
            }

            return null;
        });
        if ($espera !== null) {
            throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Demasiadas solicitudes. Inténtalo más tarde.', $espera);
        }
    }

    public function evaluarRecuperacion(string $accion, string $ambito, array $contexto): void
    {
        $this->evaluar([
            ['recuperacion:'.$accion.':ip:'.$contexto['ip'], (int) config('seguridad.recuperacion.solicitudes_por_ip')],
            ['recuperacion:'.$accion.':ambito:'.$ambito, (int) config($accion === 'solicitar'
                ? 'seguridad.recuperacion.solicitudes_por_correo' : 'seguridad.recuperacion.solicitudes_por_token')],
        ], (int) config('seguridad.recuperacion.ventana_segundos'), $contexto, 'RECUPERACION_LIMITADA');
        if ($accion === 'solicitar') {
            $this->evaluar([
                ['recuperacion:solicitar:correo-hora:'.$ambito, (int) config('seguridad.recuperacion.solicitudes_por_correo_hora')],
            ], 3600, $contexto, 'RECUPERACION_LIMITADA');
        }
    }

    public function evaluarCredencialTemporal(string $accion, int|string $id, array $contexto): void
    {
        $this->evaluar([
            ['credencial-temporal:'.$accion.':ip:'.$contexto['ip'], (int) config('seguridad.credencial_temporal.solicitudes_por_ip')],
            ['credencial-temporal:'.$accion.':usuario:'.$id, (int) config('seguridad.credencial_temporal.solicitudes_por_usuario')],
        ], (int) config('seguridad.credencial_temporal.ventana_segundos'), $contexto, 'CREDENCIAL_TEMPORAL_LIMITADA');
    }

    public function evaluarRegistroPaciente(string $accion, string $ambito, array $contexto): void
    {
        $this->evaluar([
            ['registro-paciente:'.$accion.':ip:'.$contexto['ip'], (int) config('seguridad.registro_paciente.solicitudes_por_ip')],
            ['registro-paciente:'.$accion.':ambito:'.$ambito, (int) config('seguridad.registro_paciente.solicitudes_por_ambito')],
        ], 60, $contexto, 'REGISTRO_PACIENTE_LIMITADO');
    }
}
