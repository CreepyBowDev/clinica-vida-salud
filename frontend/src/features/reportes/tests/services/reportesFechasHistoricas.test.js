import { afterEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { validarEstadisticasReporte } from '../../services/estadisticasReporte';
import { consultarPacientesRegistrados } from '../../services/reporte.service';
import { variantes } from '../utils/variantesEstadisticas';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { categoria, serie } from '../utils/estadisticasFixtures';

afterEach(() => vi.restoreAllMocks());
// Fronteras esperadas de PHP DateTimeImmutable/America/La_Paz, no del validador React.
// La transición de octubre de 1931 normaliza una medianoche inexistente a la 01:00 local.
const dias = [
  ['0001-01-01', '0001-01-01T04:32:36.000000Z', '0001-01-02T04:32:36.000000Z'],
  ['1900-01-01', '1900-01-01T04:32:36.000000Z', '1900-01-02T04:32:36.000000Z'],
  ['1931-10-15', '1931-10-15T04:32:36.000000Z', '1931-10-16T03:32:36.000000Z'],
  ['1932-03-20', '1932-03-20T03:32:36.000000Z', '1932-03-21T04:00:00.000000Z'],
  ['9999-12-30', '9999-12-30T04:00:00.000000Z', '9999-12-31T04:00:00.000000Z'],
];
it.each(variantes)('%s: acepta fronteras históricas y extremas en el servicio real con transporte simulado', async (tipo, load, seleccion, fixture) => {
  const get = vi.spyOn(api, 'get');
  for (const [fecha, inicio_at, fin_exclusivo_at] of dias) {
    const params = { fecha, ...seleccion }, value = fixture(params, 0);
    Object.assign(value.meta.periodo, { inicio_at, fin_exclusivo_at });
    for (const s of value.meta.estadisticas.series.filter(s => s.dimension === 'periodo')) {
      Object.assign(s.categorias[0].intervalo, { inicio_at, fin_exclusivo_at });
    }
    get.mockResolvedValue({ data: value });
    const result = await load(params);
    expect(result.meta.periodo).toEqual(value.meta.periodo);
    expect(result.meta.estadisticas).toEqual(value.meta.estadisticas);
    expect(result.meta.periodo.inicio_at).toBe(inicio_at);
  }
  expect(get).toHaveBeenCalledTimes(dias.length);
});

const meses = [
  ['0004-02-01', '0004-03-03', [
    ['0004-02-01', '0004-02-29', '0004-02-01T04:32:36.000000Z', '0004-03-01T04:32:36.000000Z', false],
    ['0004-03-01', '0004-03-03', '0004-03-01T04:32:36.000000Z', '0004-03-04T04:32:36.000000Z', true],
  ]],
  ['0099-12-20', '0100-01-20', [
    ['0099-12-20', '0099-12-31', '0099-12-20T04:32:36.000000Z', '0100-01-01T04:32:36.000000Z', true],
    ['0100-01-01', '0100-01-20', '0100-01-01T04:32:36.000000Z', '0100-01-21T04:32:36.000000Z', true],
  ]],
];
function mensual(desde, hasta, intervalos) {
  const value = pacientesReporte({}, 0);
  Object.assign(value.meta.filtros, { fecha: null, desde, hasta });
  Object.assign(value.meta.periodo, { desde, hasta, inicio_at: intervalos[0][2], fin_exclusivo_at: intervalos.at(-1)[3] });
  value.meta.estadisticas.series[1] = serie('periodo', intervalos.map(([desde, hasta, inicio_at, fin_exclusivo_at, parcial]) => {
    const mes = desde.slice(0, 7);
    return categoria(`mes:${mes}`, mes + (parcial ? ' (parcial)' : ''), 0,
      { intervalo: { desde, hasta, inicio_at, fin_exclusivo_at, parcial } });
  }), 'mensual');
  return value;
}
it.each(meses)('valida meses reales, bisiestos y límites de siglo %s–%s sin sumar 1900 al año', async (desde, hasta, intervalos) => {
  const value = mensual(desde, hasta, intervalos);
  vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  const result = await consultarPacientesRegistrados({ desde, hasta });
  expect(result.meta.estadisticas.series[1]).toEqual(value.meta.estadisticas.series[1]);
  expect(result.meta.estadisticas.series[1].categorias).toHaveLength(2);
});
it.each(['offset fijo', 'día incorrecto', 'hora interior', 'microsegundos', 'serie interior', 'fin incorrecto'])('sigue rechazando fronteras falsas: %s', tipo => {
  const [desde, hasta, intervalos] = meses[0], value = mensual(desde, hasta, intervalos);
  if (tipo === 'offset fijo') value.meta.periodo.inicio_at = '0004-02-01T04:00:00.000000Z';
  if (tipo === 'día incorrecto') value.meta.periodo.inicio_at = '0004-02-02T04:32:36.000000Z';
  if (tipo === 'hora interior') value.meta.periodo.inicio_at = '0004-02-01T05:32:36.000000Z';
  if (tipo === 'microsegundos') value.meta.periodo.inicio_at = '0004-02-01T04:32:36.000001Z';
  if (tipo === 'serie interior') value.meta.estadisticas.series[1].categorias[1].intervalo.inicio_at = '0004-03-01T05:32:36.000000Z';
  if (tipo === 'fin incorrecto') value.meta.periodo.fin_exclusivo_at = '0004-03-03T04:32:36.000000Z';
  expect(() => validarEstadisticasReporte(value.meta, value.data)).toThrow('No se pudo verificar el reporte.');
});
