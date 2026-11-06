<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Cita;
use Clinica\data\models\ObligacionPago;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\citas\CitaRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\pagos\ObligacionPagoRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\ImportePago;
use Clinica\shared\utils\PropuestaMonto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PrecioCitaService
{
    public function __construct(private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad, private PlanificacionRepository $planificacion,
        private CitaRepository $citas, private ObligacionPagoRepository $obligaciones,
        private BitacoraRepository $bitacora, private PropuestaMonto $propuestas) {}

    public function exigirHabilitacion(): void
    {
        if (config('pagos.incorporacion_habilitada') !== true || ! $this->obligaciones->disponible()) {
            throw new AppError('PAGO_NO_CONFIGURADO', 'La emisión financiera no está habilitada.');
        }
    }

    private function actor(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, ['Administrador', 'Recepcionista'], true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $this->planificacion->coordinar();

        return $actor;
    }

    private function disponible(Cita $cita): void
    {
        if (! $cita->incorporada_finanzas) {
            throw new AppError('PAGO_CITA_NO_INCORPORADA', 'La cita no está incorporada al módulo financiero.');
        }
        if ($cita->estado === 'Cancelada') {
            throw new AppError('PAGO_NO_EXIGIBLE', 'La cita cancelada no admite asignación de monto.');
        }
    }

    private function revision(Cita $cita): string
    {
        return $cita->version_programacion.'|'.$cita->actualizada_en->utc()->format('Y-m-d\TH:i:s.u\Z').'|'.$cita->estado;
    }

    public function proponer(string $id, string $monto): array
    {
        return DB::transaction(function () use ($id, $monto): array {
            $actor = $this->actor();
            $this->exigirHabilitacion();
            $cita = $this->citas->porId($id, true) ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $this->disponible($cita);
            if ($this->obligaciones->porCitaBloqueada($id) !== null) {
                throw new AppError('PAGO_PRECIO_YA_ASIGNADO', 'La cita ya tiene un precio histórico asignado.');
            }
            $importe = ImportePago::desdeBob($monto);
            $token = $this->propuestas->emitir(['id' => (string) Str::uuid(), 'actor' => (string) $actor->getKey(),
                'rol' => $actor->rol->nombre, 'cita' => $id, 'revision' => $this->revision($cita), 'monto_bob' => $importe->montoBob()]);

            return ['propuesta' => $token, 'precio' => $importe->precio(),
                'cita' => ['id_cita' => $id, 'codigo_cita' => $cita->codigo_cita, 'estado' => $cita->estado]];
        });
    }

    public function confirmar(string $id, string $token, array $contexto): array
    {
        return DB::transaction(function () use ($id, $token, $contexto): array {
            $actor = $this->actor();
            $prueba = $this->propuestas->abrir($token);
            if ($prueba['actor'] !== (string) $actor->getKey() || $prueba['rol'] !== $actor->rol->nombre || $prueba['cita'] !== $id) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $cita = $this->citas->porId($id, true) ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $existente = $this->obligaciones->porCitaBloqueada($id);
            if ($existente !== null) {
                if ((string) $existente->getKey() !== $prueba['id'] || (string) $existente->emitida_por !== (string) $actor->getKey()
                    || $existente->centavos_bob !== ImportePago::desdeBob($prueba['monto_bob'])->centavosBob) {
                    throw new AppError('PAGO_PRECIO_YA_ASIGNADO', 'La cita ya tiene un precio histórico asignado.');
                }

                return ['obligacion' => $this->obligaciones->paraRespuesta($existente), 'repetida' => true];
            }
            $this->exigirHabilitacion();
            $this->disponible($cita);
            if (! hash_equals($this->revision($cita), $prueba['revision'])) {
                throw new AppError('PAGO_REVISION_OBSOLETA', 'La cita cambió. Revisa nuevamente el monto.');
            }
            $obligacion = $this->emitir($cita, $actor, ImportePago::desdeBob($prueba['monto_bob']), $prueba['id'], $contexto);

            return ['obligacion' => $this->obligaciones->paraRespuesta($obligacion), 'repetida' => false];
        });
    }

    /** Uso interno desde reserva/asignación, manteniendo Cita bloqueada hasta la auditoría. */
    public function emitir(Cita $cita, Usuario $actor, ImportePago $importe, string $uuid, array $contexto): ObligacionPago
    {
        if (DB::transactionLevel() === 0 || ! in_array($actor->rol->nombre, ['Administrador', 'Recepcionista'], true)) {
            throw new LogicException('La emisión requiere una transacción y un ejecutor administrativo.');
        }
        $this->exigirHabilitacion();
        $this->disponible($cita);
        $obligacion = $this->obligaciones->crear(['id_obligacion' => $uuid, 'id_cita' => $cita->getKey(),
            'centavos_bob' => $importe->centavosBob, 'centavos_usd' => $importe->centavosUsd,
            'tasa_numerador' => $importe->tasaNumerador, 'tasa_denominador' => $importe->tasaDenominador,
            'emitida_por' => $actor->getKey(), 'emitida_en' => CarbonImmutable::now('UTC'),
            'vencimiento_at' => $cita->inicio_at]);
        $this->bitacora->obligacionEmitida($cita, $obligacion, $actor, $contexto);

        return $obligacion;
    }
}
