<?php

namespace Tests\Fixtures;

use Carbon\CarbonImmutable;
use Clinica\business\services\reportes\ReporteService;
use ReflectionClass;

/** Documentos internos sintéticos. Sin repositorios, bootstrap Laravel ni SQL. */
class ReportesPdfEstadisticasDocumentos
{
    public static function tipos(): array
    {
        return array_map(fn ($tipo) => [$tipo], ['citas/programadas', 'citas/por-medico', 'citas/por-especialidad',
            'citas/por-estado', 'pacientes/registrados', 'citas/cantidades', 'agenda-diaria']);
    }

    public static function periodo(string $desde, string $hasta, string $referencia = 'citas.inicio_at'): array
    {
        return ['desde' => $desde, 'hasta' => $hasta, 'referencia' => $referencia, 'criterio' => 'inicio_en_periodo',
            'inicio_at' => CarbonImmutable::parse($desde, 'America/La_Paz')->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'fin_exclusivo_at' => CarbonImmutable::parse($hasta, 'America/La_Paz')->addDay()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    public static function mapear(array $contexto, array $agregados): array
    {
        // Mapper real EST-2 con agregados inyectados: NO prueba de consulta/snapshot SQL.
        $clase = new ReflectionClass(ReporteService::class);

        return $clase->getMethod('estadisticas')->invoke($clase->newInstanceWithoutConstructor(),
            $contexto['reporte'], $contexto['filtros'], $contexto['periodo'], $agregados);
    }

    public static function medico(int $id): array
    {
        return ['id_medico' => (string) $id, 'codigo_medico' => sprintf('MED-%03d', $id),
            'medico_nombres' => 'Ana María', 'medico_apellidos' => 'Álvarez Muñoz'];
    }

    public static function documento(string $tipo, bool $vacio = false, int $numero = 5): array
    {
        $total = $vacio ? 0 : $numero;
        $pacientes = $tipo === 'pacientes/registrados';
        $porMedico = in_array($tipo, ['citas/programadas', 'citas/por-especialidad'], true);
        $temporal = in_array($tipo, ['citas/por-estado', 'pacientes/registrados'], true);
        $contexto = ['reporte' => $tipo, 'zona_horaria' => 'America/La_Paz',
            'periodo' => self::periodo('2026-11-01', $temporal ? '2026-11-03' : '2026-11-01', $pacientes ? 'pacientes.creado_en' : 'citas.inicio_at'),
            'filtros' => ['desde' => '2026-11-01', 'hasta' => $temporal ? '2026-11-03' : '2026-11-01', 'orden' => 'fecha_asc'],
            'medico' => ['codigo_medico' => 'MED-002', 'nombres' => 'Ana María', 'apellidos' => 'Álvarez Muñoz']];
        if ($tipo === 'agenda-diaria') {
            $contexto['periodo']['criterio'] = 'interseccion_diaria';
        }
        if ($tipo === 'citas/programadas') {
            $contexto['filtros']['estado'] = 'Programada';
        } elseif ($tipo === 'citas/por-estado') {
            $contexto['filtros']['estado'] = 'Cancelada';
        }
        $grupos = [];
        if ($total > 0) {
            $grupos = $porMedico ? [[...self::medico(2), 'cantidad' => $total - 1], [...self::medico(10), 'cantidad' => 1]]
                : ($temporal ? [['periodo' => '2026-11-01', 'cantidad' => $total - 1], ['periodo' => '2026-11-03', 'cantidad' => 1]]
                    : [['estado' => 'Programada', 'cantidad' => $total - 1, 'minutos' => ($total - 1) * 40],
                        ['estado' => 'Cancelada', 'cantidad' => 1, 'minutos' => 40]]);
        }
        $agregados = ['total' => $total, 'minutos' => $total * 40, 'grupos' => $grupos,
            'actividad' => [['activo' => true, 'cantidad' => max(0, $total - 1)], ['activo' => false, 'cantidad' => $total > 0 ? 1 : 0]]];
        $filas = [];
        if ($tipo !== 'citas/cantidades') {
            for ($i = 0; $i < $total; $i++) {
                $ultimo = $i === $total - 1;
                $inicio = CarbonImmutable::parse($temporal && $ultimo ? '2026-11-03T12:00:00Z' : '2026-11-01T12:00:00Z')->addMinutes($i);
                if ($tipo === 'agenda-diaria' && $i === 0) {
                    $inicio = CarbonImmutable::parse('2026-11-01T03:50:00Z');
                }
                $filas[] = $pacientes ? ['codigo_paciente' => sprintf('FICTICIO-P-%04d', $i), 'nombres' => 'María José',
                    'apellidos' => 'Álvarez de Prueba', 'activo' => ! $ultimo, 'creado_en' => $inicio->toIso8601String()]
                    : [...self::medico($porMedico && $ultimo ? 10 : 2), 'codigo_cita' => sprintf('FICTICIO-C-%04d', $i),
                        'inicio_at' => $inicio->toIso8601String(), 'fin_at' => $inicio->addMinutes(40)->toIso8601String(),
                        'paciente_nombres' => 'María José', 'paciente_apellidos' => 'Álvarez de Prueba',
                        'especialidad_nombre' => 'Medicina general', 'estado' => $tipo === 'citas/por-estado' || ($ultimo && ! $porMedico) ? 'Cancelada' : 'Programada',
                        'duracion_minutos' => 40, 'sede_atencion' => 'Sede única ficticia', 'consultorio_atencion' => 'C-01'];
            }
        }
        $documento = ['contexto' => $contexto, 'filas' => $filas, 'total' => $total, 'cantidades' => null,
            'generado_en' => '2026-11-07 12:00:00 -04:00'];
        if ($tipo === 'citas/cantidades') {
            return self::cantidades($documento, $vacio ? [0, 0, 0] : [1, $total - 1, 0]);
        }
        $documento['contexto']['estadisticas'] = self::mapear($contexto, $agregados);

        return $documento;
    }

    public static function cantidades(array $documento, array $conteos): array
    {
        $total = array_sum($conteos);
        $documento['total'] = $total;
        $documento['cantidades'] = array_combine(['atendidas', 'canceladas', 'inasistencias', 'total'], [...$conteos, $total]);
        $documento['contexto']['estadisticas'] = self::mapear($documento['contexto'], ['total' => $total,
            'grupos' => array_map(fn ($estado, $cantidad) => compact('estado', 'cantidad'), ['Atendida', 'Cancelada', 'Inasistencia'], $conteos)]);

        return $documento;
    }

    public static function muchosMedicos(int $numero = 60): array
    {
        $d = self::documento('citas/programadas', numero: $numero);
        $grupos = [];
        foreach ($d['filas'] as $i => &$fila) {
            $medico = [...self::medico($i + 1), 'medico_nombres' => 'María José del Carmen Alejandra de los Ángeles',
                'medico_apellidos' => 'Álvarez Muñoz de la Fuente Villarroel de Prueba'];
            $fila = [...$fila, ...$medico];
            $grupos[] = [...$medico, 'cantidad' => 1];
        }
        unset($fila);
        $d['contexto']['estadisticas'] = self::mapear($d['contexto'], ['total' => $numero, 'grupos' => $grupos]);

        return $d;
    }
}
