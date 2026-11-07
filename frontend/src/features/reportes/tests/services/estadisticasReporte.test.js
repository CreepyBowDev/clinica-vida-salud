import { afterEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { validarEstadisticasReporte } from '../../services/estadisticasReporte';
import { variantes } from '../utils/variantesEstadisticas';
import { reporte } from '../utils/reporteFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { cantidadesReporte } from '../utils/cantidadesAgendaFixtures';
import { categoria, serie } from '../utils/estadisticasFixtures';

afterEach(() => vi.restoreAllMocks());
it.each(variantes)('%s: conserva contrato completo de la respuesta, incluido vacío', async (tipo, load, seleccion, fixture) => {
  const params = { fecha: '2026-10-31', ...seleccion };
  const get = vi.spyOn(api, 'get');
  for (const n of [0, 5]) {
    const value = fixture(params, n); get.mockResolvedValue({ data: value });
    const result = await load(params);
    expect(result.meta.estadisticas).toEqual(value.meta.estadisticas);
    expect(result.meta.periodo).toEqual(value.meta.periodo);
    expect(result.meta.estadisticas.resumen.cantidad_total).toBe(n);
  }
});
it.each(variantes.filter(v => v[0] !== 'cantidades'))('%s: página 2 y fuera de rango no pierden agregados positivos', async (tipo, load, seleccion, fixture) => {
  const get = vi.spyOn(api, 'get'); let estadisticas;
  for (const page of [1, 2, 3]) {
    const params = { fecha: '2026-10-31', ...seleccion, page }, value = fixture(params, 21);
    get.mockResolvedValue({ data: value }); const result = await load(params);
    estadisticas ??= result.meta.estadisticas;
    expect(result.meta.estadisticas).toEqual(estadisticas);
    if (page === 3) expect(result.data).toEqual([]);
  }
});
const corrupciones = [
  ['ausente', e => { delete e.version; }], ['clave extra', e => { e.diagnostico = 'PRIVADO'; }],
  ['version', e => { e.version = '1'; }], ['unidad', e => { e.unidad = 'horas'; }],
  ['estados', e => { e.estados_incluidos = ['Confirmada']; }], ['total string', e => { e.resumen.cantidad_total = '5'; }],
  ['overflow', e => { e.resumen.cantidad_total = 9007199254740992; }], ['minutos no aprobados', e => { e.resumen.duracion_total_minutos = 0; }],
  ['serie extra', e => { e.series.push(e.series[0]); }], ['dimension', e => { e.series[0].dimension = 'estado'; }],
  ['agrupacion', e => { e.series[0].agrupacion = 'diaria'; }], ['porcentajes', e => { e.series[0].porcentajes = {}; }],
  ['cantidad negativa', e => { e.series[0].categorias[0].cantidad = -1; }], ['cantidad float', e => { e.series[0].categorias[0].cantidad = 0.5; }],
  ['suma incoherente', e => { e.series[0].categorias[0].cantidad = 4; }], ['id number', e => { e.series[0].categorias[0].medico.id_medico = 2; }],
  ['id no canónico', e => { e.series[0].categorias[0].medico.id_medico = '02'; }],
  ['id bigint fuera de rango', e => { e.series[0].categorias[0].medico.id_medico = '9223372036854775808'; }],
  ['etiqueta no coincide', e => { e.series[0].categorias[0].etiqueta = 'Otro'; }],
  ['categoría extra', e => { e.series[0].categorias[0].intervalo = null; }],
  ['nulls', e => { delete e.series[0].categorias[0].porcentaje; }],
  ['tipo gráfico', e => { e.series[0].grafico.tipo = 'pastel'; }], ['visible', e => { e.series[0].grafico.visible = true; }],
  ['motivo', e => { e.series[0].grafico.motivo_omision = 'sin_datos'; }], ['máximo', e => { e.series[0].grafico.maximo = 6; }],
];
it.each(corrupciones)('rechazo seguro sin datos fabricados: %s', async (nombre, cambiar) => {
  const value = reporte({ fecha: '2026-10-31' }, 5); cambiar(value.meta.estadisticas);
  vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  await expect(variantes[0][1]({ fecha: '2026-10-31' })).rejects.toThrow('No se pudo verificar el reporte.');
});
it('homónimos, identidades bigint, orden numérico y todas las categorías sin topN', () => {
  const value = reporte({}, 12), ids = ['2', '10', '11', '12', '13', '14', '15', '16', '17', '18', '9007199254740993', '9223372036854775807'];
  const cats = ids.map(id => {
    const medico = { id_medico: id, codigo_medico: `MED-${id}`, nombres: 'Ana', apellidos: 'Nombre ficticio largo '.repeat(8) };
    return categoria(`medico:${id}`, `${medico.apellidos}, ${medico.nombres} — ${medico.codigo_medico}`, 1, { medico });
  });
  value.meta.estadisticas.series = [serie('medico', cats)];
  expect(validarEstadisticasReporte(value.meta, value.data).series[0].categorias).toHaveLength(12);
  [cats[0], cats[1]] = [cats[1], cats[0]];
  expect(() => validarEstadisticasReporte(value.meta, value.data)).toThrow();
});
it.each([
  ['2026-01-01', '2026-01-01', 'diaria', 1], ['2026-01-01', '2026-01-31', 'diaria', 31],
  ['2026-01-20', '2026-02-20', 'mensual', 2], ['2024-02-29', '2025-02-28', 'mensual', 13],
])('valida intervalos completos diarios/mensuales %s–%s', (desde, hasta, agrupacion, cantidad) => {
  const value = pacientesReporte({ desde, hasta }, 2), s = value.meta.estadisticas.series[1];
  expect(validarEstadisticasReporte(value.meta, value.data)).toBe(value.meta.estadisticas);
  expect(s.agrupacion).toBe(agrupacion); expect(s.categorias).toHaveLength(cantidad);
  expect(s.categorias.at(-1).intervalo.hasta).toBe(hasta);
});
it.each(['hueco', 'fin', 'utc', 'parcial', 'orden', 'faltante', 'agrupacion', 'periodo'])('rechaza período alterado: %s', tipo => {
  const value = pacientesReporte({ desde: '2026-01-20', hasta: '2026-02-20' }, 2), s = value.meta.estadisticas.series[1];
  if (tipo === 'hueco') s.categorias[1].intervalo.desde = '2026-02-02';
  if (tipo === 'fin') s.categorias[1].intervalo.hasta = '2026-02-21';
  if (tipo === 'utc') s.categorias[0].intervalo.inicio_at = '2026-01-20T00:00:00.000000Z';
  if (tipo === 'parcial') s.categorias[0].intervalo.parcial = false;
  if (tipo === 'orden') s.categorias.reverse();
  if (tipo === 'faltante') s.categorias.pop();
  if (tipo === 'agrupacion') s.agrupacion = 'diaria';
  if (tipo === 'periodo') value.meta.periodo.inicio_at = '2026-01-20T00:00:00.000000Z';
  expect(() => validarEstadisticasReporte(value.meta, value.data)).toThrow();
});
it.each(['minutos', 'estado excluido', 'orden estados', 'falta cero', 'actividad', 'serie invertida', 'denominador', 'decimal', 'porcentaje null'])('rechaza invariantes específicas: %s', tipo => {
  let value;
  if (['minutos', 'estado excluido', 'orden estados', 'falta cero'].includes(tipo)) {
    value = variantes[1][3]({ fecha: '2026-10-31', estado: 'Confirmada' }, 2);
    const s = value.meta.estadisticas.series[0];
    if (tipo === 'minutos') s.categorias[1].duracion_minutos++;
    if (tipo === 'estado excluido') { s.categorias[0].cantidad = 1; s.categorias[1].cantidad = 1; }
    if (tipo === 'orden estados') s.categorias.reverse();
    if (tipo === 'falta cero') s.categorias.pop();
  } else if (['actividad', 'serie invertida'].includes(tipo)) {
    value = pacientesReporte({ activo: '1' }, 2);
    if (tipo === 'actividad') value.meta.estadisticas.series[0].categorias.reverse(); else value.meta.estadisticas.series.reverse();
  } else {
    value = cantidadesReporte({ fecha: '2026-10-31' }); const s = value.meta.estadisticas.series[0];
    if (tipo === 'denominador') s.porcentajes.denominador = 38;
    if (tipo === 'decimal') s.categorias[0].porcentaje = '27,0';
    if (tipo === 'porcentaje null') s.categorias[0].porcentaje = null;
  }
  expect(() => validarEstadisticasReporte(value.meta, value.data)).toThrow();
});
