import { medico, reporte } from './reporteFixtures';
import { conEstadisticas } from './estadisticasFixtures';

export function cantidadesReporte(params = {}, counts = { atendidas: 10, canceladas: 20, inasistencias: 7, total: 37 }) {
  return conEstadisticas({ data: counts, meta: { reporte: 'citas/cantidades', zona_horaria: 'America/La_Paz', consultado_en: '2026-10-31T04:00:00.000000Z',
    filtros: { fecha: params.fecha ?? null, desde: params.desde ?? null, hasta: params.hasta ?? null, id_medico: params.id_medico || null,
      id_especialidad: params.id_especialidad || null, estados: ['Atendida', 'Cancelada', 'Inasistencia'] },
    periodo: { referencia: 'citas.inicio_at', criterio: 'inicio_en_periodo' } } });
}
export function agendaReporte(params = {}, total = 1) {
  const result = reporte(params, total);
  result.meta = { ...result.meta, reporte: 'agenda-diaria', medico, filtros: { fecha: params.fecha, id_medico: params.id_medico, estado: params.estado || null },
    periodo: { referencia: 'citas.inicio_at', criterio: 'interseccion_diaria' } };
  return conEstadisticas(result);
}
