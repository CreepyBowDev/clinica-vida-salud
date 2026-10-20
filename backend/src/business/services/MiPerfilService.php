<?php

namespace Clinica\business\services;

use Carbon\CarbonImmutable;
use Clinica\data\models\Paciente;
use Clinica\data\repositories\BitacoraRepository;
use Clinica\data\repositories\PacienteRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class MiPerfilService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private PacienteRepository $pacientes,
        private BitacoraRepository $bitacora,
    ) {}

    private function fichaPropia(): Paciente
    {
        $usuario = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($usuario->rol->nombre !== 'Paciente') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $paciente = $this->pacientes->porUsuario($usuario->getKey())
            ?? throw new AppError('ACCESO_DENEGADO', 'No se puede acceder al perfil solicitado.');

        // El recurso solo obtiene el correo propio; nunca serializa el Usuario completo.
        return $paciente->setRelation('usuario', $usuario);
    }

    public function consultar(): Paciente
    {
        return DB::transaction(fn (): Paciente => $this->fichaPropia());
    }

    public function actualizar(array $datos, array $contexto): Paciente
    {
        return DB::transaction(function () use ($datos, $contexto): Paciente {
            $paciente = $this->fichaPropia();
            if (! $paciente->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                throw new AppError('PACIENTE_EDICION_OBSOLETA', 'La ficha cambió desde que la consultaste. Vuelve a cargarla y revisa los cambios.');
            }
            $paciente->fill(array_intersect_key($datos, array_flip(['telefono', 'direccion', 'correo_contacto'])));
            $campos = array_keys($paciente->getDirty());
            if (array_key_exists('contacto_emergencia', $datos)) {
                $emergencia = $datos['contacto_emergencia'];
                if ($emergencia !== null) {
                    $emergencia = ['nombre' => $emergencia['nombre'], 'parentesco' => $emergencia['parentesco'], 'telefono' => $emergencia['telefono']];
                }
                if ($paciente->contactoEmergencia?->only(['nombre', 'parentesco', 'telefono']) !== $emergencia) {
                    $this->pacientes->guardarEmergencia($paciente, $emergencia);
                    $campos[] = 'contacto_emergencia';
                }
            }
            if (array_key_exists('recordatorios_habilitados', $datos)
                && $paciente->preferencia?->recordatorios_habilitados !== (bool) $datos['recordatorios_habilitados']) {
                $this->pacientes->guardarRecordatorios($paciente, (bool) $datos['recordatorios_habilitados']);
                $campos[] = 'recordatorios_habilitados';
            }
            if ($campos !== []) {
                $this->pacientes->guardar($paciente);
                $this->bitacora->pacienteModificado($paciente, $paciente->usuario, $contexto, $campos, null);
            }

            return $paciente->load(['contactoEmergencia', 'preferencia']);
        });
    }
}
