import { afterEach, describe, expect, it } from 'vitest';
import { api, csrfURL } from '../../../api/api';
import { registrarPaciente, vincularPaciente, reenviarCodigoRegistro, verificarRegistro } from './registration.service';
import { emitirCodigoActivacion } from '../../pacientes/services/activation.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
function capture() {
  const calls = [];
  api.defaults.adapter = async config => { calls.push(config); return { config, data: { data: {} }, headers: {}, status: 202 }; };
  return calls;
}
describe('contratos de registro y activación', () => {
  it('registro excluye IDs/rol/clínica y conserva CI y contraseña como texto', async () => {
    const calls = capture();
    await registrarPaciente({ documento_identidad: ' 000-A lp ', nombres: 'Persona', password: ' Frase ficticia 123 ', id_usuario: '8', rol: 'Administrador', alergias: 'no enviar', codigo_activacion: 'no enviar' });
    expect(calls.map(c => c.url)).toEqual([csrfURL, '/auth/registro']);
    expect(JSON.parse(calls[1].data)).toEqual({ documento_identidad: ' 000-A lp ', nombres: 'Persona', password: ' Frase ficticia 123 ' });
    expect(calls[1].sessionBound).not.toBe(true);
  });
  it('vincular envía exclusivamente cinco campos, sin datos de perfil', async () => {
    const calls = capture();
    await vincularPaciente({ documento_identidad: '001', codigo_activacion: 'aB1234567890', correo_acceso: 'ficticio@example.test', password: 'Clave ficticia 123', password_confirmation: 'Clave ficticia 123', nombres: 'no enviar', id_paciente: '8' });
    expect(calls[1].url).toBe('/auth/registro/vincular');
    expect(Object.keys(JSON.parse(calls[1].data))).toEqual(['documento_identidad', 'codigo_activacion', 'correo_acceso', 'password', 'password_confirmation']);
  });
  it('reenvío no selecciona proceso/correo y verificar conserva ceros iniciales', async () => {
    const calls = capture();
    await reenviarCodigoRegistro({ correo_acceso: 'no enviar' });
    await verificarRegistro({ codigo: '001234', id_paciente: '8' });
    expect(calls.map(c => c.url)).toEqual([csrfURL, '/auth/registro/reenviar', csrfURL, '/auth/registro/verificar']);
    expect(calls[1].data).toBeUndefined();
    expect(JSON.parse(calls[3].data)).toEqual({ codigo: '001234' });
  });
  it('Recepción emite con BIGINT textual, CSRF y sesión vinculada', async () => {
    const calls = capture();
    await emitirCodigoActivacion('9223372036854775807', true);
    expect(calls[1].url).toBe('/pacientes/9223372036854775807/codigo-activacion');
    expect(JSON.parse(calls[1].data)).toEqual({ identidad_verificada: true });
    expect(calls[1].sessionBound).toBe(true);
  });
  it.each(['0', '../8', '9223372036854775808'])('rechaza ficha inválida %s antes de HTTP', async id => {
    const calls = capture();
    await expect(emitirCodigoActivacion(id, true)).rejects.toThrow();
    expect(calls).toHaveLength(0);
  });
  it.each([419, 429, 500])('no reintenta una escritura con %s', async status => {
    const calls = [];
    api.defaults.adapter = async config => { calls.push(config.url); if (config.url === csrfURL) return { config, data: null, status: 204, headers: {} }; throw { response: { status } }; };
    await expect(verificarRegistro({ codigo: '001234' })).rejects.toBeDefined();
    expect(calls).toEqual([csrfURL, '/auth/registro/verificar']);
  });
});
