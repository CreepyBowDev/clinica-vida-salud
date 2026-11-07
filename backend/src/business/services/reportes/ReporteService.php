<?php

namespace Clinica\business\services\reportes;

use Carbon\CarbonImmutable;
use Clinica\business\services\agenda\AgendaService;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\data\repositories\reportes\ReporteRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;
use OverflowException;

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

    public function autorizarEntrega(): void
    {
        DB::transaction(fn () => $this->exigirAdministrador());
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

            $cantidades = array_map(fn ($valor) => $this->entero($valor), $this->reportes->cantidades($periodo['inicio'], $periodo['fin'], $filtros));
            $agregados = ['total' => $cantidades['total'], 'grupos' => []];
            foreach (['Atendida' => 'atendidas', 'Cancelada' => 'canceladas', 'Inasistencia' => 'inasistencias'] as $estado => $campo) {
                $agregados['grupos'][] = ['estado' => $estado, 'cantidad' => $cantidades[$campo]];
            }

            return ['cantidades' => $cantidades,
                'contexto' => [...$periodo['contexto'], 'reporte' => 'citas/cantidades', 'filtros' => $filtros,
                    'estadisticas' => $this->estadisticas('citas/cantidades', $filtros, $periodo['contexto']['periodo'], $agregados)]];
        });
    }

    public function agendaDiaria(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $this->exigirAdministrador();
            $periodo = $this->periodo($datos, 'citas.inicio_at', 'interseccion_diaria');
            $result = $this->agenda->consultarParaReporte($datos);
            $result['contexto'] = [...$periodo['contexto'], ...$result['contexto'], 'reporte' => 'agenda-diaria'];
            $result['contexto']['estadisticas'] = $this->estadisticas('agenda-diaria', $result['contexto']['filtros'],
                $periodo['contexto']['periodo'], $result['agregados']);
            unset($result['agregados']);

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

            $resultado = $this->reportes->pacientesRegistrados($periodo['inicio'], $periodo['fin'], [...$datos, ...$filtros]);

            return ['pagina' => $resultado['pagina'],
                'contexto' => [...$periodo['contexto'], 'reporte' => 'pacientes/registrados', 'filtros' => $filtros,
                    'estadisticas' => $this->estadisticas('pacientes/registrados', $filtros, $periodo['contexto']['periodo'], $resultado['agregados'])]];
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

            $resultado = $this->reportes->citas($periodo['inicio'], $periodo['fin'], [...$datos, ...$filtros], $reporte);

            return ['pagina' => $resultado['pagina'],
                'contexto' => [...$periodo['contexto'], 'reporte' => $reporte, 'filtros' => $filtros,
                    'estadisticas' => $this->estadisticas($reporte, $filtros, $periodo['contexto']['periodo'], $resultado['agregados'])]];
        });
    }

    private function entero(mixed $valor): int
    {
        // No aceptar floats ni casts que oculten desbordamiento; también limita COUNT/SUM SQL numeric.
        if ((! is_int($valor) && ! is_string($valor)) || ! preg_match('/^(0|[1-9][0-9]*)$/D', (string) $valor)
            || strlen((string) $valor) > 16 || (strlen((string) $valor) === 16 && strcmp((string) $valor, '9007199254740991') > 0)) {
            throw new OverflowException('Entero estadístico no representable.');
        }

        return (int) $valor;
    }

    private function porcentaje(int $cantidad, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }
        // PHP64: 1000 * MAX_SAFE_INTEGER cabe en int. HALF_UP racional, sin float.
        $numerador = 1000 * $cantidad;
        $decimas = intdiv($numerador, $total);
        if (2 * ($numerador % $total) >= $total) {
            $decimas++;
        }

        return intdiv($decimas, 10).'.'.($decimas % 10);
    }

    private function categoria(string $id, string $etiqueta, mixed $cantidad, array $dimension, ?int $minutos = null, ?string $porcentaje = null): array
    {
        return ['id' => $id, 'etiqueta' => $etiqueta, 'cantidad' => $this->entero($cantidad),
            'duracion_minutos' => $minutos, 'porcentaje' => $porcentaje, ...$dimension];
    }

    private function serie(string $dimension, array $categorias, ?string $agrupacion = null, ?int $denominador = null): array
    {
        $positivas = count(array_filter($categorias, fn ($categoria) => $categoria['cantidad'] > 0));

        return ['id' => 'por_'.$dimension, 'dimension' => $dimension, 'agrupacion' => $agrupacion,
            'porcentajes' => $denominador === null ? null : ['denominador' => $denominador, 'decimales' => 1, 'redondeo' => 'HALF_UP'],
            'grafico' => ['tipo' => 'barras_horizontales', 'visible' => $positivas >= 2,
                'motivo_omision' => $positivas >= 2 ? null : ($positivas === 0 ? 'sin_datos' : 'unica_categoria_con_datos'),
                'maximo' => $categorias === [] ? 0 : max(array_column($categorias, 'cantidad'))], 'categorias' => $categorias];
    }

    private function serieTemporal(array $periodo, array $grupos): array
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m-d', $periodo['desde'], 'America/La_Paz');
        $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $periodo['hasta'], 'America/La_Paz');
        $diaria = $desde->diffInDays($hasta) + 1 <= 31;
        $conteos = array_column($grupos, 'cantidad', 'periodo');
        $categorias = [];
        for ($cursor = $desde; $cursor <= $hasta; $cursor = $fin->addDay()) {
            $fin = $diaria ? $cursor : $cursor->endOfMonth()->startOfDay()->min($hasta);
            $clave = $cursor->format($diaria ? 'Y-m-d' : 'Y-m');
            $parcial = ! $diaria && ($cursor->day !== 1 || $fin->day !== $fin->daysInMonth);
            $categorias[] = $this->categoria(($diaria ? 'dia:' : 'mes:').$clave, $clave.($parcial ? ' (parcial)' : ''),
                $conteos[$clave] ?? 0, ['intervalo' => ['desde' => $cursor->format('Y-m-d'), 'hasta' => $fin->format('Y-m-d'),
                    'inicio_at' => $cursor->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'fin_exclusivo_at' => $fin->addDay()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'parcial' => $parcial]]);
        }

        return $this->serie('periodo', $categorias, $diaria ? 'diaria' : 'mensual');
    }

    private function estadisticas(string $reporte, array $filtros, array $periodo, array $agregados): array
    {
        $estados = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
        $pacientes = $reporte === 'pacientes/registrados';
        $cantidades = $reporte === 'citas/cantidades';
        $conMinutos = in_array($reporte, ['citas/por-medico', 'agenda-diaria'], true);
        $total = $this->entero($agregados['total']);
        $minutos = $conMinutos ? $this->entero($agregados['minutos']) : null;
        $incluidos = $pacientes ? [] : ($cantidades ? array_slice($estados, 2)
            : (isset($filtros['estado']) ? [$filtros['estado']] : $estados));
        $grupos = $agregados['grupos'];
        if ($pacientes) {
            $actividad = [0 => 0, 1 => 0];
            foreach ($agregados['actividad'] as $grupo) {
                $actividad[(int) $grupo['activo']] = $grupo['cantidad'];
            }
            $series = [$this->serie('actividad', [
                $this->categoria('actividad:activo', 'Activos', $actividad[1], ['activo' => true]),
                $this->categoria('actividad:inactivo', 'Inactivos', $actividad[0], ['activo' => false])]),
                $this->serieTemporal($periodo, $grupos)];
        } elseif ($reporte === 'citas/por-estado') {
            $series = [$this->serieTemporal($periodo, $grupos)];
        } elseif ($conMinutos || $cantidades) {
            $porEstado = array_column($grupos, null, 'estado');
            $categorias = [];
            foreach ($cantidades ? $incluidos : $estados as $estado) {
                $cantidad = $this->entero($porEstado[$estado]['cantidad'] ?? 0);
                $categorias[] = $this->categoria('estado:'.$estado, $estado, $cantidad, ['estado' => $estado],
                    $conMinutos ? $this->entero($porEstado[$estado]['minutos'] ?? 0) : null,
                    $cantidades ? $this->porcentaje($cantidad, $total) : null);
            }
            $series = [$this->serie('estado', $categorias, denominador: $cantidades ? $total : null)];
        } else {
            $categorias = [];
            foreach ($grupos as $grupo) {
                $medico = ['id_medico' => $grupo['id_medico'], 'codigo_medico' => $grupo['codigo_medico'],
                    'nombres' => $grupo['medico_nombres'], 'apellidos' => $grupo['medico_apellidos']];
                $categorias[] = $this->categoria('medico:'.$medico['id_medico'],
                    $medico['apellidos'].', '.$medico['nombres'].' — '.$medico['codigo_medico'], $grupo['cantidad'], ['medico' => $medico]);
            }
            $series = [$this->serie('medico', $categorias)];
        }

        return ['version' => 1, 'unidad' => $pacientes ? 'fichas' : 'citas', 'estados_incluidos' => $incluidos,
            'resumen' => ['cantidad_total' => $total, 'duracion_total_minutos' => $minutos], 'series' => $series];
    }
}
