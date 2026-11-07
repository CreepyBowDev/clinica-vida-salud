import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useReservaRegistro } from '../../hooks/useReservaRegistro';
import { proponerReserva, confirmarReserva, consultarResultadoReserva } from '../../services/reserva.service';
import { propuesta, seleccion, cita } from '../utils/reservaFixtures';

vi.mock('../../services/reserva.service', () => ({ proponerReserva: vi.fn(), confirmarReserva: vi.fn(), consultarResultadoReserva: vi.fn() }));
beforeEach(() => {
  vi.resetAllMocks(); proponerReserva.mockResolvedValue(propuesta);
  confirmarReserva.mockResolvedValue({ data: cita, meta: { repetida: false } });
  consultarResultadoReserva.mockResolvedValue({ registrada: true, cita });
});
afterEach(() => { vi.useRealTimers(); });
it.each(['proponer', 'confirmar', 'resultado'])('Retry-After bloquea %s sin timers de reenvío', async etapa => {
  vi.useFakeTimers();
  const { result } = renderHook(useReservaRegistro);
  if (etapa !== 'proponer') await act(() => result.current.proponer(seleccion));
  if (etapa === 'resultado') {
    confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true }); await act(() => result.current.confirmar());
  }
  const task = etapa === 'proponer' ? proponerReserva : etapa === 'confirmar' ? confirmarReserva : consultarResultadoReserva;
  task.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '2' } } });
  const operation = etapa === 'proponer' ? () => result.current.proponer(seleccion) : etapa === 'confirmar' ? () => result.current.confirmar() : () => result.current.consultar();
  await act(operation); const antes = task.mock.calls.length;
  expect(result.current.remaining).toBe(2);
  await act(operation); expect(task).toHaveBeenCalledTimes(antes);
  await act(async () => vi.advanceTimersByTimeAsync(2000));
  expect(result.current.remaining).toBe(0); expect(task).toHaveBeenCalledTimes(antes);
  if (etapa === 'proponer') act(() => result.current.reset());
  await act(operation); expect(task).toHaveBeenCalledTimes(antes + 1);
});
it('beforeunload advierte durante incertidumbre y se retira al recuperar resultado', async () => {
  const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true }); await act(() => result.current.confirmar());
  const warn = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(warn); expect(warn.defaultPrevented).toBe(true);
  await act(() => result.current.consultar());
  const after = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(after); expect(after.defaultPrevented).toBe(false);
});
it('pagehide retira datos, aborta y conserva reconciliación sin aceptar resultado tardío', async () => {
  let resolve; const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
  let pending; act(() => { pending = result.current.confirmar(); });
  const signal = confirmarReserva.mock.calls[0][1];
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true);
  await act(async () => { resolve({ data: cita, meta: { repetida: false } }); await pending; });
  expect(result.current.status).toBe('incierto'); expect(result.current.propuesta).toBeNull(); expect(result.current.cita).toBeNull();
  act(() => result.current.reset()); await act(() => result.current.confirmar());
  expect(result.current.status).toBe('incierto'); expect(confirmarReserva).toHaveBeenCalledTimes(1);
  await act(() => result.current.consultar());
  expect(consultarResultadoReserva).toHaveBeenCalledWith({ propuesta: propuesta.propuesta, pacienteEsperado: '3' }, expect.any(AbortSignal));
  expect(result.current.status).toBe('registrada'); expect(result.current.cita).toEqual(cita);
});
it('consulta doble simultánea no crea una cita ni repite resultado', async () => {
  let resolve; const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true }); await act(() => result.current.confirmar());
  consultarResultadoReserva.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
  let pending; act(() => { pending = result.current.consultar(); result.current.consultar(); });
  expect(consultarResultadoReserva).toHaveBeenCalledTimes(1);
  await act(async () => { resolve({ registrada: false }); await pending; });
  expect(result.current.status).toBe('ausente'); expect(confirmarReserva).toHaveBeenCalledTimes(1);
});
it.each([undefined, 408, 418, 499, 500, 502])('HTTP %s tras envío conserva la propuesta y solo permite reconciliar', async status => {
  const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true, response: status ? { status } : undefined });
  await act(() => result.current.confirmar());
  expect(result.current.status).toBe('incierto'); expect(result.current.propuesta).toBe(propuesta);
  act(() => result.current.reset()); await act(() => result.current.confirmar());
  expect(confirmarReserva).toHaveBeenCalledTimes(1); expect(result.current.status).toBe('incierto');
  await act(() => result.current.consultar()); expect(result.current.status).toBe('registrada');
});
it('desmontar aborta una operación pendiente y descarta su publicación', async () => {
  let complete;
  const { result, unmount } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockImplementationOnce(() => new Promise(resolve => { complete = resolve; }));
  let pending; act(() => { pending = result.current.confirmar(); });
  const signal = confirmarReserva.mock.calls[0][1]; unmount(); expect(signal.aborted).toBe(true);
  await act(async () => { complete({ data: cita, meta: { repetida: false } }); await pending; });
  expect(consultarResultadoReserva).not.toHaveBeenCalled(); expect(confirmarReserva).toHaveBeenCalledTimes(1);
});
it('ausencia consultada tras pagehide exige nueva revisión, no confirma datos retirados', async () => {
  const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true }); await act(() => result.current.confirmar());
  act(() => window.dispatchEvent(new Event('pagehide')));
  consultarResultadoReserva.mockResolvedValueOnce({ registrada: false }); await act(() => result.current.consultar());
  expect(result.current.status).toBe('ausente'); expect(result.current.propuesta).toBeNull();
  await act(() => result.current.confirmar()); expect(confirmarReserva).toHaveBeenCalledTimes(1);
  act(() => result.current.reset()); expect(result.current.status).toBe('busqueda');
});
it('otro pagehide durante consulta no pierde referencia ni publica un resultado antiguo', async () => {
  let complete;
  const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockRejectedValueOnce({ reservaEnviada: true }); await act(() => result.current.confirmar());
  act(() => window.dispatchEvent(new Event('pagehide')));
  consultarResultadoReserva.mockImplementationOnce(() => new Promise(resolve => { complete = resolve; }));
  let pending; act(() => { pending = result.current.consultar(); });
  const signal = consultarResultadoReserva.mock.calls[0][1];
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true);
  await act(async () => { complete({ registrada: true, cita }); await pending; });
  expect(result.current.status).toBe('incierto'); expect(result.current.cita).toBeNull();
  await act(() => result.current.consultar()); expect(result.current.status).toBe('registrada');
  expect(consultarResultadoReserva.mock.calls[1][0]).toEqual({ propuesta: propuesta.propuesta, pacienteEsperado: '3' });
});
