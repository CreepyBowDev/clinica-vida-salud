<?php

namespace Clinica\business\services\consultorios;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\services\usuarios\ContinuidadAdministrativaService;
use Clinica\business\validators\IntervalosAtencion;
use Clinica\data\models\Consultorio;
use Clinica\data\models\Medico;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\consultorios\AsignacionConsultorioRepository;
use Clinica\data\repositories\consultorios\ConsultorioRepository;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Clinica\data\repositories\medicos\MedicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AsignacionConsultorioService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private ContinuidadAdministrativaService $continuidad,
        private PlanificacionRepository $planificacion,
        private MedicoRepository $medicos,
        private ConsultorioRepository $consultorios,
        private AsignacionConsultorioRepository $asignaciones,
        private IntervalosAtencion $intervalos,
        private BitacoraRepository $bitacora,
    ) {}

    private function administrador(): Usuario
    {
        $this->continuidad->coordinar();
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
        $this->planificacion->coordinar();

        return $actor;
    }

    private function medico(int|string $id, ?string $version = null): Medico
    {
        $medico = $this->medicos->porIdBloqueado($id)
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el médico solicitado.');
        if ($version !== null && ! $medico->actualizado_en->equalTo(CarbonImmutable::parse($version, 'UTC'))) {
            throw new AppError('MEDICO_EDICION_OBSOLETA', 'El médico cambió desde que lo consultaste. Vuelve a cargarlo y revisa los cambios.');
        }

        return $medico;
    }

    private function salas(Medico $medico, int|string|null $destino): array
    {
        $ids = array_unique(array_filter([$medico->id_consultorio, $destino], fn ($id) => $id !== null));
        usort($ids, fn ($a, $b) => strlen((string) $a) <=> strlen((string) $b) ?: strcmp((string) $a, (string) $b));
        $salas = [];
        foreach ($ids as $id) {
            $salas[(string) $id] = $this->consultorios->porIdBloqueado($id)
                ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el consultorio solicitado.');
        }

        return $salas;
    }

    private function resultado(Medico $medico, ?Consultorio $consultorio): array
    {
        return ['id_medico' => (string) $medico->getKey(),
            'actualizado_en' => $medico->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'consultorio' => $consultorio === null ? null : ['id_consultorio' => (string) $consultorio->getKey(),
                'codigo_consultorio' => $consultorio->codigo_consultorio, 'nombre' => $consultorio->nombre,
                'activo' => $consultorio->activo,
                'actualizado_en' => $consultorio->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z')]];
    }

    public function consultar(int|string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $this->administrador();
            $medico = $this->medico($id);
            $salas = $this->salas($medico, null);

            return $this->resultado($medico, $salas[(string) $medico->id_consultorio] ?? null);
        });
    }

    public function impacto(int|string $id, array $datos): array
    {
        return DB::transaction(function () use ($id, $datos): array {
            $actor = $this->administrador();
            $medico = $this->medico($id, $datos['actualizado_en_original']);
            $salas = $this->salas($medico, $datos['id_consultorio']);
            $impacto = $this->evaluar($medico, $salas, $datos['id_consultorio'], $actor);

            return $this->publicar($impacto);
        });
    }

    public function confirmar(int|string $id, array $datos, array $contexto): array
    {
        try {
            return DB::transaction(function () use ($id, $datos, $contexto): array {
                $actor = $this->administrador();
                $medico = $this->medico($id, $datos['actualizado_en_original']);
                $salas = $this->salas($medico, $datos['id_consultorio']);
                $destino = $salas[(string) $datos['id_consultorio']];
                $impacto = $this->evaluar($medico, $salas, $datos['id_consultorio'], $actor);
                if (! hash_equals($impacto['huella'], $datos['huella_impacto'])) {
                    throw new AppError('ASIGNACION_IMPACTO_OBSOLETO', 'El impacto cambió. Consulta otra vista previa antes de confirmar.');
                }
                if ($impacto['conflictos'] !== []) {
                    $codigo = $impacto['conflictos'][0]['codigo'];
                    throw new AppError($codigo, 'La asignación es incompatible. Revisa los conflictos en la vista previa. No se guardó ningún cambio.');
                }
                if ($impacto['traslados'] !== [] && ! (bool) $datos['confirmar_traslados']) {
                    throw new AppError('ASIGNACION_CONFIRMACION_REQUERIDA', 'Confirma expresamente los cambios de ubicación de las citas afectadas.');
                }
                $anterior = $medico->id_consultorio === null ? null : (string) $medico->id_consultorio;
                if ($anterior !== (string) $destino->getKey()) {
                    $medico->id_consultorio = $destino->getKey();
                    $this->medicos->guardar($medico);
                    foreach ($impacto['traslados'] as $cita) {
                        $this->asignaciones->trasladar($cita, $destino, $actor->getKey(), $impacto['ahora']);
                    }
                    $this->bitacora->asignacionConsultorio($medico, $actor, $contexto, $anterior, count($impacto['traslados']));
                }

                return [...$this->resultado($medico, $destino), 'citas_trasladadas' => count($impacto['traslados'])];
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23P01' && (str_contains($error->errorInfo[2] ?? '', 'citas_medico_no_solapado_excl')
                || str_contains($error->errorInfo[2] ?? '', 'citas_consultorio_no_solapado_excl'))) {
                throw new AppError('PLANIFICACION_CONFLICTO', 'La ocupación es incompatible. Consulta de nuevo el impacto. No se guardó ningún cambio.');
            }
            throw $error;
        }
    }

    private function evaluar(Medico $medico, array $salas, int|string $idDestino, Usuario $actor): array
    {
        $destino = $salas[(string) $idDestino];
        $horarios = $this->asignaciones->horarios($medico->getKey());
        $compartidos = $this->asignaciones->compartidos($medico->getKey(), $idDestino);
        // El instante se obtiene después de coordinar y bloquear Médico/consultorios.
        $ahora = CarbonImmutable::now('UTC');
        $citas = $this->asignaciones->citas($medico->getKey(), array_keys($salas), $ahora);
        $conflictos = [];
        $traslados = [];
        $cambia = (string) $medico->id_consultorio !== (string) $idDestino;
        if (! $destino->activo) {
            $conflictos[] = ['codigo' => 'CONSULTORIO_NO_DISPONIBLE', 'id_consultorio' => (string) $idDestino];
        }
        foreach ($horarios as $i => $horario) {
            foreach ([...$horarios->slice($i + 1), ...$compartidos] as $otro) {
                if ($horario->dia_semana === $otro->dia_semana && $this->intervalos->superpuestos($horario->hora_inicio, $horario->hora_fin, $otro->hora_inicio, $otro->hora_fin)) {
                    $conflictos[] = ['codigo' => 'PLANIFICACION_CONFLICTO', 'id_horario' => (string) $horario->getKey(),
                        'id_otro_horario' => (string) $otro->getKey(), 'id_medico' => (string) $otro->id_medico];
                }
            }
        }
        foreach ($citas as $cita) {
            if ((string) $cita->id_medico !== (string) $medico->getKey() || ! $cambia
                || ! in_array($cita->estado, ['Programada', 'Confirmada'], true)) {
                continue;
            }
            if ($cita->inicio_at->lessThan($ahora) || $cita->consulta_exists) {
                $conflictos[] = $this->elemento($cita, 'ATENCION_NO_TRASLADABLE');

                continue;
            }
            if (! $this->intervalos->cubierto($cita->inicio_at, $cita->fin_at, $horarios)) {
                $conflictos[] = $this->elemento($cita, 'PLANIFICACION_CONFLICTO');
            }
            if ((string) $cita->id_consultorio !== (string) $idDestino
                || $cita->consultorio_atencion !== $destino->codigo_consultorio.' — '.$destino->nombre) {
                $traslados[] = $cita;
            }
        }
        $idsTraslados = array_map(fn ($cita) => (string) $cita->getKey(), $traslados);
        $conflictos = [...$conflictos, ...$this->conflictosDeTraslado($citas, $idsTraslados, (string) $idDestino, (string) $medico->getKey())];
        foreach ($traslados as $cita) {
            foreach ($compartidos as $horario) {
                if ($this->intervalos->ocupaFranja($cita->inicio_at, $cita->fin_at, $horario)) {
                    $conflictos[] = [...$this->elemento($cita, 'PLANIFICACION_CONFLICTO'), 'id_horario' => (string) $horario->getKey()];
                }
            }
        }
        // Una cita real ocupa el consultorio aunque su Médico ya tenga otra asignación.
        foreach ($citas as $cita) {
            if ((string) $cita->id_consultorio !== (string) $idDestino || (string) $cita->id_medico === (string) $medico->getKey()) {
                continue;
            }
            foreach ($horarios as $horario) {
                if ($this->intervalos->ocupaFranja($cita->inicio_at->max($ahora), $cita->fin_at, $horario)) {
                    $conflictos[] = [...$this->elemento($cita, 'PLANIFICACION_CONFLICTO'), 'id_horario' => (string) $horario->getKey()];
                }
            }
        }
        $huella = hash('sha256', json_encode(['actor' => (string) $actor->getKey(), 'medico' => [(string) $medico->getKey(),
            $medico->actualizado_en->utc()->format('Y-m-d\TH:i:s.u\Z'), $medico->id_consultorio === null ? null : (string) $medico->id_consultorio],
            'destino' => (string) $idDestino, 'salas' => array_values(array_map(fn ($sala) => $sala->only(['id_consultorio', 'codigo_consultorio', 'nombre', 'activo', 'actualizado_en']), $salas)),
            'horarios' => $horarios->toArray(), 'compartidos' => $compartidos->toArray(), 'citas' => $citas->toArray(),
            'traslados' => $idsTraslados, 'conflictos' => $conflictos], JSON_THROW_ON_ERROR));

        return compact('huella', 'conflictos', 'traslados', 'ahora');
    }

    private function conflictosDeTraslado(iterable $citas, array $idsTraslados, string $destino, string $medico): array
    {
        if ($idsTraslados === []) {
            return [];
        }
        $trasladadas = array_fill_keys($idsTraslados, true);
        $intervalos = [];
        foreach ($citas as $cita) {
            $id = (string) $cita->getKey();
            if (! isset($trasladadas[$id]) && (string) $cita->id_consultorio !== $destino && (string) $cita->id_medico !== $medico) {
                continue;
            }
            // UTC canónico de ancho fijo: convertir una vez evita casts Carbon por cada par.
            $intervalos[] = ['id' => $id, 'inicio' => $cita->inicio_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'fin' => $cita->fin_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'cita' => $cita];
        }
        usort($intervalos, fn ($a, $b) => strcmp($a['inicio'], $b['inicio']) ?: ((int) $a['id'] <=> (int) $b['id']));
        $abiertos = [];
        $conflictos = [];
        foreach ($intervalos as $intervalo) {
            foreach ($abiertos as $id => $otro) {
                if ($otro['fin'] <= $intervalo['inicio']) {
                    unset($abiertos[$id]);

                    continue;
                }
                // Ambos candidatos ocupan el destino o el Médico tras proyectar el cambio.
                foreach ([[$intervalo, $otro], [$otro, $intervalo]] as [$afectado, $ocupante]) {
                    if (isset($trasladadas[$afectado['id']])) {
                        $conflictos[] = [...$this->elemento($afectado['cita'], 'PLANIFICACION_CONFLICTO'), 'id_otra_cita' => $ocupante['id']];
                    }
                }
            }
            $abiertos[$intervalo['id']] = $intervalo;
        }

        return $conflictos;
    }

    private function elemento(object $cita, string $codigo): array
    {
        return ['codigo' => $codigo, 'id_cita' => (string) $cita->getKey(), 'id_medico' => (string) $cita->id_medico,
            'id_consultorio' => (string) $cita->id_consultorio, 'estado' => $cita->estado,
            'inicio_at' => $cita->inicio_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'fin_at' => $cita->fin_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    private function publicar(array $impacto): array
    {
        $elementos = [...$impacto['conflictos'], ...array_map(fn ($cita) => $this->elemento($cita, 'CAMBIO_UBICACION'), $impacto['traslados'])];
        usort($elementos, fn ($a, $b) => strcmp($a['inicio_at'] ?? '', $b['inicio_at'] ?? '')
            ?: strlen($a['id_cita'] ?? '') <=> strlen($b['id_cita'] ?? '') ?: strcmp($a['id_cita'] ?? '', $b['id_cita'] ?? '')
            ?: strcmp(json_encode($a), json_encode($b)));

        return ['puede_confirmar' => $impacto['conflictos'] === [], 'huella_impacto' => $impacto['huella'],
            'totales' => ['conflictos' => count($impacto['conflictos']), 'citas_trasladadas' => count($impacto['traslados'])],
            'elementos' => $elementos];
    }
}
