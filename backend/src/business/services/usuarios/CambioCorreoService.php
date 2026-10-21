<?php

namespace Clinica\business\services\usuarios;

use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\data\models\VerificacionCorreo;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\usuarios\CambioCorreoRepository;
use Clinica\data\repositories\usuarios\RegistroPacienteRepository;
use Clinica\data\repositories\usuarios\SesionRepository;
use Clinica\data\repositories\usuarios\TokenAccesoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class CambioCorreoService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private UsuarioRepository $usuarios,
        private CambioCorreoRepository $cambios,
        private RegistroPacienteRepository $registros,
        private TokenAccesoRepository $tokens,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private CorreoSeguridadService $correo,
    ) {}

    private function titular(): Usuario
    {
        $usuario = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($usuario->rol->nombre !== 'Paciente' || $usuario->paciente === null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $usuario;
    }

    private function resumen(int|string $usuario, #[\SensitiveParameter] string $codigo): string
    {
        return hash_hmac('sha256', "CambioCorreo\0".$usuario."\0".$codigo, (string) config('app.key'));
    }

    private function instantanea(Usuario $usuario): string
    {
        return hash_hmac('sha256', serialize([$usuario->getRawOriginal(), (string) $usuario->paciente?->getKey()]), (string) config('app.key'));
    }

    private function disponible(Usuario $usuario, string $correo): bool
    {
        return $correo !== $usuario->correo_acceso && ! $this->usuarios->existeCorreo($correo)
            && ! $this->registros->correoReservado($correo, CarbonImmutable::now('UTC'));
    }

    private function limitar(Usuario $usuario, string $correo, ?VerificacionCorreo $ultimo): void
    {
        $ahora = CarbonImmutable::now('UTC');
        foreach ([$ultimo, $this->cambios->ultimoPorCorreo($correo)] as $emision) {
            if ($emision && $ahora->lessThan($emision->enviado_en->addSeconds(60))) {
                throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Espera antes de solicitar otro código.',
                    max(1, (int) ceil($ahora->diffInSeconds($emision->enviado_en->addSeconds(60)))));
            }
        }
        $emisiones = collect($this->cambios->emisionesHora($usuario->getKey(), $correo, $ahora->subHour()));
        foreach ([$emisiones->where('id_usuario', $usuario->getKey()), $emisiones->filter(fn ($c) => $c->correo_destino === $correo)] as $grupo) {
            if ($grupo->count() >= 5) {
                throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Se alcanzó el límite de envíos. Inténtalo más tarde.',
                    max(1, (int) ceil($ahora->diffInSeconds($grupo->first()->enviado_en->addHour()))));
            }
        }
    }

    private function enTransporte(VerificacionCorreo $codigo): bool
    {
        return $codigo->invalidado_en !== null && $codigo->invalidado_en->equalTo($codigo->enviado_en);
    }

    private function emitir(Usuario $usuario, string $destino, array $contexto): array
    {
        $this->limitar($usuario, $destino, $this->cambios->ultimo($usuario->getKey()));
        $ahora = CarbonImmutable::now('UTC');
        $this->cambios->invalidar($usuario->getKey(), $ahora);
        do {
            $secreto = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = $this->resumen($usuario->getKey(), $secreto);
        } while ($this->cambios->codigoUtilizado($usuario->getKey(), $hash));
        $codigo = $this->cambios->crear($usuario->getKey(), $destino, $hash, $ahora);
        $instantanea = $this->instantanea($usuario);
        $this->bitacora->autenticacion('CAMBIO_CORREO_SOLICITADO', 'EXITO', $contexto, $usuario);
        $envio = 'pendiente';
        DB::afterCommit(function () use ($usuario, $codigo, $instantanea, $secreto, $contexto, &$envio): void {
            try {
                // Nunca mantener locks durante transporte externo. Hasta confirmar sigue no consumible.
                $resultado = $this->correo->enviarCambioCorreo($codigo->correo_destino, $secreto);
                $envio = DB::transaction(function () use ($usuario, $codigo, $instantanea, $resultado, $contexto): string {
                    $this->registros->coordinar();
                    $actual = $this->usuarios->porId($usuario->getKey(), true);
                    $ultimo = $this->cambios->ultimo($usuario->getKey());
                    $vigente = $actual && hash_equals($instantanea, $this->instantanea($actual))
                        && $actual->estado_cuenta === 'Activa' && $actual->rol->nombre === 'Paciente'
                        && $ultimo && (string) $ultimo->getKey() === (string) $codigo->getKey()
                        && $ultimo->consumido_en === null && $this->enTransporte($ultimo)
                        && CarbonImmutable::now('UTC')->lessThan($ultimo->expira_en)
                        && in_array($resultado, ['aceptado', 'simulado'], true);
                    if ($vigente) {
                        $ultimo->invalidado_en = null;
                        $this->cambios->guardar($ultimo);
                    }
                    $this->bitacora->autenticacion('CAMBIO_CORREO_ENVIO', $vigente ? 'EXITO' : 'FALLO', $contexto,
                        objetivo: $actual, causa: $vigente ? ($resultado === 'simulado' ? 'ENVIO_SIMULADO' : null) : 'ENVIO_NO_VIGENTE');

                    return $vigente ? $resultado : 'fallido';
                });
            } catch (Throwable $error) {
                $envio = 'fallido';
                Log::warning('No se confirmó el envío de cambio de correo.', ['tipo' => $error::class, 'correlacion' => $contexto['correlacion']]);
                // El código permanece invalidado si transporte o confirmación SQL fallan.
                try {
                    DB::transaction(fn () => $this->bitacora->autenticacion('CAMBIO_CORREO_ENVIO', 'FALLO', $contexto,
                        objetivo: $usuario, causa: 'ENVIO_NO_CONFIRMADO'));
                } catch (Throwable $auditoria) {
                    Log::warning('No se registró el resultado del envío de cambio de correo.', ['tipo' => $auditoria::class, 'correlacion' => $contexto['correlacion']]);
                }
            }
        });

        return ['envio' => &$envio, 'expira_en' => $codigo->expira_en->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    public function solicitar(string $destino, #[\SensitiveParameter] string $password, array $contexto): array
    {
        $resultado = DB::transaction(function () use ($destino, $password, $contexto): ?array {
            $this->registros->coordinar();
            $usuario = $this->titular();
            if (! Hash::check($password, $usuario->password_hash)) {
                $this->bitacora->autenticacion('CAMBIO_CORREO_SOLICITADO', 'DENEGADO', $contexto, $usuario, causa: 'CLAVE_ACTUAL_INVALIDA');

                return null;
            }
            if (! $this->disponible($usuario, $destino)) {
                throw new AppError('CAMBIO_CORREO_NO_DISPONIBLE', 'No se puede utilizar ese correo para este cambio. Revisa los datos o utiliza otro.');
            }

            return $this->emitir($usuario, $destino, $contexto);
        });
        if ($resultado === null) {
            throw new AppError('AUTH_CLAVE_ACTUAL_INVALIDA', 'La contraseña actual no es correcta.');
        }

        return $resultado;
    }

    public function reenviar(array $contexto): array
    {
        return DB::transaction(function () use ($contexto): array {
            $this->registros->coordinar();
            $usuario = $this->titular();
            $ultimo = $this->cambios->ultimo($usuario->getKey());
            if (! $ultimo || $ultimo->consumido_en !== null || ($ultimo->invalidado_en !== null && ! $this->enTransporte($ultimo))) {
                throw new AppError('CAMBIO_CORREO_INVALIDO', 'Solicita un nuevo cambio de correo para continuar.');
            }
            if (! $this->disponible($usuario, $ultimo->correo_destino)) {
                throw new AppError('CAMBIO_CORREO_NO_DISPONIBLE', 'No se puede utilizar ese correo para este cambio. Revisa los datos o utiliza otro.');
            }

            return $this->emitir($usuario, $ultimo->correo_destino, $contexto);
        });
    }

    public function verificar(#[\SensitiveParameter] string $valor, array $contexto): void
    {
        try {
            $correcto = DB::transaction(function () use ($valor, $contexto): bool {
                $this->registros->coordinar();
                $usuario = $this->titular();
                $codigo = $this->cambios->ultimo($usuario->getKey());
                $ahora = CarbonImmutable::now('UTC');
                if (! $codigo || $codigo->invalidado_en !== null || $codigo->consumido_en !== null
                    || $codigo->intentos_fallidos >= 5 || $ahora->lessThan($codigo->enviado_en) || $ahora->greaterThanOrEqualTo($codigo->expira_en)) {
                    return false;
                }
                if (! hash_equals($codigo->codigo_hash, $this->resumen($usuario->getKey(), $valor))) {
                    $codigo->intentos_fallidos++;
                    $this->cambios->guardar($codigo);
                    if ($codigo->intentos_fallidos >= 5) {
                        $this->cambios->invalidar($usuario->getKey(), $ahora);
                    }
                    $this->bitacora->autenticacion('CAMBIO_CORREO_VERIFICADO', 'DENEGADO', $contexto, $usuario, causa: 'CODIGO_INVALIDO');

                    return false;
                }
                if (! $this->disponible($usuario, $codigo->correo_destino)) {
                    throw new AppError('CAMBIO_CORREO_NO_DISPONIBLE', 'No se puede utilizar ese correo para este cambio. Revisa los datos o utiliza otro.');
                }
                $usuario->correo_acceso = $codigo->correo_destino;
                $usuario->correo_verificado_en = $ahora;
                $this->usuarios->guardar($usuario);
                $codigo->consumido_en = $ahora;
                $this->cambios->guardar($codigo);
                $this->cambios->invalidar($usuario->getKey(), $ahora);
                $this->tokens->invalidarRecuperaciones($usuario->getKey(), $ahora);
                $this->sesiones->revocarUsuario($usuario->getKey());
                $this->sesiones->cerrar();
                $this->bitacora->autenticacion('CAMBIO_CORREO_VERIFICADO', 'EXITO', $contexto, $usuario);

                return true;
            });
        } catch (AppError $error) {
            if ($error->codigo === 'AUTH_NO_AUTENTICADO') {
                $this->sesiones->limpiarTrasFallo();
            }
            throw $error;
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                $this->sesiones->limpiarTrasFallo();
                throw $error;
            }
            throw new AppError('CAMBIO_CORREO_NO_DISPONIBLE', 'No se pudo completar el cambio de correo. Revisa los datos o utiliza otro.');
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if (! $correcto) {
            throw new AppError('CAMBIO_CORREO_INVALIDO', 'El código no es válido o ya no está disponible. Solicita un nuevo código o cambio.');
        }
    }
}
