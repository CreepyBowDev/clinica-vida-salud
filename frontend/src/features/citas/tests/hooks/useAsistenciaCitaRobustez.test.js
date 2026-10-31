import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useAsistenciaCita } from '../../hooks/useAsistenciaCita';
import { confirmarLlegada, registrarInasistencia, marcarAtendida, consultarAsistenciaCita } from '../../services/asistenciaCita.service';
import { asistencia, ausenciaElegible, atencionElegible, llegada, inasistencia, atencion } from '../utils/asistenciaFixtures';

vi.mock('../../services/asistenciaCita.service', () => ({ confirmarLlegada: vi.fn(), registrarInasistencia: vi.fn(), marcarAtendida: vi.fn(), consultarAsistenciaCita: vi.fn() }));
const acciones = [
  ['guardar', confirmarLlegada, asistencia, llegada, 'LLEGADA', 'presencia_verificada'],
  ['registrarInasistencia', registrarInasistencia, ausenciaElegible, inasistencia, 'INASISTENCIA', 'ausencia_verificada'],
  ['marcarAtendida', marcarAtendida, atencionElegible, atencion, 'ATENCION', 'atencion_realizada'],
];
const mount = () => renderHook(() => useAsistenciaCita(asistencia().id_cita, 'Administrador', '1'));
const entrada = fixture => ({ original: fixture(), declaracion: true });
beforeEach(() => { vi.resetAllMocks(); acciones.forEach(([, service, , respuesta]) => service.mockResolvedValue(respuesta())); consultarAsistenciaCita.mockResolvedValue(asistencia()); });
afterEach(() => vi.useRealTimers());

it.each(acciones.flatMap(a => [undefined, 408, 418, 499, 500, 502, 503, 504].map(status => [...a, status])))('%s: fallo no contractual tras envío bloquea las tres acciones (%#)', async (tipo, service, fixture, _, operacion, campo, status) => {
  service.mockRejectedValue({ asistenciaEnviada: true, response: status ? { status, data: { error: { message: 'NO-SALIR' } } } : undefined });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture)));
  expect(result.current.status).toBe('incierto'); expect(result.current.operacion).toBe(operacion); expect(result.current.peligro).toBe(true);
  act(() => result.current.reset());
  await act(async () => { for (const [method, , f] of acciones) await result.current[method](entrada(f)); });
  expect(acciones.reduce((n, [, s]) => n + s.mock.calls.length, 0)).toBe(1);
  expect(consultarAsistenciaCita).not.toHaveBeenCalled(); expect(JSON.stringify(result.current)).not.toContain('NO-SALIR');
});
it.each(acciones.flatMap(a => [400, 401, 403, 404, 405, 409, 419, 422, 429].map(status => [...a, status])))('%s: rechazo conocido no conserva borrador ni reenvía (%#)', async (tipo, service, fixture, _, operacion, campo, status) => {
  service.mockRejectedValue({ asistenciaEnviada: true, response: { status, data: { error: { code: 'DESCONOCIDO', message: 'NO-SALIR' } } } });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture)));
  expect(result.current.status).toBe('error'); expect(result.current.operacion).toBe(operacion); expect(result.current.resultado).toBeNull();
  expect(JSON.stringify(result.current)).not.toContain('NO-SALIR'); expect(consultarAsistenciaCita).not.toHaveBeenCalled(); expect(service).toHaveBeenCalledTimes(1);
});
it.each(acciones.flatMap(a => acciones.map(b => [...a, b[0]])))('%s: un solo lock rechaza segundo método (%#)', async (tipo, service, fixture, respuesta, operacion, campo, otra) => {
  let complete; service.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = mount(); let pending;
  act(() => { pending = result.current[tipo](entrada(fixture)); result.current[otra](entrada(fixture)); result.current.consultar(); result.current.reset(); });
  expect(acciones.reduce((n, [, s]) => n + s.mock.calls.length, 0)).toBe(1); expect(consultarAsistenciaCita).not.toHaveBeenCalled();
  await act(async () => { complete(respuesta()); await pending; }); expect(result.current.status).toBe('guardada');
});
it.each(acciones)('%s: pagehide aborta y descarta éxito tardío sin perder operación incierta', async (tipo, service, fixture, respuesta, operacion) => {
  let complete; service.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = mount(); let pending; act(() => { pending = result.current[tipo](entrada(fixture)); });
  const signal = service.mock.calls[0][1]; act(() => window.dispatchEvent(new Event('pagehide')));
  expect(signal.aborted).toBe(true); expect(result.current.operacion).toBe(operacion);
  await act(async () => { complete(respuesta()); await pending; }); expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull();
});
it.each(acciones)('%s: desmontaje aborta señal y nunca publica resultado', async (tipo, service, fixture, respuesta) => {
  let complete; service.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result, unmount } = mount(); let pending; act(() => { pending = result.current[tipo](entrada(fixture)); });
  const signal = service.mock.calls[0][1]; unmount(); expect(signal.aborted).toBe(true);
  complete(respuesta()); expect(await pending).toBe(false);
});
it.each(acciones)('%s: doble recuperación solo GET y pagehide descarta su respuesta', async (tipo, service, fixture, respuesta, operacion) => {
  service.mockRejectedValue({ asistenciaEnviada: true }); let complete;
  consultarAsistenciaCita.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture)));
  let pending; act(() => { pending = result.current.consultar(); result.current.consultar(); }); expect(consultarAsistenciaCita).toHaveBeenCalledTimes(1);
  const signal = consultarAsistenciaCita.mock.calls[0][1]; act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true);
  await act(async () => { complete(asistencia()); await pending; }); expect(result.current.status).toBe('incierto'); expect(result.current.operacion).toBe(operacion);
  expect(service).toHaveBeenCalledTimes(1);
});
it.each(acciones.flatMap(a => [403, 404, 419, 429, 500].map(status => [...a, status])))('%s: GET fallido mantiene incertidumbre, no habilita otra escritura (%#)', async (tipo, service, fixture, _, operacion, campo, status) => {
  service.mockRejectedValue({ asistenciaEnviada: true }); consultarAsistenciaCita.mockRejectedValue({ response: { status } });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture))); await act(async () => result.current.consultar());
  expect(result.current.status).toBe('incierto'); expect(result.current.operacion).toBe(operacion); expect(result.current.resultado).toBeNull();
  act(() => result.current.reset()); expect(result.current.status).toBe('incierto'); expect(service).toHaveBeenCalledTimes(1);
});
it.each(acciones.flatMap(a => ['0', '-1', 'no-numero', 'Infinity', undefined].map(header => [...a, header])))('%s: Retry-After inválido no inventa espera (%#)', async (tipo, service, fixture, _, operacion, campo, header) => {
  service.mockRejectedValue({ asistenciaEnviada: true, response: { status: 429, headers: { 'retry-after': header } } });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture))); expect(result.current.remaining).toBe(0);
});
it.each(acciones)('%s: espera persiste tras pagehide y su vencimiento no consulta ni reenvía', async (tipo, service, fixture) => {
  vi.useFakeTimers(); service.mockRejectedValue({ asistenciaEnviada: true, response: { status: 429, headers: { 'retry-after': '2' } } });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture)));
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(result.current.remaining).toBe(2);
  await act(async () => { for (const [method, , f] of acciones) await result.current[method](entrada(f)); }); expect(service).toHaveBeenCalledTimes(1);
  act(() => vi.advanceTimersByTime(2000)); expect(result.current.remaining).toBe(0); expect(service).toHaveBeenCalledTimes(1); expect(consultarAsistenciaCita).not.toHaveBeenCalled();
});
it.each(acciones)('%s: 422 solo publica declaración propia y originales, nunca otros hechos/clínica', async (tipo, service, fixture, _, operacion, campo) => {
  const details = { presencia_verificada: ['NO-SALIR'], ausencia_verificada: ['NO-SALIR'], atencion_realizada: ['NO-SALIR'],
    password: ['NO-SALIR'], diagnostico: ['NO-SALIR'], [campo]: ['Hecho requerido'], actualizada_en_original: ['Original requerido'] };
  service.mockRejectedValue({ asistenciaEnviada: true, response: { status: 422, data: { error: { details } } } });
  const { result } = mount(); await act(async () => result.current[tipo](entrada(fixture)));
  expect(result.current.failure.fields).toEqual({ [campo]: 'Hecho requerido', actualizada_en_original: 'Original requerido' }); expect(JSON.stringify(result.current)).not.toContain('NO-SALIR');
});
it.each(acciones)('%s: beforeunload advierte sin invocar otra escritura', async (tipo, service, fixture) => {
  service.mockRejectedValue({ asistenciaEnviada: true }); const { result, unmount } = mount();
  await act(async () => result.current[tipo](entrada(fixture))); const event = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true); expect(service).toHaveBeenCalledTimes(1);
  unmount(); const after = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(after); expect(after.defaultPrevented).toBe(false);
});
