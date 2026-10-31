import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { useGestionCita } from '../../hooks/useGestionCita';
import { cancelarCita, consultarGestionCita, reprogramarCita } from '../../services/gestionCita.service';
import { alternativas, cancelada, cita, gestion, reprogramada } from '../utils/gestionFixtures';

vi.mock('../../services/gestionCita.service', () => ({ cancelarCita: vi.fn(), consultarGestionCita: vi.fn(), reprogramarCita: vi.fn() }));
const acciones = [
  ['cancelar', cancelarCita, () => ({ original: gestion(), motivo: 'MOTIVO-PRIVADO' })],
  ['guardar', reprogramarCita, () => ({ vista: alternativas().data, turno: alternativas().data.turnos[0] })],
];
beforeEach(() => { vi.resetAllMocks(); cancelarCita.mockResolvedValue(cancelada()); reprogramarCita.mockResolvedValue(reprogramada()); consultarGestionCita.mockResolvedValue(gestion()); });

it.each(acciones.flatMap(a => [408, 418, 499, 500, 502, 503, 504, undefined].map(status => [...a, status])))('%s: estado no verificable posterior bloquea ambas escrituras (%#)', async (tipo, service, entrada, status) => {
  service.mockRejectedValue({ gestionEnviada: true, response: status ? { status, data: { error: { message: 'NO-SALIR' } } } : undefined });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current[tipo](entrada())); expect(result.current.status).toBe('incierto'); expect(result.current.peligro).toBe(true);
  act(() => result.current.reset()); await act(async () => { await result.current.guardar(acciones[1][2]()); await result.current.cancelar(acciones[0][2]()); });
  expect(service).toHaveBeenCalledTimes(1); expect(JSON.stringify(result.current)).not.toContain('NO-SALIR');
  expect(consultarGestionCita).not.toHaveBeenCalled();
});
it.each(acciones.flatMap(a => [400, 401, 403, 404, 405, 409, 419, 422, 429].map(status => [...a, status])))('%s: rechazo conocido retira revisión sin replay (%#)', async (tipo, service, entrada, status) => {
  service.mockRejectedValue({ gestionEnviada: true, response: { status, headers: {}, data: { error: { message: 'NO-SALIR', code: 'NO-CONOCIDO' } } } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current[tipo](entrada()));
  expect(result.current.status).toBe('error'); expect(service).toHaveBeenCalledTimes(1); expect(consultarGestionCita).not.toHaveBeenCalled();
  expect(JSON.stringify(result.current)).not.toContain('NO-SALIR');
});
it.each(acciones)('%s: pagehide aborta señal, conserva incertidumbre y descarta completado tardío', async (tipo, service, entrada) => {
  let complete, signal; service.mockImplementation((_, s) => { signal = s; return new Promise(resolve => { complete = resolve; }); });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); let pending;
  act(() => { pending = result.current[tipo](entrada()); }); expect(signal.aborted).toBe(false);
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true); expect(result.current.status).toBe('incierto');
  await act(async () => { complete(tipo === 'cancelar' ? cancelada() : reprogramada()); await pending; });
  expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull(); expect(JSON.stringify(result.current)).not.toContain('MOTIVO-PRIVADO');
});
it.each(acciones)('%s: desmontaje aborta la señal sin prometer que el servidor revirtió', async (tipo, service, entrada) => {
  let complete, signal; service.mockImplementation((_, s) => { signal = s; return new Promise(resolve => { complete = resolve; }); });
  const { result, unmount } = renderHook(() => useGestionCita(cita.id_cita)); let pending;
  act(() => { pending = result.current[tipo](entrada()); }); unmount(); expect(signal.aborted).toBe(true);
  complete(tipo === 'cancelar' ? cancelada() : reprogramada()); expect(await pending).toBe(false);
});
it.each(acciones)('%s: recuperación es GET con señal, un doble clic no duplica lectura ni reenvía', async (tipo, service, entrada) => {
  service.mockRejectedValue({ gestionEnviada: true }); let complete, signal;
  consultarGestionCita.mockImplementation((_, s) => { signal = s; return new Promise(resolve => { complete = resolve; }); });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current[tipo](entrada()));
  let pending; act(() => { pending = result.current.consultar(); result.current.consultar(); }); expect(consultarGestionCita).toHaveBeenCalledTimes(1);
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true);
  await act(async () => { complete(gestion()); await pending; }); expect(result.current.status).toBe('incierto'); expect(service).toHaveBeenCalledTimes(1);
});
it.each(acciones.flatMap(a => [403, 404, 419, 429, 500].map(status => [...a, status])))('%s: lectura de recuperación fallida no elimina incertidumbre (%#)', async (tipo, service, entrada, status) => {
  service.mockRejectedValue({ gestionEnviada: true }); consultarGestionCita.mockRejectedValue({ response: { status } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current[tipo](entrada())); await act(async () => result.current.consultar());
  expect(result.current.status).toBe('incierto'); expect(service).toHaveBeenCalledTimes(1); expect(result.current.resultado).toBeNull();
});
it.each(acciones.flatMap(a => ['0', '-1', 'no-numero', 'Infinity', undefined].map(header => [...a, header])))('%s: Retry-After inválido no añade espera ficticia (%#)', async (tipo, service, entrada, header) => {
  service.mockRejectedValue({ gestionEnviada: true, response: { status: 429, headers: { 'retry-after': header } } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current[tipo](entrada())); expect(result.current.remaining).toBe(0);
  expect(service).toHaveBeenCalledTimes(1);
});
it.each(acciones)('%s: campos de error ajenos se descartan sin volcar cuerpo técnico', async (tipo, service, entrada) => {
  service.mockRejectedValue({ gestionEnviada: true, response: { status: 422, data: { error: { message: 'NO-SALIR', details: {
    password: ['NO-SALIR'], diagnostico: ['NO-SALIR'], motivo: ['Motivo permitido'], fecha: ['Fecha permitida'] } } } } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current[tipo](entrada()));
  expect(JSON.stringify(result.current)).not.toContain('NO-SALIR'); expect(result.current.failure.fields).toEqual(tipo === 'cancelar' ? { motivo: 'Motivo permitido' } : { fecha: 'Fecha permitida' });
});
