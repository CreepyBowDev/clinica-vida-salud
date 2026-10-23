<?php

namespace Clinica\business\services\agenda;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistorialCitasService
{
    public function __construct(private AutenticacionService $autenticacion, private AgendaRepository $agenda) {}

    public function consultar(array $filtros, ?string $idPaciente = null): array
    {
        return DB::transaction(function () use ($filtros, $idPaciente): array {
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $rol = $actor->rol->nombre;
            if (! in_array($rol, ['Recepcionista', 'Paciente'], true) || ($rol === 'Paciente' && $idPaciente !== null)) {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            if (array_key_exists('id_paciente', $filtros) || array_key_exists('id_objetivo', $filtros)) {
                throw ValidationException::withMessages(['id_paciente' => ['La ficha no se selecciona mediante estos filtros.']]);
            }
            if ($rol === 'Paciente') {
                $propio = $actor->paciente;
                if (! $propio || (string) $propio->id_usuario !== (string) $actor->getKey() || $actor->medico !== null) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $idPaciente = (string) $propio->getKey();
            } elseif ($idPaciente === null) {
                throw ValidationException::withMessages(['id_paciente' => ['Selecciona una ficha de Paciente.']]);
            }
            $paciente = $this->agenda->paciente($idPaciente)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
            $ahora = CarbonImmutable::now('UTC');
            $filtros['periodo'] ??= $rol === 'Paciente' ? 'proximas' : 'todas';

            return ['pagina' => $this->agenda->historial($idPaciente, $ahora, $filtros),
                'contexto' => ['zona_horaria' => 'America/La_Paz', 'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                    'filtros' => ['periodo' => $filtros['periodo'], 'estado' => $filtros['estado'] ?? null],
                    'paciente' => ['id_paciente' => (string) $paciente['id_paciente'], 'codigo_paciente' => $paciente['codigo_paciente'],
                        'nombres' => $paciente['nombres'], 'apellidos' => $paciente['apellidos']]]];
        });
    }
}
