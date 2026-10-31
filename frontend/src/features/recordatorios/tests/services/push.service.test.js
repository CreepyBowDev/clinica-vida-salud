import { beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion, obtenerEpocaSesion, csrfURL } from '../../../../api/api';
import { autorizarPush, clavePush, consultarConfiguracionPush } from '../../services/push.service';

const clave = btoa(String.fromCharCode(4, ...Array(64).fill(1))).replace(/=/g, '');
const subscription = { endpoint: 'https://push.example.test/ficticio', expirationTime: null, keys: { p256dh: 'a'.repeat(87), auth: 'b'.repeat(22) } };
beforeEach(() => { vi.restoreAllMocks(); vi.spyOn(api, 'get'); vi.spyOn(api, 'put'); });
it.each([true, false])('consulta configuración %s vinculada a la época y sin datos de titular', async habilitado => {
  api.get.mockResolvedValue({ status: 200, data: { data: { habilitado, clave_publica_vapid: habilitado ? clave : null, secreto: 'no' } } });
  const result = await consultarConfiguracionPush();
  expect(result).toEqual({ habilitado, clave_publica_vapid: habilitado ? clave : null });
  expect(api.get).toHaveBeenCalledWith('/push/configuracion', expect.objectContaining({ sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() }));
});
it.each([null, {}, { habilitado: 'true' }, { habilitado: false, clave_publica_vapid: clave }, { habilitado: true, clave_publica_vapid: 'invalida' }])('rechaza configuración mal formada %j', async data => {
  api.get.mockResolvedValue({ status: 200, data: { data } }); await expect(consultarConfiguracionPush()).rejects.toThrow();
});
it('decodifica únicamente la clave pública de 65 bytes', () => {
  expect(clavePush(clave)).toEqual(Uint8Array.from([4, ...Array(64).fill(1)]));
  expect(() => clavePush('a'.repeat(87))).toThrow();
});
it.each([200, 201])('registra consentimiento explícito con CSRF y contrato exacto (%s)', async status => {
  api.get.mockResolvedValue({}); api.put.mockResolvedValue({ status, data: { data: { id_suscripcion: '1', actual: true, activa: true, version: 1, endpoint: 'no exponer' } } });
  expect(await autorizarPush({ ...subscription, id_usuario: '999', keys: { ...subscription.keys, extra: 'no' } })).toEqual({ activa: true });
  expect(api.get).toHaveBeenCalledWith(csrfURL, expect.objectContaining({ sessionBound: true }));
  expect(api.put).toHaveBeenCalledWith('/push/suscripcion', { permiso_otorgado: true, suscripcion: subscription }, expect.objectContaining({ sessionEpochExpected: obtenerEpocaSesion() }));
});
it('no escribe si cambia época durante CSRF', async () => {
  api.get.mockImplementation(async () => { avanzarSesion(); return {}; });
  await expect(autorizarPush(subscription)).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(api.put).not.toHaveBeenCalled();
});
it('no consulta con época antigua o señal abortada', async () => {
  const old = obtenerEpocaSesion(); avanzarSesion();
  await expect(consultarConfiguracionPush(undefined, old)).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  await expect(autorizarPush(subscription, AbortSignal.abort())).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  expect(api.get).not.toHaveBeenCalled(); expect(api.put).not.toHaveBeenCalled();
});
it('descarta respuesta de alta tras cambio de sesión y no reintenta', async () => {
  api.get.mockResolvedValue({}); api.put.mockImplementation(async () => { avanzarSesion(); return { status: 201 }; });
  await expect(autorizarPush(subscription)).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(api.put).toHaveBeenCalledTimes(1);
});
it.each([{}, { actual: false, activa: true }, { actual: true, activa: false }, { actual: true, activa: true, version: 0 }])('no confirma autorización inválida %j', async data => {
  api.get.mockResolvedValue({}); api.put.mockResolvedValue({ status: 201, data: { data: { id_suscripcion: '1', ...data } } });
  await expect(autorizarPush(subscription)).rejects.toThrow();
});
