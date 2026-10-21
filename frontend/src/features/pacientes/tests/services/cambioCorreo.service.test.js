import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { solicitarCambioCorreo, reenviarCambioCorreo, verificarCambioCorreo } from '../../services/cambioCorreo.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
function capture(envio = 'aceptado') {
  const calls = [];
  api.defaults.adapter = async config => { calls.push(config); return { config, status: config.url.endsWith('/verificar') ? 204 : 202, headers: {}, data: { data: { envio, expira_en: '2026-10-20T12:00:00.123456Z' } } }; };
  return calls;
}
describe('contrato de cambio de correo', () => {
  it.each(['aceptado', 'simulado', 'fallido', 'pendiente'])('solicitud reconoce %s sin enviar IDs o campos adicionales', async envio => {
    const calls = capture(envio); const result = await solicitarCambioCorreo({ correo_acceso: ' Nuevo@Example.test ', current_password: ' clave privada ', id_usuario: '99', correo_contacto: 'excluir' });
    expect(result).toEqual({ envio, expira_en: '2026-10-20T12:00:00.123456Z' });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['post', '/mi-perfil/correo']]);
    expect(JSON.parse(calls[1].data)).toEqual({ correo_acceso: 'nuevo@example.test', current_password: ' clave privada ' });
    expect(calls[1]).toMatchObject({ sessionBound: true, withCredentials: true, withXSRFToken: true });
  });
  it('reenvío deriva solicitud/destino del servidor sin parámetros', async () => {
    const calls = capture(); await reenviarCambioCorreo({ correo_acceso: 'excluir', codigo: 'excluir' });
    expect(calls[1].url).toBe('/mi-perfil/correo/reenviar'); expect(JSON.parse(calls[1].data)).toEqual({});
  });
  it('verificación conserva ceros iniciales y exige 204', async () => {
    const calls = capture(); await verificarCambioCorreo({ codigo: '001234', correo_acceso: 'excluir', current_password: 'excluir' });
    expect(calls[1].url).toBe('/mi-perfil/correo/verificar'); expect(JSON.parse(calls[1].data)).toEqual({ codigo: '001234' });
    expect(calls[1].sessionBound).toBe(true);
  });
  it.each([409, 419, 422, 429, 500])('no reenvía escrituras ante %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); if (config.method === 'post') throw new AxiosError('fallo', undefined, config, null, { status }); return { config, status: 204, data: null, headers: {} }; };
    await expect(verificarCambioCorreo({ codigo: '001234' })).rejects.toThrow(); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
  });
  it('respuesta de transporte desconocida no se presenta como aceptada', async () => {
    capture('desconocido'); await expect(reenviarCambioCorreo()).rejects.toThrow('no confirmada');
  });
  it('200 no acredita confirmación de cambio', async () => {
    api.defaults.adapter = async config => ({ config, status: 200, headers: {}, data: {} });
    await expect(verificarCambioCorreo({ codigo: '001234' })).rejects.toThrow('no confirmado');
  });
});
