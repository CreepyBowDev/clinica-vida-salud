import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../../services/auth.service';

const originalAdapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = originalAdapter; });

describe('contrato HTTP de acceso', () => {
  it('obtiene CSRF antes del login y envía solo credenciales sin alterar la contraseña', async () => {
    const calls = [];
    api.defaults.adapter = async (config) => {
      calls.push(config);
      return { data: { data: { id_usuario: '123', rol: 'Paciente' } }, status: 200, headers: {}, config };
    };
    const user = await iniciarSesion({ correo_acceso: 'persona@example.test', password: '  frase privada  ', rol: 'Administrador' });
    expect(calls.map(({ method, url }) => [method, url])).toEqual([['get', csrfURL], ['post', '/auth/login']]);
    expect(JSON.parse(calls[1].data)).toEqual({ correo_acceso: 'persona@example.test', password: '  frase privada  ' });
    expect(calls[1].withCredentials).toBe(true);
    expect(calls[1].withXSRFToken).toBe(true);
    expect(calls[1].headers.Accept).toBe('application/json');
    expect(user.rol).toBe('Paciente');
  });

  it('no envía credenciales cuando falla la obtención de CSRF', async () => {
    const calls = [];
    api.defaults.adapter = async (config) => { calls.push(config.url); throw new AxiosError('red'); };
    await expect(iniciarSesion({ correo_acceso: 'persona@example.test', password: 'privada' })).rejects.toThrow();
    expect(calls).toEqual([csrfURL]);
  });

  it('no reintenta una escritura rechazada por CSRF', async () => {
    const calls = [];
    api.defaults.adapter = async (config) => {
      calls.push(config.url);
      if (config.method === 'post') throw new AxiosError('CSRF', undefined, config, null, { status: 419 });
      return { data: null, status: 204, headers: {}, config };
    };
    await expect(iniciarSesion({ correo_acceso: 'persona@example.test', password: 'privada' })).rejects.toThrow();
    expect(calls).toEqual([csrfURL, '/auth/login']);
  });

  it('consulta la sesión sin escribir y espera confirmación del logout', async () => {
    const calls = [];
    api.defaults.adapter = async (config) => {
      calls.push([config.method, config.url]);
      return { data: { data: { cambio_clave_obligatorio: true, acceso: 'cambio_clave' } }, status: 200, headers: {}, config };
    };
    expect(await consultarSesion()).toEqual({ cambio_clave_obligatorio: true, acceso: 'cambio_clave' });
    await cerrarSesion();
    expect(calls).toEqual([['get', '/auth/me'], ['get', csrfURL], ['post', '/auth/logout']]);
  });
});
