import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { cambiarEstadoCuenta, cambiarRolCuenta, consultarCuenta, crearPersonal, emitirCredencialTemporal, esIdentificador, listarCuentas, reenviarInvitacion } from '../../services/usuario.service';
import { aceptarInvitacion } from '../../../auth/services/invitation.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
function capture() {
  const calls = [];
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { id_usuario: '9223372036854775807' } } }; };
  return calls;
}

describe('contratos administrativos', () => {
  it('consulta filtros permitidos sin seguir URLs del servidor y admite cancelación', async () => {
    const calls = capture();
    const controller = new AbortController();
    await listarCuentas({ rol: 'Paciente', estado_cuenta: 'Activa', correo_acceso: 'persona@example.test', page: 2, per_page: 20, password: 'excluido' }, controller.signal);
    expect(calls[0].params).toEqual({ rol: 'Paciente', estado_cuenta: 'Activa', correo_acceso: 'persona@example.test', page: 2, per_page: 20 });
    expect(calls[0].signal).toBe(controller.signal);
    expect(calls[0].sessionBound).toBe(true);
    expect(calls[0].url).toBe('/usuarios');
  });
  it('conserva BIGINT como texto al consultar', async () => {
    const calls = capture();
    expect((await consultarCuenta('9223372036854775807')).id_usuario).toBe('9223372036854775807');
    expect(calls[0].url).toBe('/usuarios/9223372036854775807');
    expect(calls[0].sessionBound).toBe(true);
  });
  it.each(['0', '-1', '1/estado', '9223372036854775808', '01', '1e3'])('rechaza ID de ruta inválido %s antes de HTTP', async id => {
    const calls = capture();
    expect(esIdentificador(id)).toBe(false);
    await expect(consultarCuenta(id)).rejects.toThrow();
    expect(calls).toHaveLength(0);
  });
  it.each(['Administrador', 'Recepcionista', 'Médico'])('alta %s sin contraseña ni campos ajenos al rol', async rol => {
    const calls = capture();
    await crearPersonal({ rol, correo_acceso: 'persona@example.test', nombres_personal: 'Persona', apellidos_personal: 'Ficticia', id_medico: '9223372036854775807', password: 'no enviar', estado_cuenta: 'Activa' });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['post', '/personal']]);
    expect(JSON.parse(calls[1].data)).toEqual({ rol, correo_acceso: 'persona@example.test', ...(rol === 'Médico' ? { id_medico: '9223372036854775807' } : { nombres_personal: 'Persona', apellidos_personal: 'Ficticia' }) });
    expect(calls[1].sessionBound).toBe(true);
    expect(calls[1].withCredentials).toBe(true);
  });
  it('reenvía sobre la misma cuenta, cuerpo vacío y CSRF previo', async () => {
    const calls = capture();
    await reenviarInvitacion('17');
    expect(calls.map(c => c.url)).toEqual([csrfURL, '/personal/17/invitacion']);
    expect(JSON.parse(calls[1].data)).toEqual({});
    expect(calls[1].sessionBound).toBe(true);
  });
  it('acepta invitación por POST JSON, sin sesión protegida, ni token en URL', async () => {
    const calls = capture();
    const data = { token: 'a'.repeat(64), password: ' Frase ficticia 123 ', password_confirmation: ' Frase ficticia 123 ' };
    await aceptarInvitacion({ ...data, rol: 'Administrador' });
    expect(calls.map(c => c.url)).toEqual([csrfURL, '/auth/invitaciones/aceptar']);
    expect(JSON.parse(calls[1].data)).toEqual(data);
    expect(calls[1].sessionBound).toBeUndefined();
  });
  it.each([419, 429, 500])('no reintenta reemisión con %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => {
      calls.push(config.url);
      if (config.method === 'post') throw new AxiosError('rechazo', undefined, config, null, { status });
      return { config, data: null, status: 204, headers: {} };
    };
    await expect(reenviarInvitacion('17')).rejects.toThrow();
    expect(calls).toEqual([csrfURL, '/personal/17/invitacion']);
  });

  it('modifica solo estado y nunca ficha/rol/credenciales', async () => {
    const calls = capture();
    await cambiarEstadoCuenta('17', 'Desactivada');
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', csrfURL], ['patch', '/usuarios/17/estado']]);
    expect(JSON.parse(calls[1].data)).toEqual({ estado_cuenta: 'Desactivada' });
    expect(calls[1].sessionBound).toBe(true);
  });
  it.each(['Administrador', 'Médico'])('cambio de rol %s envía solo campos permitidos', async rol => {
    const calls = capture();
    await cambiarRolCuenta('17', { rol, id_medico: '9223372036854775807', identidad_verificada: true, password: 'prohibido' });
    expect(calls[1].url).toBe('/usuarios/17/rol');
    expect(JSON.parse(calls[1].data)).toEqual({ rol, ...(rol === 'Médico' ? { id_medico: '9223372036854775807', identidad_verificada: true } : {}) });
    expect(calls[1].method).toBe('patch');
    expect(calls[1].sessionBound).toBe(true);
  });
  it('emite temporal exclusivamente con declaración de identidad, CSRF y sesión', async () => {
    const calls = capture();
    await emitirCredencialTemporal('17', true);
    expect(calls.map(c => c.url)).toEqual([csrfURL, '/usuarios/17/credencial-temporal']);
    expect(JSON.parse(calls[1].data)).toEqual({ identidad_verificada: true });
    expect(calls[1].sessionBound).toBe(true);
  });
});
