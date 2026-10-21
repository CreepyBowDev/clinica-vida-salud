<?php

namespace Clinica\business\services\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\CambioCorreoRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\TokenAccesoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class ClaveService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private UsuarioRepository $usuarios,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private TokenAccesoRepository $tokens,
        private CambioCorreoRepository $cambiosCorreo,
    ) {}

    public function cambiar(string $actual, string $nueva, array $contexto): void
    {
        try {
            $cambiada = DB::transaction(function () use ($actual, $nueva, $contexto): bool {
                // Revalidar después de adquirir el mismo bloqueo que login y revocaciones.
                $usuario = $this->autenticacion->exigirSesionNormal(bloquear: true);
                if (! Hash::check($actual, $usuario->password_hash)) {
                    $this->bitacora->autenticacion('AUTENTICACION_CAMBIO_CLAVE', 'DENEGADO', $contexto, $usuario, 'CLAVE_ACTUAL_INVALIDA');

                    return false;
                }
                $usuario->password_hash = Hash::make($nueva);
                $this->usuarios->guardar($usuario);
                $this->cambiosCorreo->invalidar($usuario->getKey(), CarbonImmutable::now('UTC'));
                $this->tokens->invalidarRecuperaciones($usuario->getKey(), CarbonImmutable::now('UTC'));
                $this->sesiones->revocarUsuario($usuario->getKey());
                // El guard regenera ID/CSRF y huella; persistir antes del COMMIT.
                $this->sesiones->iniciar($usuario);
                $this->bitacora->autenticacion('AUTENTICACION_CAMBIO_CLAVE', 'EXITO', $contexto, $usuario);

                return true;
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if (! $cambiada) {
            throw new AppError('AUTH_CLAVE_ACTUAL_INVALIDA', 'La contraseña actual no es correcta.');
        }
    }
}
