<?php

namespace Clinica\business\services\agenda;

use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\agenda\AgendaRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class ConsultaCitaService
{
    public function __construct(private AutenticacionService $autenticacion, private AgendaRepository $agenda) {}

    public function consultar(string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            $rol = $actor->rol->nombre;
            $ambito = [];
            $campos = [];
            if ($rol === 'Médico') {
                $medico = $actor->medico;
                if (! $medico || (string) $medico->id_usuario !== (string) $actor->getKey() || $actor->paciente !== null) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $ambito = ['citas.id_medico' => (string) $medico->getKey()];
                $campos = ['citas.motivo_breve', 'p.fecha_nacimiento as paciente_fecha_nacimiento'];
            } elseif ($rol === 'Paciente') {
                $paciente = $actor->paciente;
                if (! $paciente || (string) $paciente->id_usuario !== (string) $actor->getKey() || $actor->medico !== null) {
                    throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
                }
                $ambito = ['citas.id_paciente' => (string) $paciente->getKey()];
                $campos = ['citas.motivo_breve'];
            } elseif ($rol === 'Recepcionista') {
                $campos = ['citas.motivo_breve', 'p.documento_identidad as paciente_documento_identidad', 'p.telefono as paciente_telefono'];
            } elseif ($rol !== 'Administrador') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $cita = $this->agenda->detalle($id, $ambito, $campos)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');

            return ['cita' => $cita, 'rol' => $rol];
        });
    }
}
