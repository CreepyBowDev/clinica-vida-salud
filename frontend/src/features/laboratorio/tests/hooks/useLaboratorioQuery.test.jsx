import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import { useLaboratorioQuery } from '../../hooks/useLaboratorioQuery';

const auth = vi.hoisted(() => ({ value: {} }));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => auth.value }));
beforeEach(() => { auth.value = { status: 'authenticated', usuario: { id_usuario: '1', rol: 'Paciente' } }; });
it('ignora resultados tardíos al cambiar recurso y aborta al desmontar', async () => {
  const pending = [], load = vi.fn((p, signal) => new Promise(resolve => pending.push({ p, signal, resolve })));
  const { result, rerender, unmount } = renderHook(({ id }) => useLaboratorioQuery(load, { id }), { initialProps: { id: '1' } });
  rerender({ id: '2' }); expect(pending[0].signal.aborted).toBe(true);
  await act(async () => pending[0].resolve('ajeno')); expect(result.current.data).toBeUndefined();
  await act(async () => pending[1].resolve('actual')); expect(result.current.data).toBe('actual');
  unmount(); expect(pending[1].signal.aborted).toBe(true);
});
it('cambio de identidad retira datos antes de nueva respuesta', async () => {
  const load = vi.fn().mockResolvedValueOnce('primero').mockImplementation(() => new Promise(() => {}));
  const { result, rerender } = renderHook(() => useLaboratorioQuery(load, {}));
  await waitFor(() => expect(result.current.data).toBe('primero'));
  auth.value = { ...auth.value, usuario: { id_usuario: '2', rol: 'Médico' } }; rerender();
  expect(result.current.data).toBeUndefined(); expect(result.current.loading).toBe(true);
});
it('evento de sesión pausa y exige reconsulta explícita', async () => {
  const load = vi.fn().mockResolvedValue('dato');
  const { result } = renderHook(() => useLaboratorioQuery(load, {}));
  await waitFor(() => expect(result.current.data).toBe('dato'));
  act(() => avanzarSesion()); expect(result.current.data).toBeUndefined(); expect(result.current.failure.message).toMatch(/sesión cambió/); expect(load).toHaveBeenCalledTimes(1);
  act(() => result.current.reload()); await waitFor(() => expect(load).toHaveBeenCalledTimes(2));
});
it('pagehide/BFCache retira datos y pageshow consulta nuevamente', async () => {
  const load = vi.fn().mockResolvedValue('dato'); const { result } = renderHook(() => useLaboratorioQuery(load, {}));
  await waitFor(() => expect(result.current.data).toBe('dato'));
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(result.current.data).toBeUndefined();
  act(() => window.dispatchEvent(new Event('pageshow'))); await waitFor(() => expect(load).toHaveBeenCalledTimes(2));
});
it('429 aplica espera incluso al cambiar filtros sin bucles de red', async () => {
  const load = vi.fn().mockRejectedValue({ response: { status: 429, headers: { 'retry-after': '60' } } });
  const { result, rerender } = renderHook(({ page }) => useLaboratorioQuery(load, { page }), { initialProps: { page: 1 } });
  await waitFor(() => expect(result.current.remaining).toBeGreaterThan(0));
  act(() => result.current.reload()); rerender({ page: 2 });
  await waitFor(() => expect(result.current.failure.status).toBe(429)); expect(load).toHaveBeenCalledTimes(1);
});
