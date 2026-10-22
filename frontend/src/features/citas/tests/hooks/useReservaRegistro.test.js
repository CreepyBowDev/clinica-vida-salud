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
it('pagehide retira comprobante y descarta resultado tardío', async () => {
  let resolve; const { result } = renderHook(useReservaRegistro); await act(() => result.current.proponer(seleccion));
  confirmarReserva.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
  let pending; act(() => { pending = result.current.confirmar(); });
  act(() => window.dispatchEvent(new Event('pagehide')));
  await act(async () => { resolve({ data: cita, meta: { repetida: false } }); await pending; });
  expect(result.current.status).toBe('busqueda'); expect(result.current.propuesta).toBeNull(); expect(result.current.cita).toBeNull();
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
