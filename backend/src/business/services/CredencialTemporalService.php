<?php

namespace Clinica\business\services;

use Carbon\CarbonImmutable;
use Clinica\business\validators\CredencialTemporalValidator;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\CambioCorreoRepository;
use Clinica\data\repositories\SesionRepository;
use Clinica\data\repositories\TokenAccesoRepository;
use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\CredencialTemporal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class CredencialTemporalService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private CredencialTemporalValidator $validator,
        private UsuarioRepository $usuarios,
        private TokenAccesoRepository $tokens,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private CambioCorreoRepository $cambiosCorreo,
    ) {}

    public function emitir(int|string $id, bool $identidadVerificada, array $contexto): array
    {
        $actor = $objetivo = null;
        try {
            return DB::transaction(function () use ($id, $identidadVerificada, $contexto, &$actor, &$objetivo): array {
                // Protocolo de continuidad compartido: global → actor → objetivo → tokens.
                $this->continuidad->coordinar();
                $actor = clone $this->autenticacion->exigirSesionNormal(bloquear: true);
                if ($actor->rol->nombre !== 'Administrador') {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $objetivo = $this->usuarios->porId($id, true);
                if (! $objetivo) {
                    throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la cuenta solicitada.');
                }
                if (! $identidadVerificada || ! $this->validator->personalHabilitado($objetivo)) {
                    throw new AppError('CREDENCIAL_TEMPORAL_NO_PERMITIDA', 'La cuenta no admite este restablecimiento del personal.');
                }
                $ahora = CarbonImmutable::now('UTC');
                $ultimo = $this->tokens->ultimoTemporal($id);
                $proximo = $ultimo?->emitido_en->addSeconds((int) config('seguridad.credencial_temporal.espera_reemision_segundos'));
                if ($proximo && $ahora->lessThan($proximo)) {
                    throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Espera antes de emitir otra credencial.', max(1, (int) ceil($ahora->diffInSeconds($proximo))));
                }
                if ($this->tokens->emisionesTemporalesDesde($id, $ahora->subHour()) >= (int) config('seguridad.credencial_temporal.emisiones_por_hora')) {
                    $primera = $this->tokens->primeraTemporalDesde($id, $ahora->subHour());
                    throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Se alcanzó el límite de emisiones.',
                        max(1, (int) ceil($ahora->diffInSeconds($primera->emitido_en->addHour()))));
                }
                $objetivo->cambio_clave_obligatorio = true;
                $this->usuarios->guardar($objetivo);
                $this->cambiosCorreo->invalidar($objetivo->getKey(), $ahora);
                $this->continuidad->verificar();
                $this->tokens->invalidarTemporales($id, $ahora);
                $this->tokens->invalidarRecuperaciones($id, $ahora);
                $secreto = CredencialTemporal::generar();
                $token = $this->tokens->emitirTemporal($id, CredencialTemporal::resumen($secreto), $ahora);
                $this->sesiones->revocarUsuario($id);
                if ((string) $actor->getKey() === (string) $id) {
                    $this->sesiones->cerrar();
                }
                $this->bitacora->autenticacion('CREDENCIAL_TEMPORAL_EMITIDA', 'EXITO', $contexto, $actor, objetivo: $objetivo);

                // Única entrega al Administrador autorizado para comunicación presencial. Nunca persistir el secreto.
                return ['id_usuario' => (string) $id, 'credencial_temporal' => $secreto, 'expira_en' => $token->expira_en->toIso8601String()];
            });
        } catch (AppError $error) {
            if ($error->codigo === 'AUTH_NO_AUTENTICADO') {
                $this->sesiones->limpiarTrasFallo();
            }
            if ($actor) {
                $objetivo = $objetivo ? $this->usuarios->porId($objetivo->getKey()) : null;
                DB::transaction(fn () => $this->bitacora->autenticacion('CREDENCIAL_TEMPORAL_EMITIDA', 'DENEGADO',
                    $contexto, $actor, $error->codigo, $objetivo));
            }
            throw $error;
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
    }

    public function completar(#[\SensitiveParameter] string $password, array $contexto): void
    {
        try {
            DB::transaction(function () use ($password, $contexto): void {
                $usuario = $this->autenticacion->exigirSesionRestringida(bloquear: true);
                $token = $this->tokens->temporalPorId($this->sesiones->credencialTemporalId(), true);
                // validarSesion ya comprobó pertenencia, propósito, vigencia y sesión persistida bajo el bloqueo de Usuario.
                $hash = Hash::make($password);
                if (! $this->validator->vigente($usuario, $token)) {
                    throw new AppError('AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.');
                }
                $ahora = CarbonImmutable::now('UTC');
                $usuario->password_hash = $hash;
                $usuario->cambio_clave_obligatorio = false;
                $this->usuarios->guardar($usuario);
                $this->cambiosCorreo->invalidar($usuario->getKey(), $ahora);
                $this->tokens->consumir($token, $ahora);
                $this->tokens->invalidarTemporales($usuario->getKey(), $ahora);
                $this->tokens->invalidarRecuperaciones($usuario->getKey(), $ahora);
                $this->sesiones->revocarUsuario($usuario->getKey());
                $this->sesiones->cerrar();
                $this->bitacora->autenticacion('CREDENCIAL_TEMPORAL_COMPLETADA', 'EXITO', $contexto, $usuario, objetivo: $usuario);
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
    }
}
