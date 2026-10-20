<?php

namespace Clinica\business\services;

use Carbon\CarbonImmutable;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\CambioCorreoRepository;
use Clinica\data\repositories\MedicoRepository;
use Clinica\data\repositories\SesionRepository;
use Clinica\data\repositories\TokenAccesoRepository;
use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvitacionPersonalService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private UsuarioRepository $usuarios,
        private MedicoRepository $medicos,
        private TokenAccesoRepository $tokens,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private CorreoSeguridadService $correo,
        private CambioCorreoRepository $cambiosCorreo,
    ) {}

    private function administrador(): Usuario
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    private function pendiente(?Usuario $usuario): bool
    {
        return $usuario !== null && $usuario->estado_cuenta === 'Pendiente'
            && $usuario->password_hash === null && $usuario->correo_verificado_en === null
            && ! $usuario->cambio_clave_obligatorio && $usuario->paciente === null
            && match ($usuario->rol?->nombre) {
                'Administrador', 'Recepcionista' => filled($usuario->nombres_personal) && filled($usuario->apellidos_personal),
                'Médico' => $usuario->medico !== null,
                default => false,
            };
    }

    private function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function emitir(Usuario $usuario, Usuario $actor, array $contexto): array
    {
        $ahora = CarbonImmutable::now('UTC');
        $secreto = bin2hex(random_bytes(32));
        $this->tokens->invalidarInvitaciones($usuario->getKey(), $ahora);
        $token = $this->tokens->emitir($usuario->getKey(), $this->hashToken($secreto), $ahora);
        $this->bitacora->autenticacion('INVITACION_EMITIDA', 'EXITO', $contexto, $actor, objetivo: $usuario);
        $envio = 'pendiente';
        DB::afterCommit(function () use ($usuario, $actor, $contexto, $secreto, &$envio): void {
            try {
                $envio = $this->correo->enviarInvitacion($usuario->correo_acceso, $secreto);
            } catch (Throwable $error) {
                $envio = 'fallido';
                // No registrar el error externo: puede contener credenciales o cuerpo del correo.
                Log::warning('No se confirmó el envío de invitación.', ['tipo' => $error::class, 'correlacion' => $contexto['correlacion']]);
            }
            DB::transaction(fn () => $this->bitacora->autenticacion(
                'INVITACION_ENVIO', $envio === 'fallido' ? 'FALLO' : 'EXITO', $contexto, $actor,
                $envio === 'fallido' ? 'ENVIO_NO_CONFIRMADO' : ($envio === 'simulado' ? 'ENVIO_SIMULADO' : null), $usuario,
            ));
        });

        return ['envio' => &$envio, 'expira_en' => $token->expira_en->toIso8601String()];
    }

    public function crear(array $datos, array $contexto): array
    {
        try {
            return DB::transaction(function () use ($datos, $contexto): array {
                $actor = $this->administrador();
                $rol = $this->usuarios->rolPorNombre($datos['rol']);
                if (! $rol || ! in_array($rol->nombre, ['Administrador', 'Recepcionista', 'Médico'], true)) {
                    throw new AppError('PERSONAL_ROL_INVALIDO', 'El rol seleccionado no está disponible.');
                }
                $medico = null;
                if ($rol->nombre === 'Médico') {
                    $medico = $this->medicos->porIdBloqueado($datos['id_medico']);
                    if (! $medico || $medico->id_usuario !== null) {
                        throw new AppError('PERSONAL_VINCULO_INVALIDO', 'El registro profesional no está disponible para esta vinculación.');
                    }
                }
                $usuario = $this->usuarios->crearPersonal([
                    'id_rol' => $rol->getKey(), 'correo_acceso' => $datos['correo_acceso'],
                    'nombres_personal' => $medico ? null : $datos['nombres_personal'],
                    'apellidos_personal' => $medico ? null : $datos['apellidos_personal'],
                    'estado_cuenta' => 'Pendiente', 'password_hash' => null, 'correo_verificado_en' => null,
                    'cambio_clave_obligatorio' => false,
                ]);
                if ($medico) {
                    $this->medicos->vincular($medico, $usuario->getKey());
                    $usuario->setRelation('medico', $medico);
                }
                $this->bitacora->autenticacion('PERSONAL_CREADO', 'EXITO', $contexto, $actor, objetivo: $usuario);

                return ['usuario' => $usuario, 'invitacion' => $this->emitir($usuario, $actor, $contexto)];
            });
        } catch (UniqueConstraintViolationException) {
            throw new AppError('PERSONAL_CORREO_NO_DISPONIBLE', 'El correo de acceso no está disponible.');
        }
    }

    public function reenviar(int|string $id, array $contexto): array
    {
        return DB::transaction(function () use ($id, $contexto): array {
            $actor = $this->administrador();
            $usuario = $this->usuarios->porId($id, true);
            if (! $usuario) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la cuenta solicitada.');
            }
            if (! $this->pendiente($usuario)) {
                throw new AppError('PERSONAL_INVITACION_NO_PERMITIDA', 'La cuenta no admite una invitación inicial.');
            }
            $ahora = CarbonImmutable::now('UTC');
            $ultimo = $this->tokens->ultimo($id);
            $proximo = $ultimo?->emitido_en->addSeconds((int) config('seguridad.invitaciones.espera_reenvio_segundos'));
            if ($proximo && $ahora->lessThan($proximo)) {
                throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Espera antes de solicitar otra invitación.', max(1, (int) ceil($ahora->diffInSeconds($proximo))));
            }
            if ($this->tokens->emisionesDesde($id, $ahora->subHour()) >= (int) config('seguridad.invitaciones.emisiones_por_hora')) {
                $primero = $this->tokens->primeraEmisionDesde($id, $ahora->subHour());
                throw new AppError('AUTH_LIMITE_SOLICITUDES', 'Se alcanzó el límite de invitaciones. Inténtalo más tarde.', max(1, (int) ceil($ahora->diffInSeconds($primero->emitido_en->addHour()))));
            }

            return ['usuario' => $usuario, 'invitacion' => $this->emitir($usuario, $actor, $contexto)];
        });
    }

    public function aceptar(#[\SensitiveParameter] string $secreto, #[\SensitiveParameter] string $password, array $contexto): void
    {
        if ($this->sesiones->usuarioId() !== null) {
            throw new AppError('AUTH_SESION_EXISTENTE', 'Cierra la sesión actual antes de aceptar una invitación.');
        }
        $hash = $this->hashToken($secreto);
        $referencia = $this->tokens->porHash($hash);
        $aceptada = false;
        try {
            $aceptada = DB::transaction(function () use ($referencia, $hash, $password, $contexto): bool {
                $usuario = $referencia ? $this->usuarios->porId($referencia->id_usuario, true) : null;
                $token = $usuario ? $this->tokens->porHash($hash, true) : null;
                $ahora = CarbonImmutable::now('UTC');
                if (! $this->pendiente($usuario) || ! $token || $token->consumido_en !== null || $token->invalidado_en !== null
                    || $ahora->lessThan($token->emitido_en) || $ahora->greaterThanOrEqualTo($token->expira_en)) {
                    $this->bitacora->autenticacion('INVITACION_ACEPTADA', 'DENEGADO', $contexto, causa: 'INVITACION_INVALIDA');

                    return false;
                }
                $usuario->password_hash = Hash::make($password);
                $usuario->correo_verificado_en = $ahora;
                $usuario->estado_cuenta = 'Activa';
                $this->usuarios->guardar($usuario);
                $this->cambiosCorreo->invalidar($usuario->getKey(), CarbonImmutable::now('UTC'));
                $this->tokens->consumir($token, $ahora);
                $this->tokens->invalidarInvitaciones($usuario->getKey(), $ahora);
                $this->sesiones->revocarUsuario($usuario->getKey());
                $this->sesiones->cerrar();
                $this->bitacora->autenticacion('INVITACION_ACEPTADA', 'EXITO', $contexto, $usuario, objetivo: $usuario);

                return true;
            });
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if (! $aceptada) {
            throw new AppError('INVITACION_INVALIDA', 'La invitación no es válida o ya no está disponible. Solicita asistencia a Administración.');
        }
    }
}
