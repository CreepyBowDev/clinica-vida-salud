import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { consultarMiPerfil, actualizarMiPerfil } from '../../services/perfil.service';

const original = api.defaults.adapter;
const version = '2026-10-20T10:00:00.123456Z';
afterEach(() => { api.defaults.adapter = original; });
function capture() {
  const calls = [];
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { id_paciente: '9223372036854775807', actualizado_en: version } } }; };
  return calls;
}
describe('contrato del perfil propio', () => {
  it('consulta sin ID ni filtros y conserva BIGINT, versión, cookies y señal', async () => {
    const calls = capture(), controller = new AbortController(); const data = await consultarMiPerfil(controller.signal);
    expect(data).toEqual({ id_paciente: '9223372036854775807', actualizado_en: version });
    expect(calls[0]).toMatchObject({ method: 'get', url: '/mi-perfil', signal: controller.signal, sessionBound: true, withCredentials: true });
    expect(calls[0].params).toBeUndefined();
  });
  it('excluye identidad, IDs, estado, clínica, correo de acceso y credenciales', async () => {
    const calls = capture();
    await actualizarMiPerfil({ actualizado_en_original: version, telefono: '71111111', correo_contacto: null, direccion: null, recordatorios_habilitados: false,
      contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '72222222', id_paciente: '99' },
      id_paciente: '99', id_usuario: '88', documento_identidad: '123', nombres: 'Excluir', apellidos: 'Excluir', fecha_nacimiento: '2000-01-01', sexo: 'Excluir', activo: true, correo_acceso: 'otro@example.test', password: 'excluir', antecedentes: 'excluir', motivo: 'excluir', identidad_verificada: true });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['patch', '/mi-perfil']]);
    expect(JSON.parse(calls[1].data)).toEqual({ actualizado_en_original: version, telefono: '71111111', correo_contacto: null, direccion: null, recordatorios_habilitados: false, contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '72222222' } });
    expect(calls[1]).toMatchObject({ sessionBound: true, withCredentials: true, withXSRFToken: true });
  });
  it('no inventa preferencias en actualización parcial y permite retirar emergencia con NULL', async () => {
    const calls = capture(); await actualizarMiPerfil({ actualizado_en_original: version, contacto_emergencia: null });
    expect(JSON.parse(calls[1].data)).toEqual({ actualizado_en_original: version, contacto_emergencia: null });
  });
  it.each([undefined, '2026-10-20T10:00:00.123Z'])('rechaza versión incompleta %s antes de HTTP', async actualizado_en_original => {
    const calls = capture(); await expect(actualizarMiPerfil({ actualizado_en_original })).rejects.toThrow(); expect(calls).toHaveLength(0);
  });
  it.each([409, 419, 500])('no reenvía PATCH ante %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); if (config.method === 'patch') throw new AxiosError('fallo', undefined, config, null, { status }); return { config, status: 204, headers: {}, data: null }; };
    await expect(actualizarMiPerfil({ actualizado_en_original: version, telefono: '71111111' })).rejects.toThrow();
    expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  });
  it('respuesta no confirmada no declara éxito', async () => {
    api.defaults.adapter = async config => ({ config, status: 200, headers: {}, data: {} });
    await expect(actualizarMiPerfil({ actualizado_en_original: version, telefono: '71111111' })).rejects.toThrow('no confirmada');
  });
});
