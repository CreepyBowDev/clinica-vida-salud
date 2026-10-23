<?php

namespace Clinica\business\services\agenda;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaService
{
    public function __construct(private AutenticacionService $autenticacion, private AgendaRepository $agenda) {}

    private function actor(array $roles): Usuario
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if (! in_array($actor->rol->nombre, $roles, true)) {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    public function medicos(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->actor(['Administrador', 'Recepcionista']);

            return $this->agenda->medicos($filtros);
        });
    }

    public function consultar(array $filtros): array
    {
        return DB::transaction(function () use ($filtros): array {
            $actor = $this->actor(['Administrador', 'Recepcionista', 'Médico']);
            if ($actor->rol->nombre === 'Médico') {
                if (array_key_exists('id_medico', $filtros)) {
                    throw ValidationException::withMessages(['id_medico' => ['El médico se determina desde tu sesión.']]);
                }
                $propio = $actor->medico;
                if (! $propio || (string) $propio->id_usuario !== (string) $actor->getKey() || $actor->paciente !== null) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $id = (string) $propio->getKey();
            } else {
                $id = (string) ($filtros['id_medico'] ?? '');
                if ($id === '') {
                    throw ValidationException::withMessages(['id_medico' => ['Selecciona un médico.']]);
                }
            }
            $medico = $this->agenda->medico($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $ahora = CarbonImmutable::now('UTC');
            $fecha = $filtros['fecha'] ?? $ahora->setTimezone('America/La_Paz')->format('Y-m-d');
            $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $fecha, 'America/La_Paz');

            return ['pagina' => $this->agenda->citas($id, $inicio, $inicio->addDay(), $filtros),
                'contexto' => ['zona_horaria' => 'America/La_Paz', 'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                    'filtros' => ['id_medico' => $id, 'fecha' => $fecha, 'estado' => $filtros['estado'] ?? null],
                    'medico' => ['id_medico' => (string) $medico['id_medico'], 'codigo_medico' => $medico['codigo_medico'],
                        'nombres' => $medico['nombres'], 'apellidos' => $medico['apellidos']]]];
        });
    }
}
