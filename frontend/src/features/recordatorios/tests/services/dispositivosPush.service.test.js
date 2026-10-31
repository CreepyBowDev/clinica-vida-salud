import { beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion, obtenerEpocaSesion, csrfURL } from '../../../../api/api';
import { listarSuscripcionesPush, revocarSuscripcionPush } from '../../services/push.service';

const item = { id_suscripcion: '1', actual: true, activa: true, version: 1, autorizada_en: '2026-10-31T12:00:00.000001Z', revocada_en: null };
const response = () => ({ status: 200, data: { data: [{ ...item, endpoint: 'SECRETO', keys: 'SECRETO', id_usuario: '9' }],
  meta: { current_page: 1, per_page: 20, total: 1, last_page: 1, secreto: 'SECRETO' } } });
beforeEach(() => { vi.restoreAllMocks(); vi.spyOn(api, 'get'); vi.spyOn(api, 'delete').mockResolvedValue({ status: 204 }); });
it('lista únicamente campos públicos, con paginación y sesión vinculada', async () => {
  api.get.mockResolvedValue(response());
  expect(await listarSuscripcionesPush()).toEqual({ data: [item], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } });
  expect(api.get).toHaveBeenCalledWith('/push/suscripciones', expect.objectContaining({ params: { page: 1, per_page: 20 }, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() }));
});
it.each([{ id_suscripcion: '0' }, { id_suscripcion: '9223372036854775808' }, { actual: 1 }, { activa: null }, { version: 0 }, { autorizada_en: 'ayer' }, { revocada_en: false }])('rechaza suscripción mal formada %j', async change => {
  const r = response(); Object.assign(r.data.data[0], change); api.get.mockResolvedValue(r);
  await expect(listarSuscripcionesPush()).rejects.toThrow();
});
it.each([{ total: -1 }, { current_page: 2 }, { per_page: 100 }, { last_page: 0 }])('rechaza meta inválida %j', async change => {
  const r = response(); Object.assign(r.data.meta, change); api.get.mockResolvedValue(r);
  await expect(listarSuscripcionesPush()).rejects.toThrow();
});
it.each([0, -1, '1', 1.5])('no consulta página inválida %s', async page => {
  await expect(listarSuscripcionesPush(page)).rejects.toThrow(); expect(api.get).not.toHaveBeenCalled();
});
it.each([null, '42'])('revoca %s con CSRF, sin cuerpo ni datos de titular', async id => {
  api.get.mockResolvedValue({}); await revocarSuscripcionPush(id);
  expect(api.get).toHaveBeenCalledWith(csrfURL, expect.objectContaining({ sessionBound: true }));
  expect(api.delete).toHaveBeenCalledWith(id === null ? '/push/suscripcion' : '/push/suscripciones/42', { signal: undefined, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() });
});
it.each(['../1', '01', '0', 1, '9223372036854775808'])('rechaza ID %s antes de red', async id => {
  await expect(revocarSuscripcionPush(id)).rejects.toThrow(); expect(api.get).not.toHaveBeenCalled(); expect(api.delete).not.toHaveBeenCalled();
});
it('no revoca después de cambiar sesión durante CSRF', async () => {
  api.get.mockImplementation(async () => { avanzarSesion(); return {}; });
  await expect(revocarSuscripcionPush()).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(api.delete).not.toHaveBeenCalled();
});
it('no consulta/revoca si la señal ya está abortada o la época es vieja', async () => {
  const epoch = obtenerEpocaSesion(); avanzarSesion();
  await expect(listarSuscripcionesPush(1, undefined, epoch)).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  await expect(revocarSuscripcionPush(null, AbortSignal.abort())).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  expect(api.get).not.toHaveBeenCalled(); expect(api.delete).not.toHaveBeenCalled();
});
it.each(['lista', 'baja'])('descarta respuesta atrasada de %s sin reintentar', async kind => {
  api.get.mockResolvedValue({});
  if (kind === 'lista') api.get.mockImplementation(async () => { avanzarSesion(); return response(); });
  else api.delete.mockImplementation(async () => { avanzarSesion(); return { status: 204 }; });
  await expect(kind === 'lista' ? listarSuscripcionesPush() : revocarSuscripcionPush()).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  expect(api.delete).toHaveBeenCalledTimes(kind === 'lista' ? 0 : 1);
});
it.each([200, 201, 202])('no confirma baja con HTTP %s', async status => {
  api.get.mockResolvedValue({}); api.delete.mockResolvedValue({ status }); await expect(revocarSuscripcionPush()).rejects.toThrow();
});
it.each([401, 403, 404, 409, 419, 422, 429, 500, undefined])('no reintenta fallo de baja %s', async status => {
  api.get.mockResolvedValue({}); api.delete.mockRejectedValue({ response: { status } });
  await expect(revocarSuscripcionPush()).rejects.toBeDefined(); expect(api.delete).toHaveBeenCalledTimes(1);
});
