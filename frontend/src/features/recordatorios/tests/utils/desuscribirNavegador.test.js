import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { desuscribirNavegador } from '../../utils/pushBrowser';

let registration, subscription, getRegistration, comprobar, controller;
beforeEach(() => {
  vi.stubGlobal('isSecureContext', true);
  subscription = { unsubscribe: vi.fn().mockResolvedValue(true) };
  registration = { scope: new URL('/push/', location.origin).href, pushManager: { getSubscription: vi.fn().mockResolvedValue(subscription) } };
  getRegistration = vi.fn().mockResolvedValue(registration);
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { getRegistration } });
  controller = new AbortController(); comprobar = () => { if (controller.signal.aborted) throw new Error('Sesión cambiada'); };
});
afterEach(() => { delete navigator.serviceWorker; vi.unstubAllGlobals(); });
it('consulta solo el scope existente y desuscribe sin registrar ni pedir permiso', async () => {
  await desuscribirNavegador(comprobar);
  expect(getRegistration).toHaveBeenCalledWith(registration.scope); expect(subscription.unsubscribe).toHaveBeenCalledTimes(1);
});
it.each(['sinRegistro', 'sinSuscripcion'])('confirma ausencia local %s', async kind => {
  if (kind === 'sinRegistro') getRegistration.mockResolvedValue(undefined);
  else registration.pushManager.getSubscription.mockResolvedValue(null);
  await desuscribirNavegador(comprobar); expect(subscription.unsubscribe).not.toHaveBeenCalled();
});
it.each(['inseguro', 'sinSoporte', 'padre', 'falloConsulta', 'falloBaja', 'bajaFalse'])('no confirma baja local %s', async kind => {
  if (kind === 'inseguro') vi.stubGlobal('isSecureContext', false);
  if (kind === 'sinSoporte') delete navigator.serviceWorker;
  if (kind === 'padre') registration.scope = new URL('/', location.origin).href;
  if (kind === 'falloConsulta') registration.pushManager.getSubscription.mockRejectedValue(new Error('fallo'));
  if (kind === 'falloBaja') subscription.unsubscribe.mockRejectedValue(new Error('fallo'));
  if (kind === 'bajaFalse') subscription.unsubscribe.mockResolvedValue(false);
  await expect(desuscribirNavegador(comprobar)).rejects.toThrow();
  if (kind === 'padre') expect(subscription.unsubscribe).not.toHaveBeenCalled();
});
it.each(['registro', 'suscripcion', 'baja'])('descarta cambio de sesión durante %s', async stage => {
  if (stage === 'registro') getRegistration.mockImplementation(async () => { controller.abort(); return registration; });
  if (stage === 'suscripcion') registration.pushManager.getSubscription.mockImplementation(async () => { controller.abort(); return subscription; });
  if (stage === 'baja') subscription.unsubscribe.mockImplementation(async () => { controller.abort(); return true; });
  await expect(desuscribirNavegador(comprobar)).rejects.toThrow();
  expect(subscription.unsubscribe).toHaveBeenCalledTimes(stage === 'baja' ? 1 : 0);
});
