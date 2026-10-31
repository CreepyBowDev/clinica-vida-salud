import { StrictMode } from 'react';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import { useSolicitudEstudio } from '../../hooks/useSolicitudEstudio';
import { solicitarEstudio, consultarContextoSolicitud } from '../../services/laboratorio.service';
import { contextoSolicitud, solicitud } from '../utils/laboratorioFixtures';
vi.mock('../../services/laboratorio.service', () => ({ solicitarEstudio: vi.fn(), consultarContextoSolicitud: vi.fn() }));
beforeEach(() => { vi.resetAllMocks(); solicitarEstudio.mockResolvedValue(solicitud()); consultarContextoSolicitud.mockResolvedValue(contextoSolicitud()); });
const mount = () => renderHook(() => useSolicitudEstudio('41', 'Médico', '7'), { wrapper: StrictMode });
it('solo un envío ante doble clic incluso bajo StrictMode', async () => {
  let resolve; solicitarEstudio.mockImplementation(() => new Promise(r => { resolve = r; })); const { result } = mount();
  let pending; act(() => { pending = result.current.guardar({ estudio: 'Estudio ficticio' }); result.current.guardar({}); });
  expect(solicitarEstudio).toHaveBeenCalledTimes(1); expect(result.current.status).toBe('enviando');
  await act(async () => { resolve(solicitud()); await pending; }); expect(result.current.status).toBe('registrada');
  await act(async () => { await result.current.guardar({}); }); expect(solicitarEstudio).toHaveBeenCalledTimes(1);
});
it.each([undefined, 500, 502, 503])('resultado incierto %s impide reset y POST; consulta solo GET', async status => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true, response: status ? { status } : undefined }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('incierto');
  act(() => result.current.reset()); await act(async () => { await result.current.guardar({}); }); expect(solicitarEstudio).toHaveBeenCalledTimes(1);
  await act(async () => { await result.current.consultar(); }); expect(result.current.status).toBe('consultada'); expect(consultarContextoSolicitud).toHaveBeenCalledTimes(1);
});
it('201 malformado sin status de error también exige GET', async () => {
  solicitarEstudio.mockRejectedValue(Object.assign(Error('PRIVADO'), { solicitudEnviada: true })); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('incierto'); expect(result.current.failure.message).not.toContain('PRIVADO');
});
it('CSRF/red anterior al despacho no queda como envío incierto ni se repite', async () => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: false }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('error'); expect(result.current.failure.message).toMatch(/No se envió/);
});
it.each([400, 401, 403, 404, 409, 419, 422])('HTTP conocido %s exige revisión nueva sin replay', async status => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true, response: { status, data: { error: { message: 'PRIVADO' } } } }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('error'); expect(result.current.failure.message).not.toContain('PRIVADO'); expect(solicitarEstudio).toHaveBeenCalledTimes(1);
});
it('422 conocido permite corregir con mensajes locales y nueva declaración', async () => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true, response: { status: 422, data: { error: { details: { estudio_solicitado: ['PRIVADO'] } } } } }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('validacion'); expect(result.current.failure.fields.estudio_solicitado).not.toBe('PRIVADO');
});
it('422 sobre originales nunca conserva autorización de reenviar', async () => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true, response: { status: 422, data: { error: { details: { estudio_solicitado: ['x'], 'originales.id_version_consulta': ['x'] } } } } }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.status).toBe('error');
});
it('fallo al consultar mantiene incertidumbre, no deduplica ni declara fracaso', async () => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true }); consultarContextoSolicitud.mockRejectedValue({ response: { status: 404 } }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); await result.current.consultar(); }); expect(result.current.status).toBe('incierto'); expect(solicitarEstudio).toHaveBeenCalledTimes(1);
});
it.each(['pagehide', 'sesion', 'unmount'])('%s aborta e ignora respuesta tardía', async tipo => {
  let resolve; solicitarEstudio.mockImplementation(() => new Promise(r => { resolve = r; })); const { result, unmount } = mount(); let pending;
  act(() => { pending = result.current.guardar({}); }); const signal = solicitarEstudio.mock.calls[0][1];
  act(() => { if (tipo === 'pagehide') window.dispatchEvent(new Event('pagehide')); else if (tipo === 'sesion') avanzarSesion(); else unmount(); });
  expect(signal.aborted).toBe(true);
  if (tipo !== 'unmount') { expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull(); }
  await act(async () => { resolve(solicitud()); await pending; }); if (tipo !== 'unmount') expect(result.current.status).toBe('incierto');
});
it('429 bloquea reset, GET y POST durante Retry-After', async () => {
  solicitarEstudio.mockRejectedValue({ solicitudEnviada: true, response: { status: 429, headers: { 'retry-after': '60' } } }); const { result } = mount();
  await act(async () => { await result.current.guardar({}); }); expect(result.current.remaining).toBe(60);
  act(() => result.current.reset()); await act(async () => { await result.current.guardar({}); await result.current.consultar(); }); expect(solicitarEstudio).toHaveBeenCalledTimes(1); expect(consultarContextoSolicitud).not.toHaveBeenCalled();
});
