<?php

namespace Clinica\business\services\medicos;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\data\models\Medico;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\medicos\EspecialidadRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\data\repositories\usuarios\UsuarioRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicoService
{
    private const CAMPOS = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto'];

    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private MedicoRepository $medicos,
        private EspecialidadRepository $especialidades,
        private BitacoraRepository $bitacora,
        private UsuarioRepository $usuarios,
    ) {}

    private function administrador(): Usuario
    {
        // Mismo orden que CU-03: coordinación administrativa → actor → profesional.
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }

        return $actor;
    }

    private function profesional(int|string $id): Medico
    {
        return $this->medicos->porIdBloqueado($id)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el médico solicitado.');
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filtros): LengthAwarePaginator {
            $this->administrador();

            return $this->medicos->listar($filtros);
        });
    }

    public function consultar(int|string $id): Medico
    {
        return DB::transaction(function () use ($id): Medico {
            $this->administrador();

            return $this->medicos->cargarEspecialidades($this->profesional($id));
        });
    }

    public function crear(array $datos, array $contexto): Medico
    {
        try {
            return DB::transaction(function () use ($datos, $contexto): Medico {
                $actor = $this->administrador();
                $ids = array_map('strval', $datos['especialidades']);
                usort($ids, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
                foreach ($ids as $id) {
                    // Asociar no genera disponibilidad: actividad del catálogo y par son independientes.
                    if ($this->especialidades->porIdBloqueado($id) === null) {
                        throw ValidationException::withMessages(['especialidades' => ['Selecciona especialidades existentes.']]);
                    }
                }
                $medico = $this->medicos->crear(array_intersect_key($datos, array_flip(self::CAMPOS)), $ids);
                $this->bitacora->medicoModificado($medico, $actor, $contexto, 'MEDICO_CREADO', [...self::CAMPOS, 'especialidades', 'activo']);

                return $this->medicos->cargarEspecialidades($medico);
            });
        } catch (QueryException $error) {
            $this->rechazarConflicto($error);
        }
    }

    public function actualizar(int|string $id, array $datos, array $contexto): Medico
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto): Medico {
                $actor = $this->administrador();
                $medico = $this->profesional($id);
                if (! $medico->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                    throw new AppError('MEDICO_EDICION_OBSOLETA', 'El médico cambió desde que lo consultaste. Vuelve a cargarlo y revisa los cambios.');
                }
                $medico->forceFill(array_intersect_key($datos, array_flip(self::CAMPOS)));
                $campos = array_keys($medico->getDirty());
                if ($campos !== []) {
                    $this->medicos->guardar($medico);
                    $this->bitacora->medicoModificado($medico, $actor, $contexto, 'MEDICO_ACTUALIZADO', $campos);
                }

                return $this->medicos->cargarEspecialidades($medico);
            });
        } catch (QueryException $error) {
            $this->rechazarConflicto($error);
        }
    }

    public function cambiarEstado(int|string $id, array $datos, array $contexto): Medico
    {
        return DB::transaction(function () use ($id, $datos, $contexto): Medico {
            $actor = $this->administrador();
            $medico = $this->profesional($id);
            if (! $medico->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                throw new AppError('MEDICO_EDICION_OBSOLETA', 'El médico cambió desde que lo consultaste. Vuelve a cargarlo y revisa los cambios.');
            }
            $activo = (bool) $datos['activo'];
            if ($activo && $medico->id_usuario !== null) {
                // CU-03 comparte la coordinación administrativa: no cambia rol/vínculo
                // durante esta lectura, sin adquirir un lock de Usuario después del de Médico.
                $usuario = $this->usuarios->porId($medico->id_usuario);
                if (! $usuario || $usuario->rol?->nombre !== 'Médico' || $usuario->paciente !== null
                    || (string) $usuario->medico?->getKey() !== (string) $medico->getKey()) {
                    throw new AppError('MEDICO_VINCULO_INCOMPATIBLE', 'La cuenta vinculada no conserva un rol e identidad profesional compatibles. Revisa el vínculo mediante la gestión de cuentas.');
                }
            }
            if ($medico->activo !== $activo) {
                $medico->activo = $activo;
                $this->medicos->guardar($medico);
                $this->bitacora->medicoModificado($medico, $actor, $contexto, 'MEDICO_ESTADO_CAMBIADO', ['activo']);
            }

            return $this->medicos->cargarEspecialidades($medico);
        });
    }

    public function cambiarEspecialidades(int|string $id, array $datos, array $contexto): Medico
    {
        return DB::transaction(function () use ($id, $datos, $contexto): Medico {
            $actor = $this->administrador();
            $medico = $this->profesional($id);
            if (! $medico->actualizado_en->equalTo(CarbonImmutable::parse($datos['actualizado_en_original'], 'UTC'))) {
                throw new AppError('MEDICO_EDICION_OBSOLETA', 'El médico cambió desde que lo consultaste. Vuelve a cargarlo y revisa los cambios.');
            }
            $deseadas = array_map('strval', $datos['especialidades']);
            if ($deseadas === []) {
                throw new AppError('MEDICO_SIN_ESPECIALIDADES_HABILITADAS', 'El médico debe conservar al menos una especialidad habilitada.');
            }
            $pares = $this->medicos->pares($id);
            $ids = array_unique([...array_map('strval', array_keys($pares)), ...$deseadas]);
            usort($ids, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            foreach ($ids as $idEspecialidad) {
                if ($this->especialidades->porIdBloqueado($idEspecialidad) === null) {
                    throw ValidationException::withMessages(['especialidades' => ['Selecciona especialidades existentes.']]);
                }
            }
            $pares = $this->medicos->pares($id, bloquear: true);
            $vigentes = array_map('strval', array_keys(array_filter($pares)));
            $retirar = array_values(array_diff($vigentes, $deseadas));
            $habilitar = array_values(array_diff($deseadas, $vigentes));
            // Instante único del servidor después de obtener los locks, no al iniciar HTTP.
            $ahora = CarbonImmutable::now('UTC');
            if ($retirar !== [] && $this->medicos->tieneCitasFuturas($id, $retirar, $ahora)) {
                throw new AppError('MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS', 'No se puede retirar una especialidad con citas futuras vigentes. No se guardó ningún cambio.');
            }
            if ($retirar !== [] || $habilitar !== []) {
                $this->medicos->guardarHabilitaciones($medico, $habilitar, $retirar, $pares);
                $this->medicos->guardar($medico);
                $this->bitacora->medicoModificado($medico, $actor, $contexto, 'MEDICO_ESPECIALIDADES_CAMBIADAS', ['especialidades'],
                    ['habilitadas' => $habilitar, 'retiradas' => $retirar]);
            }

            return $this->medicos->cargarEspecialidades($medico);
        });
    }

    private function rechazarConflicto(QueryException $error): never
    {
        if ($error->getCode() === '23505' && str_contains($error->errorInfo[2] ?? '', 'medicos_codigo_uq')) {
            throw new AppError('MEDICO_CONFLICTO_UNICIDAD', 'No se pudo asignar un código único al profesional. Vuelve a intentarlo.');
        }
        throw $error;
    }
}
