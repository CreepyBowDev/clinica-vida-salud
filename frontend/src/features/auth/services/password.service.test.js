import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../api/api';
import { cambiarClave, completarClaveTemporal, restablecerClave, solicitarRecuperacion } from './password.service';

const adapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = adapter; });

describe('contratos de contraseñas', () => {
  it('completa desde la sesión restringida sin ID, token ni contraseña anterior', async () => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config); return { config, data: null, status: 204, headers: {} }; };
    await completarClaveTemporal({ password: ' Nueva definitiva 123 ', password_confirmation: ' Nueva definitiva 123 ', id_usuario: '999', current_password: 'no enviar' });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['put', '/auth/password/temporal']]);
    expect(JSON.parse(calls[1].data)).toEqual({ password: ' Nueva definitiva 123 ', password_confirmation: ' Nueva definitiva 123 ' });
    expect(calls[1].sessionBound).toBe(true);
  });
  it.each([
    [cambiarClave, 'put', '/auth/password', { current_password: ' actual ', password: ' Nueva frase ficticia 123 ', password_confirmation: ' Nueva frase ficticia 123 ' }, true],
    [solicitarRecuperacion, 'post', '/auth/password/forgot', { correo_acceso: 'persona@example.test' }, false],
    [restablecerClave, 'post', '/auth/password/reset', { token: 'a'.repeat(64), password: ' Nueva frase ficticia 123 ', password_confirmation: ' Nueva frase ficticia 123 ' }, false],
  ])('renueva CSRF y respeta campos/contrato %s', async (service, method, url, data, bound) => {
    const calls = [];
    api.defaults.adapter = async (config) => { calls.push(config); return { config, data: null, status: 204, headers: {} }; };
    await service({ ...data, rol: 'Administrador', id_usuario: '999' });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], [method, url]]);
    expect(JSON.parse(calls[1].data)).toEqual(data);
    expect(Boolean(calls[1].sessionBound)).toBe(bound);
    expect(calls[1].withCredentials).toBe(true);
    expect(calls[1].withXSRFToken).toBe(true);
    expect(calls[1].url).not.toContain('token');
  });

  it.each([cambiarClave, solicitarRecuperacion, restablecerClave])('no escribe si falla CSRF', async service => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config.url); throw new AxiosError('network'); };
    await expect(service({})).rejects.toThrow();
    expect(calls).toEqual([csrfURL]);
  });

  it.each([419, 429, 500])('no reintenta un reset con HTTP %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => {
      calls.push(config.url);
      if (config.method === 'post') throw new AxiosError('error', undefined, config, null, { status });
      return { config, status: 204, headers: {}, data: null };
    };
    await expect(restablecerClave({ token: 'a'.repeat(64), password: 'Nueva frase 123', password_confirmation: 'Nueva frase 123' })).rejects.toThrow();
    expect(calls).toEqual([csrfURL, '/auth/password/reset']);
  });
});
