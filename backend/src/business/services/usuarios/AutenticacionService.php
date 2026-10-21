<?php

namespace Clinica\business\services\usuarios;

use Carbon\CarbonImmutable;
use Clinica\business\validators\CredencialTemporalValidator;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\TokenAccesoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\CredencialTemporal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class AutenticacionService
{
    private static ?string $hashFicticio = null;

    public function __construct(
        private UsuarioRepository $usuarios,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private TokenAccesoRepository $tokens,
        private CredencialTemporalValidator $temporales,
    ) {}

    private function habilitado(Usuario $usuario): bool
    {
        return $usuario->estado_cuenta === 'Activa'
            && $usuario->correo_verificado_en !== null
            && $usuario->password_hash !== null
            && match ($usuario->rol?->nombre) {
                'Administrador', 'Recepcionista' => filled($usuario->nombres_personal) && filled($usuario->apellidos_personal),
                'Médico' => $usuario->medico !== null,
                'Paciente' => $usuario->paciente !== null,
                default => false,
            };
    }

    public function iniciar(string $correo, #[\SensitiveParameter] string $password, array $contexto): Usuario
    {
        if ($this->sesiones->usuarioId() !== null) {
            $this->validarSesion();
            $this->exigirSesionNormal();
            throw new AppError('AUTH_SESION_EXISTENTE', 'Cierra la sesión actual antes de iniciar otra.');
        }
        // Solo se genera un hash ficticio por proceso; no representa una cuenta.
        self::$hashFicticio ??= Hash::make(Str::random(64));
        try {
            $usuario = DB::transaction(function () use ($correo, $password, $contexto): ?Usuario {
                $usuario = $this->usuarios->porCorreoBloqueado($correo);
                $ahora = CarbonImmutable::now('UTC');
                if ($usuario?->bloqueado_hasta !== null && $ahora->greaterThanOrEqualTo($usuario->bloqueado_hasta)) {
                    $usuario->intentos_fallidos = 0;
                    $usuario->bloqueado_hasta = null;
                    $this->usuarios->guardar($usuario);
                }
                $bloqueado = $usuario?->bloqueado_hasta?->greaterThan($ahora) ?? false;
                $hash = ! $bloqueado && $usuario?->password_hash !== null ? $usuario->password_hash : self::$hashFicticio;
                $correcta = Hash::check($password, $hash);
                $temporal = null;
                if ($usuario?->cambio_clave_obligatorio) {
                    $temporal = $this->tokens->ultimoTemporal($usuario->getKey(), true);
                    $correcta = ! $bloqueado && $this->temporales->vigente($usuario, $temporal)
                        && hash_equals($temporal->token_hash, CredencialTemporal::resumen($password));
                }
                if (! $correcta && ! $bloqueado && $usuario?->password_hash !== null) {
                    $usuario->intentos_fallidos++;
                    if ($usuario->intentos_fallidos >= 3) {
                        $usuario->bloqueado_hasta = $ahora->addMinutes(10);
                    }
                    $this->usuarios->guardar($usuario);
                }
                if (! $usuario || $bloqueado || ! $correcta || ! $this->habilitado($usuario)) {
                    $this->bitacora->autenticacion('AUTENTICACION_LOGIN', 'DENEGADO', $contexto);

                    return null;
                }
                $usuario->intentos_fallidos = 0;
                $usuario->bloqueado_hasta = null;
                $this->usuarios->guardar($usuario);
                if ($temporal !== null) {
                    $this->sesiones->revocarUsuario($usuario->getKey());
                }
                $this->sesiones->iniciar($usuario, $temporal);
                $this->bitacora->autenticacion($temporal ? 'AUTENTICACION_LOGIN_RESTRINGIDO' : 'AUTENTICACION_LOGIN', 'EXITO', $contexto, $usuario);

                return $usuario;
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if ($usuario === null) {
            // Fuera de la transacción: contador, bloqueo y evento ya confirmados.
            throw new AppError('AUTH_CREDENCIALES_INVALIDAS', 'No se pudo iniciar sesión. Verifica tus datos o inténtalo más tarde.');
        }

        return $usuario;
    }

    public function validarSesion(bool $bloquear = false): Usuario
    {
        $id = $this->sesiones->usuarioId();
        $usuario = $id !== null ? $this->usuarios->porId($id, $bloquear) : null;
        if (! $usuario || ! $this->habilitado($usuario) || ! $this->sesiones->coincide($usuario)
            || (($bloquear || $usuario->cambio_clave_obligatorio) && ! $this->sesiones->vigente($usuario))
            || ($usuario->cambio_clave_obligatorio && ! $this->temporales->vigente($usuario,
                $this->tokens->temporalPorId($this->sesiones->credencialTemporalId(), $bloquear)))) {
            $this->sesiones->cerrar();
            throw new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.');
        }
        $this->sesiones->actualizarActor($usuario);

        return $usuario;
    }

    public function exigirSesionNormal(bool $bloquear = false): Usuario
    {
        $usuario = $this->validarSesion($bloquear);
        if ($usuario->cambio_clave_obligatorio) {
            throw new AppError('AUTH_CAMBIO_CLAVE_OBLIGATORIO', 'Establece tu contraseña definitiva para continuar.');
        }

        return $usuario;
    }

    public function exigirSesionRestringida(bool $bloquear = false): Usuario
    {
        $usuario = $this->validarSesion($bloquear);
        if (! $usuario->cambio_clave_obligatorio) {
            throw new AppError('ACCESO_DENEGADO', 'Esta operación requiere una sesión de cambio obligatorio.');
        }

        return $usuario;
    }

    public function cerrar(array $contexto): void
    {
        try {
            DB::transaction(function () use ($contexto): void {
                $id = $this->sesiones->usuarioId();
                $usuario = $id !== null ? $this->usuarios->porId($id, true) : null;
                if ($usuario?->cambio_clave_obligatorio && $this->sesiones->coincide($usuario) && $this->sesiones->vigente($usuario)
                    && $this->temporales->vigente($usuario, $this->tokens->temporalPorId($this->sesiones->credencialTemporalId(), true))) {
                    // Cierre voluntario sin completar: no restaurar clave previa ni reutilizar la credencial.
                    $this->tokens->invalidarTemporales($id, CarbonImmutable::now('UTC'));
                    $this->sesiones->revocarUsuario($id);
                }
                $this->sesiones->cerrar();
                if ($usuario) {
                    $this->bitacora->autenticacion('AUTENTICACION_LOGOUT', 'EXITO', $contexto, $usuario);
                }
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
    }
}
