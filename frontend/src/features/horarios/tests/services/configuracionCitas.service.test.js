import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { consultarConfiguracionCitas, consultarImpactoDuracion, actualizarDuracion } from '../../services/configuracionCitas.service';
const original = api.defaults.adapter, version = '2026-10-22T12:00:00.123456Z';
let calls;
beforeEach(() => {
  calls = [];
  api.defaults.adapter = vi.fn(async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { duracion_turno_minutos: 15 }, meta: { total: 0 } } }; });
});
afterEach(() => { api.defaults.adapter = original; });
it('consulta singleton sin parámetros inventados con sesión y cancelación', async () => {
  const signal = new AbortController().signal;
  expect(await consultarConfiguracionCitas(signal)).toEqual({ duracion_turno_minutos: 15 });
  expect(calls[0]).toMatchObject({ method: 'get', url: '/configuracion-citas', signal, sessionBound: true }); expect(calls[0].params).toBeUndefined();
});
it('impacto repite POST del cuerpo cerrado con paginación y CSRF', async () => {
  const signal = new AbortController().signal;
  const result = await consultarImpactoDuracion({ duracion_turno_minutos: 20, actualizado_en_original: version, page: 3, per_page: 10, id_medico: '2', huella_impacto: 'No enviar' }, signal);
  expect(calls[0]).toMatchObject({ url: csrfURL, signal });
  expect(calls[1]).toMatchObject({ method: 'post', url: '/configuracion-citas/impacto', sessionBound: true, signal });
  expect(JSON.parse(calls[1].data)).toEqual({ duracion_turno_minutos: 20, actualizado_en_original: version, page: 3, per_page: 10 }); expect(result.meta.total).toBe(0);
});
it('edición usa PATCH solo con duración y versión global exacta', async () => {
  await actualizarDuracion({ duracion_turno_minutos: 2147483647, actualizado_en_original: version, zona_horaria: 'UTC', activo: true, page: 1 });
  expect(calls[0].url).toBe(csrfURL); expect(calls[1]).toMatchObject({ method: 'patch', url: '/configuracion-citas', sessionBound: true });
  expect(JSON.parse(calls[1].data)).toEqual({ duracion_turno_minutos: 2147483647, actualizado_en_original: version });
});
it('tamaño y página predeterminados del impacto', async () => {
  await consultarImpactoDuracion({ duracion_turno_minutos: 20, actualizado_en_original: version }); expect(JSON.parse(calls[1].data)).toMatchObject({ page: 1, per_page: 20 });
});
it('CSRF fallido impide PATCH sin reintentar', async () => {
  api.defaults.adapter = vi.fn(async () => { throw Error('Sin conexión'); });
  await expect(actualizarDuracion({ duracion_turno_minutos: 20, actualizado_en_original: version })).rejects.toThrow(); expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});
it('PATCH incierto no reenvía operación', async () => {
  api.defaults.adapter = vi.fn(async config => { calls.push(config); if (config.method === 'patch') throw Error('Sin conexión'); return { config, status: 204, headers: {}, data: '' }; });
  await expect(actualizarDuracion({ duracion_turno_minutos: 20, actualizado_en_original: version })).rejects.toThrow(); expect(calls.map(c => c.method)).toEqual(['get', 'patch']);
});
