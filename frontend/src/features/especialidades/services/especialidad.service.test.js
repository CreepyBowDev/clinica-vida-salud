import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../api/api';
import { listarEspecialidades, consultarEspecialidad, crearEspecialidad, actualizarEspecialidad, cambiarEstadoEspecialidad } from './especialidad.service';
import { listarMedicos } from '../../medicos/services/medico.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
describe('contratos de listados CU-09/CU-10', () => {
  it.each([false, '0', true, '1'])('conserva estados %s, BIGINT, microsegundos, cookies y solo filtros permitidos', async state => {
    const calls = [];
    const payload = { data: [{ id_especialidad: '9223372036854775807', actualizado_en: '2026-10-21T12:00:00.123456Z' }], meta: { total: 1 } };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: payload }; };
    const controller = new AbortController();
    expect(await listarEspecialidades({ nombre: ' Geriatría ', activa: state, page: 2, per_page: 10, password: 'excluir' }, controller.signal)).toEqual(payload);
    await listarMedicos({ nombre: ' Persona ', activo: state, id_especialidad: '9223372036854775807', page: 3, per_page: 20, cuenta_vinculada: true }, controller.signal);
    expect(calls.map(c => [c.url, c.params])).toEqual([
      ['/especialidades', { nombre: 'Geriatría', activa: state, page: 2, per_page: 10 }],
      ['/medicos', { nombre: 'Persona', activo: state, id_especialidad: '9223372036854775807', page: 3, per_page: 20 }],
    ]);
    for (const call of calls) { expect(call.signal).toBe(controller.signal); expect(call.withCredentials).toBe(true); expect(call.sessionBound).toBe(true); expect(call.method).toBe('get'); }
  });
  it.each([undefined, null, ''])('omite filtros vacíos %s y no confunde consulta administrativa con disponibilidad', async state => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: [], meta: {} } }; };
    await listarEspecialidades({ nombre: ' ', activa: state });
    await listarMedicos({ nombre: '', activo: state, id_especialidad: '' });
    expect(calls.map(c => c.params)).toEqual([{ page: 1, per_page: 20 }, { page: 1, per_page: 20 }]);
  });
  it.each([403, 419, 422, 429, 500])('propaga %s sin reintentos ni peticiones a enlaces de paginación ajenos', async status => {
    let count = 0;
    api.defaults.adapter = async config => { count++; throw new AxiosError('fallo', undefined, config, null, { status, data: {} }); };
    await expect(listarEspecialidades()).rejects.toThrow();
    await expect(listarMedicos()).rejects.toThrow();
    expect(count).toBe(2);
  });
});

describe('contratos de escritura de especialidades', () => {
  const id = '9223372036854775807';
  const version = '2026-10-21T12:00:00.123456Z';
  it('consulta detalle, prepara CSRF y envía solo campos permitidos sin convertir ID ni versión', async () => {
    const calls = [];
    const specialty = { id_especialidad: id, actualizado_en: version };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: specialty } }; };
    expect(await consultarEspecialidad(id)).toEqual(specialty);
    expect(await crearEspecialidad({ nombre: ' Geriatría ', descripcion: ' ', activa: false })).toEqual(specialty);
    await actualizarEspecialidad(id, { nombre: ' Nuevo ', descripcion: '', actualizado_en_original: version, activa: false });
    await cambiarEstadoEspecialidad(id, { activa: false, actualizado_en_original: version, nombre: 'excluir' });
    expect(calls.map(c => [c.method, c.url])).toEqual([
      ['get', `/especialidades/${id}`], ['get', csrfURL], ['post', '/especialidades'],
      ['get', csrfURL], ['patch', `/especialidades/${id}`], ['get', csrfURL], ['patch', `/especialidades/${id}/estado`],
    ]);
    expect(JSON.parse(calls[2].data)).toEqual({ nombre: 'Geriatría', descripcion: null });
    expect(JSON.parse(calls[4].data)).toEqual({ nombre: 'Nuevo', descripcion: null, actualizado_en_original: version });
    expect(JSON.parse(calls[6].data)).toEqual({ activa: false, actualizado_en_original: version });
    for (const call of [calls[0], calls[2], calls[4], calls[6]]) expect(call.sessionBound).toBe(true);
  });
  it.each(['0', '-1', '1/estado', '9223372036854775808'])('rechaza ID inválido %s antes de cualquier HTTP', async invalid => {
    let count = 0; api.defaults.adapter = async () => { count++; };
    await expect(consultarEspecialidad(invalid)).rejects.toThrow();
    await expect(actualizarEspecialidad(invalid, {})).rejects.toThrow();
    await expect(cambiarEstadoEspecialidad(invalid, {})).rejects.toThrow();
    expect(count).toBe(0);
  });
  it.each([409, 419, 422, 429, 500, undefined])('no reenvía alta ante error %s de escritura', async status => {
    const calls = [];
    api.defaults.adapter = async config => {
      calls.push(config);
      if (config.method === 'get') return { config, status: 204, headers: {}, data: '' };
      throw new AxiosError('fallo', undefined, config, null, status ? { status, data: {} } : undefined);
    };
    await expect(crearEspecialidad({ nombre: 'Geriatría' })).rejects.toThrow();
    expect(calls).toHaveLength(2);
  });
});
