<?php

namespace Clinica\business\services\recordatorios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\validators\AutorizacionPushValidator;
use Clinica\business\validators\SuscripcionPushValidator;
use Clinica\data\models\SuscripcionPush;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\recordatorios\SuscripcionPushRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SuscripcionPushService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private SuscripcionPushRepository $suscripciones,
        private SuscripcionPushValidator $validator,
        private BitacoraRepository $bitacora,
        private AutorizacionPushValidator $autorizacion,
    ) {}

    private function actor(): Usuario
    {
        $usuario = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($usuario->rol->nombre !== 'Paciente' || $usuario->paciente === null) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $usuario;
    }

    public function huella(Usuario $usuario): string
    {
        return $this->autorizacion->huella($usuario);
    }

    private function configuracionPublica(): array
    {
        $clave = config('push.vapid_publica');
        $habilitado = config('push.habilitado') === true && $this->validator->clavePublicaValida($clave);

        return ['habilitado' => $habilitado, 'clave_publica_vapid' => $habilitado ? $clave : null];
    }

    public function configuracion(): array
    {
        return DB::transaction(function (): array {
            $this->actor();

            return $this->configuracionPublica();
        });
    }

    private function recurso(SuscripcionPush $s, Usuario $usuario, ?string $instalacion): array
    {
        return ['id_suscripcion' => (string) $s->getKey(), 'actual' => $instalacion !== null && hash_equals($s->instalacion_hash, $instalacion),
            'activa' => $s->revocada_en === null && $s->expira_en->isAfter(CarbonImmutable::now('UTC'))
                && hash_equals($s->huella_cuenta, $this->huella($usuario)), 'version' => $s->version_autorizacion,
            'autorizada_en' => $s->autorizada_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'revocada_en' => $s->revocada_en?->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    public function listar(array $filtros, ?string $instalacion): array
    {
        return DB::transaction(function () use ($filtros, $instalacion): array {
            $usuario = $this->actor();
            $p = $this->suscripciones->listar((string) $usuario->getKey(), (string) $usuario->paciente->getKey(), $filtros['page'] ?? 1, $filtros['per_page'] ?? 20);

            return ['data' => collect($p->items())->map(fn ($s) => $this->recurso($s, $usuario, $instalacion))->all(),
                'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => max(1, $p->lastPage())]];
        });
    }

    public function autorizar(array $datos, string $instalacion, CarbonImmutable $venceInstalacion, array $contexto): array
    {
        try {
            return DB::transaction(function () use ($datos, $instalacion, $venceInstalacion, $contexto): array {
                $usuario = $this->actor();
                if (! $this->configuracionPublica()['habilitado']) {
                    throw new AppError('PUSH_NO_CONFIGURADO', 'Las notificaciones del dispositivo no están disponibles. Puedes consultar tus avisos en el portal.');
                }
                $datos = $this->validator->validar($datos);
                $ahora = CarbonImmutable::now('UTC');
                $vence = $venceInstalacion;
                if ($datos['expirationTime'] !== null) {
                    $vence = $vence->min(CarbonImmutable::createFromTimestampMsUTC($datos['expirationTime']));
                }
                if (! $vence->isAfter($ahora)) {
                    throw new AppError('PUSH_SUSCRIPCION_OBSOLETA', 'Vuelve a autorizar las notificaciones desde este dispositivo.');
                }
                $s = $this->suscripciones->porInstalacion($instalacion);
                $endpoint = hash('sha256', $datos['endpoint']);
                $otro = $this->suscripciones->porEndpoint($endpoint);
                if ($otro && (! $s || (string) $s->getKey() !== (string) $otro->getKey())) {
                    throw new AppError('PUSH_SUSCRIPCION_OBSOLETA', 'No se pudo vincular este dispositivo. Revisa su autorización y vuelve a intentarlo.');
                }
                $creada = $s === null;
                $equivalente = $s && (string) $s->id_usuario === (string) $usuario->getKey()
                    && (string) $s->id_paciente === (string) $usuario->paciente->getKey()
                    && $s->revocada_en === null && $s->expira_en->isAfter($ahora)
                    && hash_equals($s->huella_cuenta, $this->huella($usuario)) && $s->datos_cifrados === $datos;
                if (! $equivalente) {
                    if ($s && $s->version_autorizacion === 2147483647) {
                        throw new AppError('PUSH_SUSCRIPCION_OBSOLETA', 'Vuelve a autorizar las notificaciones desde otro contexto de dispositivo.');
                    }
                    if ($s) {
                        $this->suscripciones->descartarPendientes((string) $s->getKey());
                    }
                    $s ??= new SuscripcionPush;
                    $ahora = $s->exists ? $ahora->max($s->actualizada_en->addMicrosecond()) : $ahora;
                    $s->forceFill(['instalacion_hash' => $instalacion, 'endpoint_hash' => $endpoint, 'datos_cifrados' => $datos,
                        'id_usuario' => $usuario->getKey(), 'id_paciente' => $usuario->paciente->getKey(),
                        'version_autorizacion' => $s->exists ? $s->version_autorizacion + 1 : 1,
                        'huella_cuenta' => $this->huella($usuario), 'autorizada_en' => $ahora, 'actualizada_en' => $ahora,
                        'revocada_en' => null, 'expira_en' => $vence]);
                    $this->suscripciones->guardar($s);
                    $this->bitacora->suscripcionPushModificada($usuario, $contexto, (string) $s->getKey(), 'PUSH_AUTORIZADO', $s->version_autorizacion);
                }

                return ['data' => $this->recurso($s, $usuario, $instalacion), 'creada' => $creada];
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && (str_contains($error->getMessage(), 'suscripciones_push_instalacion_uq') || str_contains($error->getMessage(), 'suscripciones_push_endpoint_uq'))) {
                throw new AppError('PUSH_SUSCRIPCION_OBSOLETA', 'El dispositivo cambió durante la operación. Vuelve a consultar su autorización.');
            }
            throw $error;
        }
    }

    public function revocar(?string $instalacion, ?string $id, array $contexto): void
    {
        DB::transaction(function () use ($instalacion, $id, $contexto): void {
            $usuario = $this->actor();
            $s = $id !== null ? $this->suscripciones->propia($id, (string) $usuario->getKey())
                : ($instalacion === null ? null : $this->suscripciones->porInstalacion($instalacion));
            if ($s && ((string) $s->id_usuario !== (string) $usuario->getKey() || (string) $s->id_paciente !== (string) $usuario->paciente->getKey())) {
                $s = null;
            }
            if ($s === null && $id !== null) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            }
            if ($s) {
                $nueva = $s->revocada_en === null;
                $this->suscripciones->revocar($s);
                if ($nueva) {
                    $this->bitacora->suscripcionPushModificada($usuario, $contexto, (string) $s->getKey(), 'PUSH_REVOCADO', $s->version_autorizacion);
                }
            }
        });
    }
}
