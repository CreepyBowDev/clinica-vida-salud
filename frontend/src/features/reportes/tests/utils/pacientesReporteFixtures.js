import { pagina } from './reporteFixtures';
import { conEstadisticas } from './estadisticasFixtures';

export function pacienteRegistrado(id = '9007199254740993', activo = false) {
  return { id_paciente: id, codigo_paciente: `PAC-${id}`, nombres: 'Paciente ficticio', apellidos: 'De prueba', activo,
    creado_en: '2026-11-01T02:30:00.123456Z' };
}
export function pacientesReporte(params = {}, total = 1) {
  const { page = 1, per_page = 20, orden = 'registro_asc' } = params;
  const activo = params.activo === undefined || params.activo === '' ? null : String(params.activo) === '1';
  const data = Array.from({ length: Math.min(per_page, Math.max(0, total - (page - 1) * per_page)) }, (_, i) =>
    pacienteRegistrado(String(9007199254740993n + BigInt((page - 1) * per_page + i)), activo ?? false));
  const result = pagina(data, params, total);
  return conEstadisticas({ ...result, meta: { ...result.meta, reporte: 'pacientes/registrados', zona_horaria: 'America/La_Paz',
    consultado_en: '2026-10-31T04:00:00.000000Z', filtros: { fecha: params.fecha ?? (params.desde ? null : '2026-10-31'),
      desde: params.desde ?? null, hasta: params.hasta ?? null, activo, orden },
    periodo: { referencia: 'pacientes.creado_en', criterio: 'registro_en_periodo' } } });
}
