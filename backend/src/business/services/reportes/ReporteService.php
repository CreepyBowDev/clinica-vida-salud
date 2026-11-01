<?php

namespace Clinica\business\services\reportes;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\reportes\ReporteRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class ReporteService
{
    public function __construct(private AutenticacionService $autenticacion, private ReporteRepository $reportes,
        private AgendaService $agenda) {}

    private function exigirAdministrador(): void
    {
        $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
        if ($actor->rol->nombre !== 'Administrador') {
            throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
        }
    }

    private function periodo(array $datos, string $referencia, string $criterio): array
    {
        $ahora = CarbonImmutable::now('UTC');
        $desde = $datos['fecha'] ?? $datos['desde'];
        $hasta = $datos['fecha'] ?? $datos['hasta'];
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $desde, 'America/La_Paz')->utc();
        $fin = CarbonImmutable::createFromFormat('!Y-m-d', $hasta, 'America/La_Paz')->addDay()->utc();

        return ['inicio' => $inicio, 'fin' => $fin,
            'contexto' => ['zona_horaria' => 'America/La_Paz', 'consultado_en' => $ahora->format('Y-m-d\TH:i:s.u\Z'),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta, 'inicio_at' => $inicio->format('Y-m-d\TH:i:s.u\Z'),
                    'fin_exclusivo_at' => $fin->format('Y-m-d\TH:i:s.u\Z'), 'referencia' => $referencia, 'criterio' => $criterio]]];
    }

    private function validarSeleccionCitas(array $datos): void
    {
        if (isset($datos['id_medico']) && ! $this->reportes->existeMedico((string) $datos['id_medico'])) {
            throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
        }
        if (isset($datos['id_especialidad']) && ! $this->reportes->existeEspecialidad((string) $datos['id_especialidad'])) {
            throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.');
        }
    }

    public function cantidades(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $this->exigirAdministrador();
            $this->validarSeleccionCitas($datos);
            $periodo = $this->periodo($datos, 'citas.inicio_at', 'inicio_en_periodo');
            $filtros = ['fecha' => $datos['fecha'] ?? null, 'desde' => $datos['desde'] ?? null, 'hasta' => $datos['hasta'] ?? null,
                'id_medico' => isset($datos['id_medico']) ? (string) $datos['id_medico'] : null,
                'id_especialidad' => isset($datos['id_especialidad']) ? (string) $datos['id_especialidad'] : null,
                'estados' => ['Atendida', 'Cancelada', 'Inasistencia']];

            return ['cantidades' => $this->reportes->cantidades($periodo['inicio'], $periodo['fin'], $filtros),
                'contexto' => [...$periodo['contexto'], 'reporte' => 'citas/cantidades', 'filtros' => $filtros]];
        });
    }

    public function agendaDiaria(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $this->exigirAdministrador();
            $periodo = $this->periodo($datos, 'citas.inicio_at', 'interseccion_diaria');
            $result = $this->agenda->consultar($datos);
            $result['contexto'] = [...$periodo['contexto'], ...$result['contexto'], 'reporte' => 'agenda-diaria'];

            return $result;
        });
    }

    public function pacientesRegistrados(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $this->exigirAdministrador();
            $periodo = $this->periodo($datos, 'pacientes.creado_en', 'registro_en_periodo');
            $filtros = ['fecha' => $datos['fecha'] ?? null, 'desde' => $datos['desde'] ?? null, 'hasta' => $datos['hasta'] ?? null,
                'activo' => isset($datos['activo']) ? (bool) $datos['activo'] : null, 'orden' => $datos['orden'] ?? 'registro_asc'];

            return ['pagina' => $this->reportes->pacientesRegistrados($periodo['inicio'], $periodo['fin'], [...$datos, ...$filtros]),
                'contexto' => [...$periodo['contexto'], 'reporte' => 'pacientes/registrados', 'filtros' => $filtros]];
        });
    }

    public function citas(string $reporte, array $datos): array
    {
        return DB::transaction(function () use ($reporte, $datos): array {
            $this->exigirAdministrador();
            $this->validarSeleccionCitas($datos);
            $periodo = $this->periodo($datos, 'citas.inicio_at', 'inicio_en_periodo');
            $filtros = ['fecha' => $datos['fecha'] ?? null, 'desde' => $datos['desde'] ?? null, 'hasta' => $datos['hasta'] ?? null,
                'id_medico' => isset($datos['id_medico']) ? (string) $datos['id_medico'] : null,
                'id_especialidad' => isset($datos['id_especialidad']) ? (string) $datos['id_especialidad'] : null,
                'estado' => $reporte === 'citas/programadas' ? 'Programada' : ($datos['estado'] ?? null),
                'orden' => $datos['orden'] ?? 'fecha_asc'];

            return ['pagina' => $this->reportes->citas($periodo['inicio'], $periodo['fin'], [...$datos, ...$filtros]),
                'contexto' => [...$periodo['contexto'], 'reporte' => $reporte, 'filtros' => $filtros]];
        });
    }
}
