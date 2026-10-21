import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { consultarPaciente, listarPacientes, crearPaciente, actualizarPaciente, cambiarEstadoPaciente } from '../../services/paciente.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
function capture() {
  const calls = [];
  api.defaults.adapter = async config => {
    calls.push(config);
    return { config, status: 200, headers: {}, data: { data: { id_paciente: '9223372036854775807', actualizado_en: '2026-10-20T10:00:00.123456Z' } } };
  };
  return calls;
}

describe('contratos de consulta de pacientes', () => {
  it.each([false, '0', true, '1'])('envía estado %s sin perder el filtro inactivo y excluye campos ajenos', async activo => {
    const calls = capture();
    const controller = new AbortController();
    await listarPacientes({ documento_identidad: '1234', nombres: 'Persona', apellidos: 'Ficticia', activo, page: 2, per_page: 20, diagnostico: 'excluir' }, controller.signal);
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toBe('/pacientes');
    expect(calls[0].params).toEqual({ documento_identidad: '1234', nombres: 'Persona', apellidos: 'Ficticia', activo, page: 2, per_page: 20 });
    expect(calls[0].signal).toBe(controller.signal);
    expect(calls[0].sessionBound).toBe(true);
  });
  it('omite filtros vacíos, mantiene cookies y conserva BIGINT y microsegundos del detalle', async () => {
    const calls = capture();
    await listarPacientes({ documento_identidad: '', nombres: '', apellidos: '', activo: '' });
    expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
    const controller = new AbortController();
    const patient = await consultarPaciente('9223372036854775807', controller.signal);
    expect(patient).toEqual({ id_paciente: '9223372036854775807', actualizado_en: '2026-10-20T10:00:00.123456Z' });
    expect(calls[1].url).toBe('/pacientes/9223372036854775807');
    expect(calls[1].withCredentials).toBe(true);
    expect(calls[1].sessionBound).toBe(true);
    expect(calls[1].signal).toBe(controller.signal);
  });
  it.each(['0', '01', '-1', '1/estado', '9223372036854775808'])('rechaza ID %s antes de HTTP', async id => {
    const calls = capture();
    await expect(consultarPaciente(id)).rejects.toThrow();
    expect(calls).toHaveLength(0);
  });
});

describe('edición y estado con precondición exacta', () => {
  const id = '9223372036854775807';
  const version = '2026-10-20T10:00:00.123456Z';
  it('PATCH administrativo conserva microsegundos, nulabilidad y solo campos explícitos permitidos', async () => {
    const calls = capture();
    await actualizarPaciente(id, { actualizado_en_original: version, documento_identidad: '1234', motivo: 'Corrección presencial', identidad_verificada: true, contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '71111111', id_paciente: '99' }, correo_contacto: null, activo: false, id_usuario: '99', password: 'excluir', antecedentes: 'excluir', recordatorios_habilitados: true });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['patch', `/pacientes/${id}`]]);
    expect(JSON.parse(calls[1].data)).toEqual({ actualizado_en_original: version, documento_identidad: '1234', motivo: 'Corrección presencial', identidad_verificada: true, correo_contacto: null, contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '71111111' } });
    expect(calls[1].sessionBound).toBe(true);
  });
  it('PATCH contacto parcial no envía identidad/estado ni valores predeterminados', async () => {
    const calls = capture(); await actualizarPaciente(id, { actualizado_en_original: version, telefono: '70000000', contacto_emergencia: null });
    expect(JSON.parse(calls[1].data)).toEqual({ actualizado_en_original: version, telefono: '70000000', contacto_emergencia: null });
  });
  it.each([true, false])('estado %s envía únicamente estado, motivo y versión', async activo => {
    const calls = capture(); await cambiarEstadoPaciente(id, { activo, motivo: 'Revisión administrativa', actualizado_en_original: version, password: 'excluir' });
    expect(calls[1].url).toBe(`/pacientes/${id}/estado`);
    expect(JSON.parse(calls[1].data)).toEqual({ activo, motivo: 'Revisión administrativa', actualizado_en_original: version });
    expect(calls[1].sessionBound).toBe(true);
  });
  it.each(['2026-10-20T10:00:00.123Z', undefined])('rechaza precondición %s antes de CSRF o escritura', async actualizado_en_original => {
    const calls = capture(); await expect(actualizarPaciente(id, { actualizado_en_original })).rejects.toThrow();
    await expect(cambiarEstadoPaciente(id, { actualizado_en_original })).rejects.toThrow(); expect(calls).toHaveLength(0);
  });
  it.each([409, 419, 500])('no reintenta PATCH ni altera versión ante %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); if (config.method === 'patch') throw new AxiosError('fallo', undefined, config, null, { status }); return { config, status: 204, data: null, headers: {} }; };
    await expect(actualizarPaciente(id, { actualizado_en_original: version, telefono: '70000000' })).rejects.toThrow();
    expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(JSON.parse(calls[1].data).actualizado_en_original).toBe(version);
  });
});

describe('alta administrativa de pacientes', () => {
  const draft = { documento_identidad: ' 1234 ', nombres: ' Persona ', apellidos: ' Ficticia ', fecha_nacimiento: '1990-01-02', sexo: '', telefono: ' 70000000 ', correo_contacto: '', direccion: '', contacto_emergencia: null };
  it.each([true, false])('envía exclusivamente campos administrativos, con CI: %s', async withDocument => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); return { config, status: config.method === 'post' ? 201 : 204, headers: {}, data: { data: { id_paciente: '9223372036854775807' } } }; };
    const result = await crearPaciente({ ...draft, documento_identidad: withDocument ? draft.documento_identidad : '', posibles_duplicados_revisados: true,
      id_paciente: '2', id_usuario: '2', activo: false, password: 'excluir', antecedentes: 'excluir', recordatorios_habilitados: true,
      contacto_emergencia: { nombre: ' Contacto Ficticio ', parentesco: ' Hermana ', telefono: ' 71111111 ', id_paciente: '2' } });
    expect(result.id_paciente).toBe('9223372036854775807');
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['post', '/pacientes']]);
    expect(calls[1].sessionBound).toBe(true);
    expect(calls[1].withCredentials).toBe(true);
    expect(JSON.parse(calls[1].data)).toEqual({ documento_identidad: withDocument ? '1234' : null, nombres: 'Persona', apellidos: 'Ficticia', fecha_nacimiento: '1990-01-02', sexo: null, telefono: '70000000', correo_contacto: null, direccion: null,
      contacto_emergencia: { nombre: 'Contacto Ficticio', parentesco: 'Hermana', telefono: '71111111' }, ...(!withDocument ? { posibles_duplicados_revisados: true } : {}) });
  });
  it.each([419, 409, 500, undefined])('no reenvía automáticamente un POST con fallo %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); if (config.method === 'post') throw new AxiosError('fallo', undefined, config, null, status ? { status } : undefined); return { config, status: 204, headers: {}, data: null }; };
    await expect(crearPaciente(draft)).rejects.toThrow();
    expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
  });
  it('no envía alta cuando no se pudo renovar CSRF', async () => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); throw new Error('Sin conexión'); };
    await expect(crearPaciente(draft)).rejects.toThrow();
    expect(calls.map(c => c.url)).toEqual([csrfURL]);
  });
  it('no declara éxito por una respuesta incompleta o un estado distinto de 201', async () => {
    api.defaults.adapter = async config => ({ config, status: 200, headers: {}, data: { data: { id_paciente: '17' } } });
    await expect(crearPaciente(draft)).rejects.toThrow('Alta no confirmada');
    api.defaults.adapter = async config => ({ config, status: 201, headers: {}, data: { data: {} } });
    await expect(crearPaciente(draft)).rejects.toThrow('Alta no confirmada');
  });
});
