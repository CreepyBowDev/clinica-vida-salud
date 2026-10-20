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
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdministracionCuentaService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private UsuarioRepository $usuarios,
        private MedicoRepository $medicos,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private TokenAccesoRepository $tokens,
        private CambioCorreoRepository $cambiosCorreo,
    ) {}

    private function administrador(): Usuario
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return clone $actor;
    }

    private function objetivo(int|string $id, bool $bloquear = false): Usuario
    {
        $usuario = $this->usuarios->porId($id, $bloquear);
        if (! $usuario || ($usuario->rol?->nombre === 'Paciente'
            && ($usuario->correo_verificado_en === null || $usuario->paciente === null))) {
            throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la cuenta solicitada.');
        }

        return $usuario;
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->administrador();

            return $this->usuarios->listadoAdministrativo($filtros);
        });
    }

    public function consultar(int|string $id): Usuario
    {
        return DB::transaction(function () use ($id): Usuario {
            $this->administrador();

            return $this->objetivo($id);
        });
    }

    private function modificar(int|string $id, string $evento, array $contexto, Closure $cambio): Usuario
    {
        $actor = $objetivo = null;
        try {
            return DB::transaction(function () use ($id, $evento, $contexto, $cambio, &$actor, &$objetivo): Usuario {
                // Orden compartido por desactivaciones, roles y futuros restablecimientos: global → actor → objetivo.
                $this->continuidad->coordinar();
                $actor = $this->administrador();
                $objetivo = $this->objetivo($id, true);
                $antes = $this->valoresAdministrativos($objetivo);
                $cambio($objetivo);
                $this->usuarios->guardar($objetivo);
                $this->cambiosCorreo->invalidar($objetivo->getKey(), CarbonImmutable::now('UTC'));
                $this->tokens->invalidarRecuperaciones($objetivo->getKey(), CarbonImmutable::now('UTC'));
                $this->tokens->invalidarTemporales($objetivo->getKey(), CarbonImmutable::now('UTC'));
                $this->continuidad->verificar();
                $this->sesiones->revocarUsuario($objetivo->getKey());
                if ((string) $actor->getKey() === (string) $objetivo->getKey()) {
                    $this->sesiones->cerrar();
                }
                $this->bitacora->autenticacion($evento, 'EXITO', $contexto, $actor, objetivo: $objetivo,
                    cambios: ['antes' => $antes, 'despues' => $this->valoresAdministrativos($objetivo)]);

                return $objetivo;
            });
        } catch (AppError $error) {
            if ($error->codigo === 'AUTH_NO_AUTENTICADO') {
                $this->sesiones->limpiarTrasFallo();
            }
            if ($actor) {
                // La operación rechazada ya se revirtió; registrar solo referencias/causa segura.
                $objetivo = $objetivo ? $this->usuarios->porId($objetivo->getKey()) : null;
                DB::transaction(fn () => $this->bitacora->autenticacion($evento, 'DENEGADO', $contexto, $actor, $error->codigo, $objetivo));
            }
            throw $error;
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
    }

    private function valoresAdministrativos(Usuario $usuario): array
    {
        return ['rol' => $usuario->rol->nombre, 'estado_cuenta' => $usuario->estado_cuenta,
            'id_medico' => $usuario->medico ? (string) $usuario->medico->getKey() : null];
    }

    public function cambiarEstado(int|string $id, string $estado, array $contexto): Usuario
    {
        return $this->modificar($id, $estado === 'Activa' ? 'CUENTA_REACTIVADA' : 'CUENTA_DESACTIVADA', $contexto, function (Usuario $usuario) use ($estado): void {
            if (! in_array($estado, ['Activa', 'Desactivada'], true) || $usuario->estado_cuenta === $estado
                || ! in_array($usuario->estado_cuenta, ['Activa', 'Desactivada'], true)
                || $usuario->correo_verificado_en === null || $usuario->password_hash === null
                || match ($usuario->rol?->nombre) {
                    'Administrador', 'Recepcionista' => ! filled($usuario->nombres_personal) || ! filled($usuario->apellidos_personal) || $usuario->paciente !== null,
                    'Médico' => $usuario->medico === null || $usuario->paciente !== null,
                    'Paciente' => $usuario->paciente === null || $usuario->medico !== null,
                    default => true,
                }) {
                throw new AppError('CUENTA_TRANSICION_INVALIDA', 'La cuenta no admite este cambio de acceso. Debe conservar su verificación y vínculo legítimos.');
            }
            $usuario->estado_cuenta = $estado;
        });
    }

    public function cambiarRol(int|string $id, array $datos, array $contexto): Usuario
    {
        return $this->modificar($id, 'CUENTA_ROL_CAMBIADO', $contexto, function (Usuario $usuario) use ($datos): void {
            $origen = $usuario->rol?->nombre;
            $destino = $datos['rol'];
            $rol = $this->usuarios->rolPorNombre($destino);
            if (! in_array($origen, ['Administrador', 'Recepcionista', 'Médico'], true) || $usuario->paciente !== null
                || ! $rol || ! in_array($destino, ['Administrador', 'Recepcionista', 'Médico'], true) || $origen === $destino) {
                throw new AppError('CUENTA_ROL_NO_PERMITIDO', 'La cuenta no admite esta transición de rol.');
            }
            if ($destino === 'Médico') {
                $medico = $this->medicos->porIdBloqueado($datos['id_medico']);
                if (! $medico || ($usuario->medico && (string) $usuario->medico->getKey() !== (string) $medico->getKey())
                    || ($medico->id_usuario !== null && (string) $medico->id_usuario !== (string) $usuario->getKey())) {
                    throw new AppError('PERSONAL_VINCULO_INVALIDO', 'El registro profesional no está disponible para esta vinculación.');
                }
                if ($usuario->medico === null) {
                    // La similitud de nombres NO prueba identidad: se exige la comprobación expresa del Administrador.
                    $normalizar = fn (string $nombre) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($nombre)), 'UTF-8');
                    if (! ($datos['identidad_verificada'] ?? false)
                        || $normalizar((string) $usuario->nombres_personal) !== $normalizar($medico->nombres)
                        || $normalizar((string) $usuario->apellidos_personal) !== $normalizar($medico->apellidos)) {
                        throw new AppError('PERSONAL_IDENTIDAD_INCOMPATIBLE', 'Comprueba la identidad y la correspondencia del registro profesional antes de vincular.');
                    }
                    $this->medicos->vincular($medico, $usuario->getKey());
                }
                $usuario->setRelation('medico', $medico);
            } elseif ($origen === 'Médico') {
                $medico = $usuario->medico ? $this->medicos->porIdBloqueado($usuario->medico->getKey()) : null;
                if (! $medico || $medico->activo || $this->medicos->tieneAtencionesSinCierreComprobable($medico->getKey())) {
                    throw new AppError('MEDICO_OBLIGACIONES_NO_RESUELTAS', 'No se puede retirar el rol: se requiere inactividad profesional expresa y ausencia comprobable de obligaciones asistenciales o documentación pendiente.');
                }
                // Conservar identidad/vínculo histórico incluso al retirar los permisos clínicos.
                $usuario->nombres_personal = $medico->nombres;
                $usuario->apellidos_personal = $medico->apellidos;
            } elseif (! filled($usuario->nombres_personal) || ! filled($usuario->apellidos_personal)) {
                throw new AppError('PERSONAL_IDENTIDAD_INCOMPATIBLE', 'La cuenta no dispone de identidad administrativa compatible.');
            }
            $usuario->id_rol = $rol->getKey();
            $usuario->setRelation('rol', $rol);
        });
    }
}
