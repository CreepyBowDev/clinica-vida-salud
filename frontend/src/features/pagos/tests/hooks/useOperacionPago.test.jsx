import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useOperacionPago } from '../../hooks/useOperacionPago';
import { operarPago } from '../../services/pago.service';
import { avanzarSesion } from '../../../../api/api';
import { I, O, operacion } from '../utils/pagoFixtures';

vi.mock('../../services/pago.service', () => ({ operarPago: vi.fn() }));
beforeEach(() => { vi.useFakeTimers(); vi.resetAllMocks(); });
afterEach(() => vi.useRealTimers());
it('bloquea doble envío y recupera pérdida con exactamente la misma clave/entrada', async () => {
  let reject;
  operarPago.mockImplementationOnce(() => new Promise((_, no) => { reject = no; }));
  const { result } = renderHook(useOperacionPago);
  act(() => { result.current.ejecutar({ tipo: 'iniciar', id: O, proveedor: 'paypal', revision_pago: 'r' }); result.current.ejecutar({ tipo: 'iniciar', id: O, proveedor: 'stripe', revision_pago: 'r' }); });
  expect(operarPago).toHaveBeenCalledTimes(1);
  await act(async () => reject(Object.assign(new Error('red'), { pagoEnviado: true })));
  expect(result.current.uncertain).toBe(true);
  act(() => { result.current.ejecutar({ tipo: 'iniciar', id: O, proveedor: 'stripe', revision_pago: 'otra' }); });
  expect(operarPago).toHaveBeenCalledTimes(1);
  operarPago.mockResolvedValueOnce({ status: 202, retryAfter: 5, data: operacion({ proveedor: 'paypal', accion: null, estado: 'Incierto' }) });
  await act(async () => { vi.advanceTimersByTime(5000); });
  await act(async () => result.current.recuperar());
  expect(operarPago.mock.calls[1][0]).toEqual(operarPago.mock.calls[0][0]); expect(result.current.response.status).toBe(202);
  expect(result.current.remaining).toBe(5); await act(async () => vi.advanceTimersByTime(60000)); expect(operarPago).toHaveBeenCalledTimes(2);
});
it.each(['sesion', 'salida', 'unmount'])('descarta respuesta tardía por %s', async kind => {
  let resolve; operarPago.mockImplementationOnce(() => new Promise(yes => { resolve = yes; }));
  const { result, unmount } = renderHook(useOperacionPago);
  act(() => { result.current.ejecutar({ tipo: 'resultado', id: I }); });
  act(() => { if (kind === 'sesion') avanzarSesion(); else if (kind === 'salida') window.dispatchEvent(new Event('pagehide')); else unmount(); });
  await act(async () => resolve({ status: 200, retryAfter: 0, data: operacion({ estado: 'Exitoso', aplicado: true }) }));
  if (kind !== 'unmount') expect(result.current.response).toBeUndefined();
});
it('Retry-After de 429 impide otra operación sin polling', async () => {
  operarPago.mockRejectedValueOnce({ pagoEnviado: true, response: { status: 429, headers: { 'retry-after': '12' } } });
  const { result } = renderHook(useOperacionPago);
  await act(async () => result.current.ejecutar({ tipo: 'resultado', id: I }));
  await act(async () => result.current.ejecutar({ tipo: 'resultado', id: I }));
  expect(operarPago).toHaveBeenCalledTimes(1); expect(result.current.remaining).toBe(12);
});
