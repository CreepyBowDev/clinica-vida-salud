export function soportePush() {
  if (!window.isSecureContext) return 'Las notificaciones requieren un contexto seguro (HTTPS o localhost).';
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    return 'Este navegador no admite Web Push. En algunas plataformas debes instalar la web en la pantalla de inicio.';
  }
  return '';
}

function esperarActivacion(registration, signal) {
  if (registration.active?.state === 'activated') return Promise.resolve();
  const worker = registration.installing || registration.waiting || registration.active;
  if (!worker) return Promise.reject(new Error('Service Worker no disponible.'));
  return new Promise((resolve, reject) => {
    const finish = error => {
      clearTimeout(timer); worker.removeEventListener('statechange', changed); signal.removeEventListener('abort', aborted);
      if (error) reject(error); else resolve();
    };
    const changed = () => {
      if (worker.state === 'activated') finish();
      else if (worker.state === 'redundant') finish(new Error('Service Worker no disponible.'));
    };
    const aborted = () => finish(new Error('Operación interrumpida.'));
    const timer = setTimeout(() => finish(new Error('No se pudo activar el Service Worker.')), 15000);
    worker.addEventListener('statechange', changed); signal.addEventListener('abort', aborted, { once: true });
    if (signal.aborted) aborted(); else changed();
  });
}

export async function suscribirNavegador(clave, signal, comprobar) {
  comprobar();
  const scope = `${import.meta.env.BASE_URL}push/`;
  const registration = await navigator.serviceWorker.register(`${scope}sw.js`, { scope, updateViaCache: 'none' });
  comprobar();
  await esperarActivacion(registration, signal);
  comprobar();
  let subscription = await registration.pushManager.getSubscription();
  comprobar();
  if (subscription) {
    const actual = subscription.options?.applicationServerKey;
    if (!actual || new Uint8Array(actual).length !== clave.length || new Uint8Array(actual).some((v, i) => v !== clave[i])) {
      throw new Error('La suscripción existente requiere revisión.');
    }
  } else subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: clave });
  comprobar();
  return subscription.toJSON();
}

export async function desuscribirNavegador(comprobar) {
  comprobar();
  if (!window.isSecureContext || !navigator.serviceWorker?.getRegistration) throw new Error('No se puede comprobar la baja local.');
  const scope = new URL(`${import.meta.env.BASE_URL}push/`, window.location.origin).href;
  const registration = await navigator.serviceWorker.getRegistration(scope);
  comprobar();
  if (!registration) return;
  // getRegistration puede devolver un worker de un scope padre: no modificarlo.
  if (registration.scope !== scope || !registration.pushManager) throw new Error('No se puede comprobar la baja local.');
  const subscription = await registration.pushManager.getSubscription();
  comprobar();
  if (!subscription) return;
  const removed = await subscription.unsubscribe();
  comprobar();
  if (removed !== true) throw new Error('Baja local no confirmada.');
}
