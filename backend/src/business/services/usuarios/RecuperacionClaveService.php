<?php

namespace Clinica\business\services\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\CambioCorreoRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\TokenAccesoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

class RecuperacionClaveService
{
    public function __construct(
        private UsuarioRepository $usuarios,
        private TokenAccesoRepository $tokens,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private CorreoSeguridadService $correo,
        private CambioCorreoRepository $cambiosCorreo,
    ) {}

    private function elegible(?Usuario $usuario): bool
    {
        return $usuario !== null && $usuario->estado_cuenta === 'Activa'
            && $usuario->correo_verificado_en !== null && $usuario->password_hash !== null
            && ! $usuario->cambio_clave_obligatorio
            && match ($usuario->rol?->nombre) {
                'Administrador', 'Recepcionista' => filled($usuario->nombres_personal) && filled($usuario->apellidos_personal) && $usuario->paciente === null,
                'Médico' => $usuario->medico !== null && $usuario->paciente === null,
                'Paciente' => $usuario->paciente !== null && $usuario->medico === null,
                default => false,
            };
    }

    private function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function solicitar(string $correo, array $contexto): void
    {
        // Mismo trabajo HTTP para todo correo validado. Cuenta/envío solo en terminación de respuesta.
        defer(function () use ($correo, $contexto): void {
            try {
                DB::transaction(function () use ($correo, $contexto): void {
                    $usuario = $this->usuarios->porCorreoBloqueado($correo);
                    if (! $this->elegible($usuario)) {
                        $this->bitacora->autenticacion('RECUPERACION_SOLICITADA', 'DENEGADO', $contexto, causa: 'RECUPERACION_NO_APLICABLE');

                        return;
                    }
                    $ahora = CarbonImmutable::now('UTC');
                    $secreto = bin2hex(random_bytes(32));
                    $this->tokens->invalidarRecuperaciones($usuario->getKey(), $ahora);
                    $this->tokens->emitirRecuperacion($usuario->getKey(), $this->hashToken($secreto), $ahora);
                    $this->bitacora->autenticacion('RECUPERACION_EMITIDA', 'EXITO', $contexto, objetivo: $usuario);
                    DB::afterCommit(function () use ($usuario, $secreto, $contexto): void {
                        $envio = 'fallido';
                        try {
                            $envio = $this->correo->enviarRecuperacion($usuario->correo_acceso, $secreto);
                        } catch (Throwable $error) {
                            Log::warning('No se confirmó el envío de recuperación.', ['tipo' => $error::class, 'correlacion' => $contexto['correlacion']]);
                        }
                        DB::transaction(fn () => $this->bitacora->autenticacion('RECUPERACION_ENVIO',
                            $envio === 'fallido' ? 'FALLO' : 'EXITO', $contexto,
                            causa: $envio === 'fallido' ? 'ENVIO_NO_CONFIRMADO' : ($envio === 'simulado' ? 'ENVIO_SIMULADO' : null), objetivo: $usuario));
                    });
                });
            } catch (Throwable $error) {
                // No exponer SQL, correo ni secreto. La respuesta 202 nunca confirma entrega/recuperación.
                Log::error('No se completó el procesamiento de recuperación.', ['tipo' => $error::class, 'correlacion' => $contexto['correlacion']]);
            }
        });
    }

    public function restablecer(#[\SensitiveParameter] string $secreto, #[\SensitiveParameter] string $password, array $contexto): void
    {
        $hash = $this->hashToken($secreto);
        $referencia = $this->tokens->recuperacionPorHash($hash);
        try {
            $restablecida = DB::transaction(function () use ($referencia, $hash, $password, $contexto): bool {
                // Mismo orden que emisión, CU-05 y cambios de cuenta: Usuario antes del token.
                $usuario = $referencia ? $this->usuarios->porId($referencia->id_usuario, true) : null;
                $token = $usuario ? $this->tokens->recuperacionPorHash($hash, true) : null;
                $ahora = CarbonImmutable::now('UTC');
                if (! $this->elegible($usuario) || ! $token || $token->consumido_en !== null || $token->invalidado_en !== null
                    || $ahora->lessThan($token->emitido_en) || $ahora->greaterThanOrEqualTo($token->expira_en)) {
                    $this->bitacora->autenticacion('RECUPERACION_COMPLETADA', 'DENEGADO', $contexto, causa: 'RECUPERACION_INVALIDA');

                    return false;
                }
                $usuario->password_hash = Hash::make($password);
                $this->usuarios->guardar($usuario);
                $this->cambiosCorreo->invalidar($usuario->getKey(), $ahora);
                $this->tokens->consumir($token, $ahora);
                $this->tokens->invalidarRecuperaciones($usuario->getKey(), $ahora);
                $this->sesiones->revocarUsuario($usuario->getKey());
                // No cerrar una sesión de otra cuenta: el token solo autoriza la cuenta recuperada.
                $actual = $this->sesiones->usuarioId();
                if ($actual === null || (string) $actual === (string) $usuario->getKey()) {
                    $this->sesiones->cerrar();
                }
                $this->bitacora->autenticacion('RECUPERACION_COMPLETADA', 'EXITO', $contexto, $usuario, objetivo: $usuario);

                return true;
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if (! $restablecida) {
            throw new AppError('RECUPERACION_INVALIDA', 'El enlace no es válido o ya no está disponible. Solicita uno nuevo.');
        }
    }
}
