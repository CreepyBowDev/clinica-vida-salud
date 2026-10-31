import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { useClinicaQuery } from '../../hooks/useClinicaQuery';
import { AuthContext } from '../../../auth/context/AuthContext';
import { sesion } from '../utils/clinicaFixtures';

const auth = rol => ({ status: 'authenticated', usuario: sesion(rol) });
afterEach(() => vi.useRealTimers());
function mount(load, initial = { id: '41' }) {
  let identity = auth('Médico');
  const Wrapper = ({ children }) => <AuthContext.Provider value={identity}>{children}</AuthContext.Provider>;
  const view = renderHook(({ parameters }) => useClinicaQuery(load, parameters), { wrapper: Wrapper, initialProps: { parameters: initial } });
  return { ...view, changeIdentity: value => { identity = value; view.rerender({ parameters: initial }); } };
}
it('cambio de identidad retira datos y descarta respuesta pendiente', async () => {
  let resolve; const load = vi.fn(() => new Promise(r => { resolve = r; }));
  const view = mount(load); expect(load).toHaveBeenCalledOnce();
  view.changeIdentity({ status: 'anonymous', usuario: null });
  await act(async () => resolve({ diagnostico: 'NO-MOSTRAR' })); expect(view.result.current.data).toBeUndefined();
});
it('cambio de parámetros retira datos de inmediato y aborta solicitud anterior', async () => {
  let resolve; const load = vi.fn(() => new Promise(r => { resolve = r; }));
  const view = mount(load); const oldSignal = load.mock.calls[0][1]; const oldResolve = resolve;
  view.rerender({ parameters: { id: '42' } }); expect(oldSignal.aborted).toBe(true); expect(view.result.current.data).toBeUndefined();
  await act(async () => oldResolve({ id: '41' })); expect(view.result.current.data).toBeUndefined();
  await act(async () => resolve({ id: '42' })); expect(view.result.current.data).toEqual({ id: '42' });
});
it('429 respeta espera y solo consulta otra vez por acción explícita', async () => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  const load = vi.fn().mockRejectedValue({ response: { status: 429, headers: { 'retry-after': '2' } } });
  const view = mount(load); await act(async () => {}); expect(view.result.current.remaining).toBe(2);
  act(() => view.result.current.reload()); expect(load).toHaveBeenCalledOnce();
  await act(async () => vi.advanceTimersByTime(2000)); expect(view.result.current.remaining).toBe(0); expect(load).toHaveBeenCalledOnce();
  load.mockResolvedValue({ ok: true }); act(() => view.result.current.reload()); await act(async () => {});
  expect(load).toHaveBeenCalledTimes(2); expect(view.result.current.data).toEqual({ ok: true });
});
it('SESSION_SUPERSEDED sale de carga con fallo seguro sin conservar respuesta', async () => {
  const load = vi.fn().mockRejectedValue({ code: 'SESSION_SUPERSEDED', message: 'NO-MOSTRAR' });
  const view = mount(load); await waitFor(() => expect(view.result.current.loading).toBe(false));
  expect(view.result.current.failure.message).not.toContain('NO-MOSTRAR'); expect(view.result.current.data).toBeUndefined();
});
it('desmontar aborta la petición', () => {
  const load = vi.fn(() => new Promise(() => {})); const view = mount(load);
  const signal = load.mock.calls[0][1]; view.unmount(); expect(signal.aborted).toBe(true);
});
