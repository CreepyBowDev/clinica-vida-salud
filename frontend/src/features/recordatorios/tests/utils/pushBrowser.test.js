import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { suscribirNavegador } from '../../utils/pushBrowser';

const clave = Uint8Array.from([4, ...Array(64).fill(1)]);
let worker, registration, manager, register, controller, comprobar;
beforeEach(() => {
  worker = new EventTarget(); worker.state = 'installing';
  manager = { getSubscription: vi.fn().mockResolvedValue(null), subscribe: vi.fn().mockResolvedValue({ toJSON: () => ({ endpoint: 'ficticio' }) }) };
  registration = { installing: worker, pushManager: manager };
  register = vi.fn().mockResolvedValue(registration);
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register } });
  controller = new AbortController(); comprobar = () => { if (controller.signal.aborted) throw new Error('Interrumpida'); };
});
afterEach(() => { delete navigator.serviceWorker; vi.useRealTimers(); });
it('espera activación del worker antes de suscribir y no necesita controlar la página', async () => {
  const result = suscribirNavegador(clave, controller.signal, comprobar);
  await vi.waitFor(() => expect(register).toHaveBeenCalled()); expect(manager.subscribe).not.toHaveBeenCalled();
  worker.state = 'activated'; worker.dispatchEvent(new Event('statechange'));
  expect(await result).toEqual({ endpoint: 'ficticio' }); expect(manager.subscribe).toHaveBeenCalledTimes(1);
});
it.each(['redundant', 'abort', 'sinWorker'])('rechaza activación %s sin suscribir', async kind => {
  if (kind === 'sinWorker') registration.installing = null;
  const result = suscribirNavegador(clave, controller.signal, comprobar);
  const rejected = expect(result).rejects.toThrow();
  await vi.waitFor(() => expect(register).toHaveBeenCalled());
  if (kind === 'abort') controller.abort();
  if (kind === 'redundant') { worker.state = 'redundant'; worker.dispatchEvent(new Event('statechange')); }
  await rejected; expect(manager.subscribe).not.toHaveBeenCalled();
});
it('acota la espera de activación y no deja suscripción tardía', async () => {
  vi.useFakeTimers(); const result = suscribirNavegador(clave, controller.signal, comprobar);
  const rejected = expect(result).rejects.toThrow(); await vi.advanceTimersByTimeAsync(15000); await rejected;
  worker.state = 'activated'; worker.dispatchEvent(new Event('statechange')); expect(manager.subscribe).not.toHaveBeenCalled();
});
