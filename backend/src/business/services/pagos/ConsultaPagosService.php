<?php

namespace Clinica\business\services\pagos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\data\repositories\pagos\ObligacionPagoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultaPagosService
{
    public function __construct(private AutenticacionService $autenticacion, private AgendaRepository $agenda,
        private ObligacionPagoRepository $obligaciones) {}

    public function consultar(string $tipo, array $filtros): array
    {
        return DB::transaction(function () use ($tipo, $filtros): array {
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $rol = $actor->rol->nombre;
            if (! in_array($rol, ['Paciente', 'Administrador'], true)) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            if ($rol === 'Paciente') {
                if (array_key_exists('id_paciente', $filtros)) {
                    throw ValidationException::withMessages(['id_paciente' => ['Esta consulta utiliza exclusivamente tu ficha.']]);
                }
                $paciente = $actor->paciente;
                if (! $paciente || (string) $paciente->id_usuario !== (string) $actor->getKey() || $actor->medico !== null) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $id = (string) $paciente->getKey();
            } else {
                if (! isset($filtros['id_paciente'])) {
                    throw ValidationException::withMessages(['id_paciente' => ['Selecciona una ficha de Paciente.']]);
                }
                $id = (string) $filtros['id_paciente'];
                if ($this->agenda->paciente($id) === null) {
                    throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                }
            }
            if (! $this->obligaciones->disponible()) {
                throw new AppError('PAGO_NO_CONFIGURADO', 'La consulta financiera no está disponible.');
            }
            $ahora = CarbonImmutable::now('UTC');
            $meta = ['calculado_at' => $ahora->format('Y-m-d\TH:i:s.u\Z')];
            if ($tipo === 'detalle') {
                $o = $this->obligaciones->detalle($filtros['obligacion_objetivo'], $id)
                    ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
                $o->setAttribute('calculado_at', $ahora);

                return ['obligacion' => $o, 'meta' => $meta,
                    'intentos' => $this->obligaciones->historial($id, ['page' => $filtros['intentos_page'] ?? 1], (string) $o->getKey()),
                    'devoluciones' => $this->obligaciones->devoluciones((string) $o->getKey(), (int) ($filtros['devoluciones_page'] ?? 1))];
            }
            $pagina = $tipo === 'historial' ? $this->obligaciones->historial($id, $filtros)
                : $this->obligaciones->listar($id, $filtros, $ahora);
            if ($tipo !== 'historial') {
                $pagina->getCollection()->each(fn ($o) => $o->setAttribute('calculado_at', $ahora));
            }

            return ['pagina' => $pagina, 'meta' => $meta];
        });
    }
}
