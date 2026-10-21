import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../api/api';
import { consultarMedico, crearMedico, actualizarMedico, cambiarEstadoMedico, cambiarEspecialidadesMedico } from './medico.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
const id = '9223372036854775807';
const version = '2026-10-21T12:00:00.123456Z';
const data = { registro_profesional: ' REG-ficticio ', nombres: ' Persona ', apellidos: ' Ficticia ', telefono: ' 70000000 ', correo_contacto: ' CONTACTO@example.test ', especialidades: [id, '2'], activo: false, id_usuario: '1', codigo_medico: 'NO ENVIAR' };
describe('datos profesionales CU-10', () => {
  it('conserva BIGINT y versión exacta, usa CSRF y limita payload de alta/edición', async () => {
    const calls = [];
    const response = { id_medico: id, actualizado_en: version };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: response } }; };
    const controller = new AbortController();
    expect(await consultarMedico(id, controller.signal)).toEqual(response);
    expect(await crearMedico(data)).toEqual(response);
    await actualizarMedico(id, { ...data, actualizado_en_original: version });
    expect(calls.map(c => [c.method, c.url])).toEqual([
      ['get', `/medicos/${id}`], ['get', csrfURL], ['post', '/medicos'], ['get', csrfURL], ['patch', `/medicos/${id}`],
    ]);
    const clean = { registro_profesional: 'REG-ficticio', nombres: 'Persona', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'contacto@example.test' };
    expect(JSON.parse(calls[2].data)).toEqual({ ...clean, especialidades: [id, '2'] });
    expect(JSON.parse(calls[4].data)).toEqual({ ...clean, actualizado_en_original: version });
    expect(calls[0].signal).toBe(controller.signal);
    for (const call of [calls[0], calls[2], calls[4]]) { expect(call.sessionBound).toBe(true); expect(call.withCredentials).toBe(true); }
  });
  it('edición parcial excluye especialidades, estado, cuenta y código', async () => {
    const calls = []; api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: {} } }; };
    await actualizarMedico(id, { telefono: ' 70000001 ', actualizado_en_original: version, especialidades: ['2'], activo: false, codigo_medico: 'NO' });
    expect(JSON.parse(calls[1].data)).toEqual({ telefono: '70000001', actualizado_en_original: version });
  });
  it.each(['0', '-1', '1/estado', '9223372036854775808'])('ID inválido %s no consulta HTTP', async value => {
    let count = 0; api.defaults.adapter = async () => { count++; };
    await expect(consultarMedico(value)).rejects.toThrow(); await expect(actualizarMedico(value, {})).rejects.toThrow(); expect(count).toBe(0);
  });
  it.each([409, 419, 422, 429, 500, undefined])('propaga error %s de alta/edición sin reenvíos', async status => {
    const calls = []; api.defaults.adapter = async config => {
      calls.push(config);
      if (config.method === 'get') return { config, status: 204, headers: {}, data: '' };
      throw new AxiosError('fallo', undefined, config, null, status ? { status, data: {} } : undefined);
    };
    await expect(crearMedico(data)).rejects.toThrow(); await expect(actualizarMedico(id, { ...data, actualizado_en_original: version })).rejects.toThrow(); expect(calls).toHaveLength(4);
  });
});

describe('actividad y habilitaciones CU-10', () => {
  it.each([false, true])('envía actividad %s y conjunto completo de IDs sin campos ajenos', async activo => {
    const calls = []; const result = { id_medico: id, actualizado_en: version };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: result } }; };
    expect(await cambiarEstadoMedico(id, { activo, actualizado_en_original: version, id_usuario: '2' })).toEqual(result);
    expect(await cambiarEspecialidadesMedico(id, { especialidades: [id, '2'], actualizado_en_original: version, activo: true })).toEqual(result);
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['patch', `/medicos/${id}/estado`], ['get', csrfURL], ['put', `/medicos/${id}/especialidades`]]);
    expect(JSON.parse(calls[1].data)).toEqual({ activo, actualizado_en_original: version });
    expect(JSON.parse(calls[3].data)).toEqual({ especialidades: [id, '2'], actualizado_en_original: version });
    for (const call of [calls[1], calls[3]]) { expect(call.sessionBound).toBe(true); expect(call.withCredentials).toBe(true); }
  });
  it.each(['0', '1/estado', '9223372036854775808'])('ID inválido %s rechaza antes de CSRF/escritura', async value => {
    let count = 0; api.defaults.adapter = async () => { count++; };
    await expect(cambiarEstadoMedico(value, {})).rejects.toThrow(); await expect(cambiarEspecialidadesMedico(value, { especialidades: ['1'] })).rejects.toThrow(); expect(count).toBe(0);
  });
  it.each([409, 419, 422, 429, 500, undefined])('error %s no repite escrituras de estado ni conjunto', async status => {
    const calls = []; api.defaults.adapter = async config => {
      calls.push(config); if (config.method === 'get') return { config, status: 204, headers: {}, data: '' };
      throw new AxiosError('fallo', undefined, config, null, status ? { status, data: {} } : undefined);
    };
    await expect(cambiarEstadoMedico(id, { activo: false, actualizado_en_original: version })).rejects.toThrow();
    await expect(cambiarEspecialidadesMedico(id, { especialidades: [id], actualizado_en_original: version })).rejects.toThrow(); expect(calls).toHaveLength(4);
  });
});
