<?php

namespace Clinica\business\services\pacientes;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\models\Paciente;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pacientes\CodigoActivacionRepository;
use Clinica\data\repositories\pacientes\PacienteRepository;
use Clinica\data\repositories\usuarios\RegistroPacienteRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PacienteService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private PacienteRepository $pacientes,
        private RegistroPacienteRepository $registros,
        private BitacoraRepository $bitacora,
        private CodigoActivacionRepository $activaciones,
    ) {}

    private function recepcionista(): Usuario
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Recepcionista') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->recepcionista();

            return $this->pacientes->listar($filtros);
        });
    }

    public function consultar(int|string $id): Paciente
    {
        return DB::transaction(function () use ($id): Paciente {
            $this->recepcionista();

            return $this->pacientes->consultar($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la ficha solicitada.');
        });
    }

    public function crear(array $datos, array $contexto): Paciente
    {
        try {
            return DB::transaction(function () use ($datos, $contexto): Paciente {
                $this->registros->coordinar();
                $actor = $this->recepcionista();
                $ci = $datos['documento_identidad'] ?? null;
                if ($ci !== null) {
                    if ($this->registros->documentoRegistrado($ci)) {
                        throw new AppError('PACIENTE_DOCUMENTO_NO_DISPONIBLE', 'El documento no está disponible. Revisa las fichas existentes.');
                    }
                    $ahora = CarbonImmutable::now('UTC');
                    foreach ($this->registros->reservasPorDocumento($ci) as $reserva) {
                        if ($reserva->expira_en->greaterThan($ahora)) {
                            throw new AppError('PACIENTE_DOCUMENTO_NO_DISPONIBLE', 'El documento tiene un proceso pendiente. Revisa el registro antes de continuar.');
                        }
                        $this->registros->invalidar($reserva, $ahora);
                    }
                }
                $emergencia = $datos['contacto_emergencia'] ?? null;
                unset($datos['contacto_emergencia'], $datos['posibles_duplicados_revisados']);
                $paciente = $this->pacientes->crear([...$datos, 'documento_identidad' => $ci,
                    'codigo_paciente' => 'PAC-'.bin2hex(random_bytes(16))], $emergencia);
                $this->bitacora->pacienteCreado($paciente, $actor, $contexto);

                return $paciente;
            });
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            throw new AppError('PACIENTE_CONFLICTO_UNICIDAD', 'No se pudo registrar la ficha por un conflicto de unicidad. Consulta los registros antes de volver a intentarlo.');
        }
    }

    private function fichaVigente(int|string $id, string $original): Paciente
    {
        $paciente = $this->pacientes->consultar($id)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la ficha solicitada.');
        if (! $paciente->actualizado_en->equalTo(CarbonImmutable::parse($original, 'UTC'))) {
            throw new AppError('PACIENTE_EDICION_OBSOLETA', 'La ficha cambió desde que la consultaste. Vuelve a cargarla y revisa los cambios.');
        }

        return $paciente;
    }

    public function actualizar(int|string $id, array $datos, array $contexto): Paciente
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto): Paciente {
                $this->registros->coordinar();
                $actor = $this->recepcionista();
                $paciente = $this->fichaVigente($id, $datos['actualizado_en_original']);
                $motivo = $datos['motivo'] ?? null;
                $verificada = (bool) ($datos['identidad_verificada'] ?? false);
                $emergenciaPresente = array_key_exists('contacto_emergencia', $datos);
                $emergencia = $datos['contacto_emergencia'] ?? null;
                if ($emergencia !== null) {
                    $emergencia = ['nombre' => $emergencia['nombre'], 'parentesco' => $emergencia['parentesco'], 'telefono' => $emergencia['telefono']];
                }
                unset($datos['id_objetivo'], $datos['actualizado_en_original'], $datos['motivo'], $datos['identidad_verificada'], $datos['contacto_emergencia']);
                if (array_key_exists('documento_identidad', $datos) && $datos['documento_identidad'] === null && $paciente->documento_identidad !== null) {
                    throw new AppError('PACIENTE_CI_NO_RETIRABLE', 'No se puede retirar un CI informado. Acude a la revisión administrativa para corregirlo.');
                }
                // El CI histórico puede conservar espacios externos. El mismo valor normalizado
                // no es una corrección de identidad ni debe invalidar su vinculación pendiente.
                if (isset($datos['documento_identidad']) && $paciente->documento_identidad !== null
                    && $datos['documento_identidad'] === trim($paciente->documento_identidad, ' ')) {
                    unset($datos['documento_identidad']);
                }
                $paciente->fill($datos);
                $campos = array_keys($paciente->getDirty());
                if (array_intersect($campos, ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo']) !== []
                    && (! $verificada || $motivo === null)) {
                    throw new AppError('PACIENTE_IDENTIDAD_NO_VERIFICADA', 'La corrección de identidad exige comprobación presencial y motivo administrativo.');
                }
                if (in_array('documento_identidad', $campos, true)) {
                    $ci = $paciente->documento_identidad;
                    if ($this->registros->documentoRegistrado($ci)) {
                        throw new AppError('PACIENTE_DOCUMENTO_NO_DISPONIBLE', 'El documento no está disponible. Revisa las fichas existentes.');
                    }
                    $ahora = CarbonImmutable::now('UTC');
                    foreach ($this->registros->reservasPorDocumento($ci) as $reserva) {
                        if ((string) $reserva->id_paciente !== (string) $paciente->getKey() && $reserva->expira_en->greaterThan($ahora)) {
                            throw new AppError('PACIENTE_DOCUMENTO_NO_DISPONIBLE', 'El documento tiene un proceso pendiente. Revisa el registro antes de continuar.');
                        }
                        $this->registros->invalidar($reserva, $ahora);
                    }
                    foreach ($this->registros->porPaciente($paciente->getKey()) as $reserva) {
                        $this->registros->invalidar($reserva, $ahora);
                    }
                    $this->activaciones->invalidarAnteriores($paciente->getKey(), $ahora);
                }
                $anterior = $paciente->contactoEmergencia?->only(['nombre', 'parentesco', 'telefono']);
                if ($emergenciaPresente && $anterior !== $emergencia) {
                    $this->pacientes->guardarEmergencia($paciente, $emergencia);
                    $campos[] = 'contacto_emergencia';
                }
                if ($campos !== []) {
                    $this->pacientes->guardar($paciente);
                    $this->bitacora->pacienteModificado($paciente, $actor, $contexto, $campos, $motivo);
                }

                return $paciente->load(['contactoEmergencia', 'preferencia']);
            });
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            throw new AppError('PACIENTE_CONFLICTO_UNICIDAD', 'No se pudo actualizar la ficha por un conflicto de unicidad. Consulta los registros antes de volver a intentarlo.');
        }
    }

    public function cambiarEstado(int|string $id, array $datos, array $contexto): Paciente
    {
        return DB::transaction(function () use ($id, $datos, $contexto): Paciente {
            $this->registros->coordinar();
            $actor = $this->recepcionista();
            $paciente = $this->fichaVigente($id, $datos['actualizado_en_original']);
            if ($paciente->activo !== (bool) $datos['activo']) {
                $paciente->activo = (bool) $datos['activo'];
                $this->pacientes->guardar($paciente);
                $this->bitacora->pacienteModificado($paciente, $actor, $contexto, ['activo'], $datos['motivo'], estado: true);
            }

            return $paciente;
        });
    }
}
