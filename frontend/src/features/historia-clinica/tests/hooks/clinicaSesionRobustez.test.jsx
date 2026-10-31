import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import { AuthContext } from '../../../auth/context/AuthContext';
import { useClinicaQuery } from '../../hooks/useClinicaQuery';
import { useRegistroConsulta } from '../../hooks/useRegistroConsulta';
import { registrarConsulta } from '../../services/consultaMedica.service';
import { sesion } from '../utils/clinicaFixtures';

vi.mock('../../services/consultaMedica.service', async actual => ({ ...await actual(), registrarConsulta: vi.fn() }));
afterEach(() => { vi.resetAllMocks(); vi.useRealTimers(); });
const Wrapper = ({ children }) => <AuthContext.Provider value={{ status: 'authenticated', usuario: sesion('Médico') }}>{children}</AuthContext.Provider>;
it('avance de sesión retira lectura ya resuelta sin reconsulta automática', async () => {
  const load = vi.fn().mockResolvedValue({ diagnostico: 'DATO-FICTICIO-PRIVADO' });
  const view = renderHook(() => useClinicaQuery(load, { id: '41' }), { wrapper: Wrapper });
  await waitFor(() => expect(view.result.current.data).toBeDefined());
  act(() => avanzarSesion());
  expect(view.result.current.data).toBeUndefined(); expect(load).toHaveBeenCalledOnce();
  expect(view.result.current.failure).toBeDefined();
  act(() => view.result.current.reload()); await waitFor(() => expect(load).toHaveBeenCalledTimes(2));
});
it('avance de sesión durante escritura aborta, conserva incertidumbre y descarta éxito tardío', async () => {
  let finish; registrarConsulta.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
  const view = renderHook(() => useRegistroConsulta('41', 'Médico', '7'));
  let task; act(() => { task = view.result.current.guardar({}); }); const signal = registrarConsulta.mock.calls[0][1];
  act(() => avanzarSesion()); expect(signal.aborted).toBe(true); expect(view.result.current.status).toBe('incierto');
  await act(async () => { finish({ consulta: { id_consulta: '31' } }); await task; }); expect(view.result.current.resultado).toBeNull();
});
it('pagehide aborta la lectura de inmediato, sin esperar al siguiente efecto React', () => {
  const load = vi.fn(() => new Promise(() => {}));
  renderHook(() => useClinicaQuery(load, { id: '41' }), { wrapper: Wrapper }); const signal = load.mock.calls[0][1];
  act(() => { window.dispatchEvent(new Event('pagehide')); expect(signal.aborted).toBe(true); });
});
it('Retry-After de fecha HTTP bloquea reconsulta tras avanzar sesión y no inicia red automáticamente', async () => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] }); vi.setSystemTime(new Date('2026-10-29T12:00:00Z'));
  const load = vi.fn().mockRejectedValue({ response: { status: 429, headers: { 'retry-after': 'Thu, 29 Oct 2026 12:00:03 GMT' } } });
  const view = renderHook(() => useClinicaQuery(load, { id: '41' }), { wrapper: Wrapper }); await act(async () => {});
  expect(view.result.current.remaining).toBe(3); act(() => avanzarSesion()); act(() => view.result.current.reload()); expect(load).toHaveBeenCalledOnce();
  await act(async () => vi.advanceTimersByTime(3000)); expect(load).toHaveBeenCalledOnce();
  load.mockResolvedValue({ ok: true }); act(() => view.result.current.reload()); await act(async () => {}); expect(load).toHaveBeenCalledTimes(2);
});
it('desmontar elimina la suscripción de sesión y no resucita una lectura anterior', async () => {
  let finish; const load = vi.fn(() => new Promise(resolve => { finish = resolve; }));
  const view = renderHook(() => useClinicaQuery(load, { id: '41' }), { wrapper: Wrapper }); view.unmount();
  await act(async () => { avanzarSesion(); finish({ diagnostico: 'NO-MOSTRAR' }); }); expect(load).toHaveBeenCalledOnce(); expect(load.mock.calls[0][1].aborted).toBe(true);
});
