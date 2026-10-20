<?php

namespace Clinica\business\services;

use Carbon\CarbonImmutable;
use Clinica\business\validators\CodigoActivacionValidator;
use Clinica\data\models\RegistroPacientePendiente;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\CodigoActivacionRepository;
use Clinica\data\repositories\RegistroPacienteRepository;
use Clinica\data\repositories\SesionRepository;
use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\RegistroPacienteContexto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

class RegistroPacienteService
{
    public function __construct(
        private RegistroPacienteRepository $registros,
        private UsuarioRepository $usuarios,
        private SesionRepository $sesiones,
        private BitacoraRepository $bitacora,
        private CorreoSeguridadService $correo,
        private CodigoActivacionRepository $activaciones,
    ) {}

    private function anonimo(): void
    {
        if ($this->sesiones->usuarioId() !== null) {
            throw new AppError('AUTH_SESION_EXISTENTE', 'Cierra la sesión actual antes de iniciar un registro.');
        }
    }

    public function iniciar(#[\SensitiveParameter] array $datos, #[\SensitiveParameter] string $contexto, array $seguridad): void
    {
        $this->anonimo();
        // Trabajo criptográfico uniforme, incluso si después no corresponde reservar CI/correo.
        $hash = Hash::make($datos['password']);
        unset($datos['password'], $datos['password_confirmation']);
        $this->diferir(function () use ($datos, $hash, $contexto, $seguridad): void {
            $this->registros->coordinar();
            $ahora = CarbonImmutable::now('UTC');
            $contextoHash = RegistroPacienteContexto::hash($contexto);
            $propio = $this->registros->porContexto($contextoHash);
            if ($propio && $this->vigente($propio)) {
                // Reintentar no sustituye identidad/clave, ni roba otro proceso, ni renueva sus 24 horas.
                if ($propio->tipo_registro === 'Nuevo' && $propio->documento_identidad === $datos['documento_identidad'] && $propio->correo_propuesto === $datos['correo_acceso']) {
                    $this->emitir($propio, $seguridad);
                }

                return;
            }
            foreach ($this->registros->reservas($datos['documento_identidad'], $datos['correo_acceso']) as $reserva) {
                if ($this->vigente($reserva)) {
                    $this->bitacora->autenticacion('REGISTRO_PACIENTE_SOLICITADO', 'DENEGADO', $seguridad, causa: 'REGISTRO_NO_APLICABLE');

                    return;
                }
                $this->registros->invalidar($reserva, $ahora);
            }
            if ($this->registros->documentoRegistrado($datos['documento_identidad'])
                || $this->usuarios->porCorreoBloqueado($datos['correo_acceso'])) {
                $this->bitacora->autenticacion('REGISTRO_PACIENTE_SOLICITADO', 'DENEGADO', $seguridad, causa: 'REGISTRO_NO_APLICABLE');

                return;
            }
            $demograficos = array_diff_key($datos, ['correo_acceso' => true]);
            $registro = $this->registros->crear(['tipo_registro' => 'Nuevo', 'documento_identidad' => $datos['documento_identidad'],
                'correo_propuesto' => $datos['correo_acceso'], 'password_hash_temporal' => $hash,
                'datos_registro' => (object) [...$demograficos, '_contexto_hash' => $contextoHash],
                'iniciado_en' => $ahora, 'expira_en' => $ahora->addHours(24)]);
            $this->bitacora->autenticacion('REGISTRO_PACIENTE_INICIADO', 'EXITO', $seguridad);
            $this->emitir($registro, $seguridad);
        }, $seguridad);
    }

    private function vigente(RegistroPacientePendiente $registro): bool
    {
        $ahora = CarbonImmutable::now('UTC');

        if ($registro->completado_en !== null || $registro->invalidado_en !== null
            || $ahora->lessThan($registro->iniciado_en) || $ahora->greaterThanOrEqualTo($registro->expira_en)) {
            return false;
        }
        if ($registro->tipo_registro === 'Vinculacion') {
            $paciente = $this->activaciones->pacientePorId($registro->id_paciente);
            $codigo = $this->activaciones->porId($registro->id_codigo_activacion);

            return $paciente !== null && $paciente->id_usuario === null
                && trim($paciente->documento_identidad ?? '', ' ') === $registro->documento_identidad
                && CodigoActivacionValidator::vigente($codigo)
                && (string) $codigo->id_paciente === (string) $paciente->getKey();
        }

        return true;
    }

    public function vincular(#[\SensitiveParameter] array $datos, #[\SensitiveParameter] string $contexto, array $seguridad): void
    {
        $this->anonimo();
        $hash = Hash::make($datos['password']);
        unset($datos['password'], $datos['password_confirmation']);
        $this->diferir(function () use ($datos, $hash, $contexto, $seguridad): void {
            $this->registros->coordinar();
            $ahora = CarbonImmutable::now('UTC');
            $contextoHash = RegistroPacienteContexto::hash($contexto);
            $propio = $this->registros->porContexto($contextoHash);
            if ($propio && $this->vigente($propio)
                && ($propio->tipo_registro !== 'Vinculacion' || $propio->documento_identidad !== $datos['documento_identidad']
                    || $propio->correo_propuesto !== $datos['correo_acceso'])) {
                return;
            }
            $paciente = $this->activaciones->pacientePorDocumento($datos['documento_identidad']);
            $principal = $paciente ? $this->activaciones->ultimo($paciente->getKey()) : null;
            if (! $paciente || $paciente->id_usuario !== null || ! CodigoActivacionValidator::vigente($principal)) {
                if ($propio && ! $this->vigente($propio) && $propio->completado_en === null && $propio->invalidado_en === null) {
                    $this->registros->invalidar($propio, $ahora);
                }
                $this->bitacora->autenticacion('VINCULACION_PACIENTE_SOLICITADA', 'DENEGADO', $seguridad, causa: 'REGISTRO_NO_APLICABLE');

                return;
            }
            // Antes de comprobar el secreto, respetar la reserva exclusiva de otra cookie.
            foreach ($this->registros->porPaciente($paciente->getKey()) as $reserva) {
                if ($this->vigente($reserva)) {
                    if ($reserva->datos_registro->_contexto_hash !== $contextoHash) {
                        return;
                    }
                } else {
                    $this->registros->invalidar($reserva, $ahora);
                }
            }
            if (! hash_equals($principal->codigo_hash, RegistroPacienteContexto::recepcion($paciente->getKey(), $datos['codigo_activacion']))) {
                $principal->intentos_fallidos++;
                if ($principal->intentos_fallidos >= 5) {
                    $principal->invalidado_en = $ahora;
                    foreach ($this->registros->porPaciente($paciente->getKey()) as $reserva) {
                        $this->registros->invalidar($reserva, $ahora);
                    }
                }
                $this->activaciones->guardar($principal);
                $this->bitacora->autenticacion('VINCULACION_PACIENTE_SOLICITADA', 'DENEGADO', $seguridad, causa: 'ACTIVACION_CODIGO_INVALIDO');

                return;
            }
            if ($propio && $this->vigente($propio)) {
                $this->emitir($propio, $seguridad);

                return;
            }
            if ($propio && $propio->completado_en === null && $propio->invalidado_en === null) {
                $this->registros->invalidar($propio, $ahora);
            }
            foreach ($this->registros->reservas($datos['documento_identidad'], $datos['correo_acceso']) as $reserva) {
                if ($this->vigente($reserva)) {
                    return;
                }
                $this->registros->invalidar($reserva, $ahora);
            }
            if ($this->usuarios->porCorreoBloqueado($datos['correo_acceso'])) {
                return;
            }
            $registro = $this->registros->crear(['tipo_registro' => 'Vinculacion', 'id_paciente' => $paciente->getKey(),
                'id_codigo_activacion' => $principal->getKey(), 'documento_identidad' => $datos['documento_identidad'],
                'correo_propuesto' => $datos['correo_acceso'], 'password_hash_temporal' => $hash,
                'datos_registro' => (object) ['_contexto_hash' => $contextoHash],
                'iniciado_en' => $ahora, 'expira_en' => $principal->expira_en]);
            $this->bitacora->autenticacion('VINCULACION_PACIENTE_INICIADA', 'EXITO', $seguridad,
                cambios: ['id_paciente' => (string) $paciente->getKey()]);
            $this->emitir($registro, $seguridad);
        }, $seguridad);
    }

    private function diferir(callable $operacion, array $seguridad): void
    {
        // Igual respuesta/tiempo HTTP para CI/correo nuevo, reservado, histórico o no elegible.
        defer(function () use ($operacion, $seguridad): void {
            try {
                DB::transaction($operacion);
            } catch (Throwable $error) {
                Log::error('No se completó la solicitud de registro.', ['tipo' => $error::class, 'correlacion' => $seguridad['correlacion']]);
            }
        });
    }

    public function reenviar(#[\SensitiveParameter] string $contexto, array $seguridad): void
    {
        $this->anonimo();
        $this->diferir(function () use ($contexto, $seguridad): void {
            $this->registros->coordinar();
            $registro = $this->registros->porContexto(RegistroPacienteContexto::hash($contexto));
            if ($registro && $this->vigente($registro)) {
                $this->emitir($registro, $seguridad);
            } elseif ($registro && $registro->completado_en === null && $registro->invalidado_en === null) {
                $this->registros->invalidar($registro, CarbonImmutable::now('UTC'));
            }
        }, $seguridad);
    }

    private function emitir(RegistroPacientePendiente $registro, array $seguridad): void
    {
        $ahora = CarbonImmutable::now('UTC');
        if (! $this->vigente($registro)) {
            return;
        }
        $ultimo = $this->registros->ultimoCodigo($registro->getKey());
        if (($ultimo && $ahora->lessThan($ultimo->enviado_en->addSeconds(60)))
            || $this->registros->emisionesHora($registro->getKey(), $registro->correo_propuesto, $ahora->subHour())) {
            $this->bitacora->autenticacion('REGISTRO_CODIGO_EMITIDO', 'DENEGADO', $seguridad, causa: 'REGISTRO_ENVIO_LIMITADO');

            return;
        }
        do {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = RegistroPacienteContexto::codigo($registro->getKey(), $codigo);
        } while ($this->registros->codigoUtilizado($registro->getKey(), $hash));
        $this->registros->invalidarCodigos($registro->getKey(), $ahora);
        $this->registros->emitirCodigo($registro->getKey(), $registro->correo_propuesto,
            $hash, $ahora, $ahora->addMinutes(10)->min($registro->expira_en));
        $this->bitacora->autenticacion('REGISTRO_CODIGO_EMITIDO', 'EXITO', $seguridad);
        DB::afterCommit(function () use ($registro, $codigo, $seguridad): void {
            $envio = 'fallido';
            try {
                $envio = $this->correo->enviarActivacionPaciente($registro->correo_propuesto, $codigo);
            } catch (Throwable $error) {
                Log::warning('No se confirmó el envío de verificación.', ['tipo' => $error::class, 'correlacion' => $seguridad['correlacion']]);
            }
            DB::transaction(fn () => $this->bitacora->autenticacion('REGISTRO_CODIGO_ENVIO',
                $envio === 'fallido' ? 'FALLO' : 'EXITO', $seguridad,
                causa: $envio === 'fallido' ? 'ENVIO_NO_CONFIRMADO' : ($envio === 'simulado' ? 'ENVIO_SIMULADO' : null)));
        });
    }

    public function verificar(#[\SensitiveParameter] string $contexto, #[\SensitiveParameter] string $valor, array $seguridad): void
    {
        $this->anonimo();
        try {
            $correcto = DB::transaction(function () use ($contexto, $valor, $seguridad): bool {
                $this->registros->coordinar();
                $registro = $this->registros->porContexto(RegistroPacienteContexto::hash($contexto));
                $ahora = CarbonImmutable::now('UTC');
                if (! $registro || ! $this->vigente($registro)) {
                    if ($registro && $registro->completado_en === null && $registro->invalidado_en === null) {
                        $this->registros->invalidar($registro, $ahora);
                    }

                    return false;
                }
                $codigo = $this->registros->ultimoCodigo($registro->getKey());
                if (! $codigo || $codigo->consumido_en !== null || $codigo->invalidado_en !== null || $codigo->intentos_fallidos >= 5
                    || $ahora->lessThan($codigo->enviado_en) || $ahora->greaterThanOrEqualTo($codigo->expira_en)) {
                    return false;
                }
                if (! hash_equals($codigo->codigo_hash, RegistroPacienteContexto::codigo($registro->getKey(), $valor))) {
                    $codigo->intentos_fallidos++;
                    if ($codigo->intentos_fallidos >= 5) {
                        $codigo->invalidado_en = $ahora;
                    }
                    $this->registros->guardarCodigo($codigo);
                    $this->bitacora->autenticacion('REGISTRO_PACIENTE_VERIFICADO', 'DENEGADO', $seguridad, causa: 'REGISTRO_CODIGO_INVALIDO');

                    return false;
                }
                if (($registro->tipo_registro === 'Nuevo' && $this->registros->documentoRegistrado($registro->documento_identidad))
                    || $this->usuarios->porCorreoBloqueado($registro->correo_propuesto)) {
                    $this->registros->invalidar($registro, $ahora);

                    return false;
                }
                $rol = $this->usuarios->rolPorNombre('Paciente') ?? throw new RuntimeException('Falta el catálogo requerido.');
                $usuario = $this->usuarios->crearPaciente($rol->getKey(), $registro->correo_propuesto, $registro->password_hash_temporal, $ahora);
                // Una escritura puede esperar una restricción concurrente: ambos controles deben seguir vigentes al consumir.
                $ahora = CarbonImmutable::now('UTC');
                if (! $this->vigente($registro) || $ahora->lessThan($codigo->enviado_en) || $ahora->greaterThanOrEqualTo($codigo->expira_en)) {
                    throw new AppError('REGISTRO_VERIFICACION_INVALIDA', 'No se pudo completar la verificación. Revisa el código o acude a Recepción.');
                }
                if ($registro->tipo_registro === 'Vinculacion') {
                    $paciente = $this->activaciones->pacientePorId($registro->id_paciente);
                    $principal = $this->activaciones->porId($registro->id_codigo_activacion);
                    $this->activaciones->vincular($paciente, $usuario->getKey());
                    $principal->consumido_en = $ahora;
                    $this->activaciones->guardar($principal);
                } else {
                    $datos = (array) $registro->datos_registro;
                    unset($datos['_contexto_hash']);
                    $this->registros->crearPaciente([...$datos, 'id_usuario' => $usuario->getKey(), 'codigo_paciente' => 'PAC-'.Str::uuid(), 'activo' => true]);
                }
                $registro->forceFill(['completado_en' => $ahora, 'password_hash_temporal' => null, 'datos_registro' => null]);
                $this->registros->guardar($registro);
                $codigo->consumido_en = $ahora;
                $this->registros->guardarCodigo($codigo);
                $this->registros->invalidarCodigos($registro->getKey(), $ahora);
                $this->bitacora->autenticacion($registro->tipo_registro === 'Vinculacion' ? 'PACIENTE_CUENTA_VINCULADA' : 'REGISTRO_PACIENTE_ACTIVADO',
                    'EXITO', $seguridad, $usuario, objetivo: $usuario,
                    cambios: $registro->tipo_registro === 'Vinculacion' ? ['id_paciente' => (string) $registro->id_paciente] : null);
                $this->sesiones->cerrar();

                return true;
            });
        } catch (QueryException $error) {
            $this->sesiones->limpiarTrasFallo();
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            // La unicidad PostgreSQL sigue siendo la barrera ante altas de otros módulos.
            $correcto = false;
        } catch (Throwable $error) {
            $this->sesiones->limpiarTrasFallo();
            throw $error;
        }
        if (! $correcto) {
            throw new AppError('REGISTRO_VERIFICACION_INVALIDA', 'No se pudo completar la verificación. Revisa el código, solicita reenvío o acude a Recepción.');
        }
    }
}
