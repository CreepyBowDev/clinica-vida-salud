import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, avanzarSesion } from '../../../../api/api';
import { listarBitacora, consultarEvento } from '../../services/bitacora.service';

const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });

describe('contrato HTTP de Bitácora CU-30', () => {
  it('envía únicamente filtros permitidos, sin alterar mayúsculas, fechas locales ni BIGINT', async () => {
    const calls = [];
    const payload = { data: [{ id_evento: '9223372036854775807', ocurrido_en: '2026-10-21T14:00:00.123456Z' }], meta: { total: 1 }, links: {} };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: payload }; };
    const controller = new AbortController();
    const filtros = { fecha_desde: '2026-10-20', fecha_hasta: '2026-10-21', id_usuario_responsable: '9223372036854775807',
      id_usuario_objetivo: '9007199254740993', tipo_actor: 'USUARIO', modulo: ' Instalacion ', operacion: ' SEEDER ',
      tipo_evento: ' INSTALACION_ADMIN_INICIAL ', resultado: 'EXITO', direccion_ip: '2001:db8::1',
      entidad_referida: 'Usuario', registro_referido: '9007199254740993', page: 2, per_page: 100 };
    expect(await listarBitacora({ ...filtros, sort: 'password_hash', token: 'excluir' }, controller.signal)).toEqual(payload);
    expect(calls).toHaveLength(1);
    expect(calls[0]).toMatchObject({ url: '/bitacora', method: 'get', sessionBound: true, withCredentials: true,
      params: { ...filtros, modulo: 'Instalacion', operacion: 'SEEDER', tipo_evento: 'INSTALACION_ADMIN_INICIAL' } });
    expect(calls[0].signal).toBe(controller.signal);
  });
  it('omite filtros vacíos y conserva paginación del servidor sin solicitar CSRF', async () => {
    const calls = [];
    const payload = { data: [], meta: { current_page: 1, last_page: 1, total: 0 } };
    api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: payload }; };
    expect(await listarBitacora({ modulo: '   ', resultado: null, fecha_desde: undefined, entidad_referida: '' })).toEqual(payload);
    expect(calls).toHaveLength(1);
    expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
  });
  it('entrega el detalle íntegro contratado, conservando cadenas y objetos vacíos', async () => {
    const detalle = { id_evento: '9223372036854775807', referencias: {}, cambios_seguros: {}, metadatos_seguros: {}, registrado_en: '2026-10-21T14:00:00.123456Z' };
    const controller = new AbortController();
    let call;
    api.defaults.adapter = async config => { call = config; return { config, status: 200, headers: {}, data: { data: detalle } }; };
    expect(await consultarEvento(detalle.id_evento, controller.signal)).toEqual(detalle);
    expect(call).toMatchObject({ method: 'get', url: `/bitacora/${detalle.id_evento}`, sessionBound: true, withCredentials: true });
    expect(call.signal).toBe(controller.signal);
  });
  it.each([0, 9007199254740992, '', '0', '-1', '01', '1/otro', '1?token=secreto', '9223372036854775808'])('rechaza ID inválido %s antes de HTTP', async id => {
    let calls = 0;
    api.defaults.adapter = async () => { calls++; throw new Error('No debe consultar'); };
    await expect(consultarEvento(id)).rejects.toThrow('Identificador de evento no válido.');
    expect(calls).toBe(0);
  });
  it.each([401, 403, 404, 422, 429, 500])('propaga %s sin reenviar ni convertirlo en éxito', async status => {
    let calls = 0;
    api.defaults.adapter = async config => { calls++; throw new AxiosError('Error ficticio', undefined, config, null,
      { status, data: { error: { code: 'PRUEBA', details: {} } }, headers: {} }); };
    await expect(consultarEvento('1')).rejects.toMatchObject({ response: { status } });
    expect(calls).toBe(1);
  });
  it('permite cancelar una consulta antes del envío', async () => {
    const controller = new AbortController(); controller.abort();
    let calls = 0;
    api.defaults.adapter = async () => { calls++; throw new Error('No debe consultar'); };
    await expect(listarBitacora({}, controller.signal)).rejects.toMatchObject({ code: 'ERR_CANCELED' });
    expect(calls).toBe(0);
  });
  it('descarta una respuesta de Bitácora perteneciente a una sesión anterior', async () => {
    let completar;
    api.defaults.adapter = config => new Promise(resolve => { completar = () => resolve({ config, status: 200, headers: {}, data: { data: [] } }); });
    const pending = listarBitacora();
    await Promise.resolve(); await Promise.resolve();
    avanzarSesion(); completar();
    await expect(pending).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  });
});
