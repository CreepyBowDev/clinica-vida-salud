import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { listarConsultorios, consultarConsultorio, crearConsultorio, actualizarConsultorio, cambiarEstadoConsultorio } from '../../services/consultorio.service';

const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-22T12:00:00.123456Z';
let calls;
beforeEach(() => {
  calls = [];
  api.defaults.adapter = vi.fn(async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { id_consultorio: id } } }; });
});
afterEach(() => { api.defaults.adapter = original; });
it('envía filtros permitidos y cancelación sin alterar código ni IDs', async () => {
  const signal = new AbortController().signal;
  await listarConsultorios({ q: ' ABC_% ', activo: '0', page: 2, per_page: 50, rol: 'Administrador' }, signal);
  expect(calls[0].params).toEqual({ q: 'ABC_%', activo: '0', page: 2, per_page: 50 });
  expect(calls[0].signal).toBe(signal); expect(calls[0].sessionBound).toBe(true);
  await consultarConsultorio(id, signal); expect(calls[1].url).toBe(`/consultorios/${id}`);
});
it('omite búsqueda vacía y estado no seleccionado', async () => {
  await listarConsultorios({ q: ' ', activo: '' }); expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
});
it.each(['0', '-1', '9223372036854775808', '1/estado', undefined])('rechaza ID %s sin peticiones HTTP', async value => {
  await expect(consultarConsultorio(value)).rejects.toThrow();
  await expect(actualizarConsultorio(value, {})).rejects.toThrow();
  await expect(cambiarEstadoConsultorio(value, {})).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('alta cerrada, trim sin normalización por mayúsculas y CSRF antes de POST', async () => {
  await crearConsultorio({ codigo_consultorio: ' Mi-Codigo ', nombre: ' Sala ', activo: false });
  expect(calls[0].url).toBe(csrfURL); expect(calls[1].method).toBe('post');
  expect(JSON.parse(calls[1].data)).toEqual({ codigo_consultorio: 'Mi-Codigo', nombre: 'Sala' }); expect(calls[1].sessionBound).toBe(true);
});
it('edición parcial y estado preservan versión de seis decimales y booleano', async () => {
  await actualizarConsultorio(id, { nombre: ' Corregido ', actualizado_en_original: version, activo: true });
  expect(JSON.parse(calls[1].data)).toEqual({ nombre: 'Corregido', actualizado_en_original: version });
  await cambiarEstadoConsultorio(id, { activo: false, actualizado_en_original: version, nombre: 'No enviar' });
  expect(calls[3].url).toBe(`/consultorios/${id}/estado`);
  expect(JSON.parse(calls[3].data)).toEqual({ activo: false, actualizado_en_original: version });
});
it('fallo de CSRF no envía ni reintenta la escritura', async () => {
  api.defaults.adapter = vi.fn(async () => { throw new Error('Sin conexión'); });
  await expect(crearConsultorio({ codigo_consultorio: 'A', nombre: 'Sala' })).rejects.toThrow();
  expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});
