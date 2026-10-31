import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { beforeEach, expect, it, vi } from 'vitest';

const source = readFileSync('public/push/sw.js', 'utf8');
const destino = 'https://clinica.example/recordatorios';
let handlers, clients, showNotification;
beforeEach(() => {
  handlers = {};
  clients = { matchAll: vi.fn().mockResolvedValue([]), openWindow: vi.fn().mockResolvedValue(null) };
  showNotification = vi.fn().mockResolvedValue(undefined);
  // Sin fetch, caches, almacenamiento, PushManager ni API: solo las capacidades permitidas.
  runInNewContext(source, { URL, self: {
    location: { origin: 'https://clinica.example' }, registration: { showNotification }, clients,
    addEventListener: (type, listener) => { handlers[type] = listener; },
  } });
});
async function dispatch(type, properties = {}) {
  const waitUntil = vi.fn();
  handlers[type]({ ...properties, waitUntil });
  expect(waitUntil).toHaveBeenCalledTimes(1);
  await waitUntil.mock.calls[0][0];
}
const ventana = (url = destino) => {
  const client = { url, focus: vi.fn().mockResolvedValue(undefined), navigate: vi.fn() };
  client.navigate.mockResolvedValue(client);
  return client;
};
it.each([
  ['real', { titulo: 'Clínica Vida Salud', mensaje: 'Tienes un aviso disponible. Consulta el sistema.' }],
  ['ausente', undefined], ['nulo', null], ['inválido', '{no-json'], ['array', []],
  ['malicioso', { titulo: 'DIAGNÓSTICO-PRIVADO', mensaje: 'SECRETO', url: 'https://ajeno.example', data: { paciente: '21' } }],
])('push %s muestra exclusivamente el mensaje genérico aprobado', async (_kind, payload) => {
  const data = payload === undefined ? undefined : { json: () => {
    if (payload === '{no-json') throw new SyntaxError('PRIVADO');
    return payload;
  } };
  await dispatch('push', { data });
  expect(showNotification).toHaveBeenCalledExactlyOnceWith('Clínica Vida Salud', {
    body: 'Tienes un aviso disponible. Consulta el sistema.',
  });
  expect(clients.matchAll).not.toHaveBeenCalled(); expect(clients.openWindow).not.toHaveBeenCalled();
});
it('solo registra recepción y clic, sin fetch ni ciclo de activación forzada', () => {
  expect(Object.keys(handlers).sort()).toEqual(['notificationclick', 'push']);
});
it('fallo al mostrar no produce reintentos ni promesas rechazadas sin manejar', async () => {
  showNotification.mockRejectedValue(new Error('privado'));
  await dispatch('push'); expect(showNotification).toHaveBeenCalledTimes(1);
});
it.each(['https://ajeno.example', '//ajeno.example', 'javascript:alert(1)', '/administracion', '/recordatorios?id_paciente=21'])('clic ignora destino %s y datos obsoletos', async url => {
  const close = vi.fn();
  await dispatch('notificationclick', { notification: { close, data: { url, id_paciente: '21', vigente: true } }, action: url });
  expect(close).toHaveBeenCalledTimes(1);
  expect(clients.matchAll).toHaveBeenCalledExactlyOnceWith({ type: 'window', includeUncontrolled: true });
  expect(clients.openWindow).toHaveBeenCalledExactlyOnceWith(destino);
});
it('recarga y enfoca recordatorios no controlado para consultar sesión/API vigentes', async () => {
  const actual = ventana(); clients.matchAll.mockResolvedValue([actual]);
  await dispatch('notificationclick', { notification: { close: vi.fn() } });
  expect(actual.navigate).toHaveBeenCalledExactlyOnceWith(destino);
  expect(actual.focus).toHaveBeenCalledTimes(1); expect(clients.openWindow).not.toHaveBeenCalled();
  expect(actual.navigate.mock.invocationCallOrder[0]).toBeLessThan(actual.focus.mock.invocationCallOrder[0]);
});
it('no enfoca ni navega ventanas ajenas, otras pantallas o URLs con filtros/fragmentos', async () => {
  const otras = ['https://ajeno.example/recordatorios', 'https://clinica.example/mi-perfil', `${destino}?id_paciente=21`, `${destino}#privado`].map(ventana);
  clients.matchAll.mockResolvedValue(otras);
  await dispatch('notificationclick', { notification: { close: vi.fn() } });
  for (const client of otras) { expect(client.navigate).not.toHaveBeenCalled(); expect(client.focus).not.toHaveBeenCalled(); }
  expect(clients.openWindow).toHaveBeenCalledExactlyOnceWith(destino);
});
it.each(['rechazo', 'null'])('si la ventana desaparece al navegar (%s), abre solo el destino fijo', async failure => {
  const actual = ventana(); clients.matchAll.mockResolvedValue([actual]);
  if (failure === 'rechazo') actual.navigate.mockRejectedValue(new Error('cerrada'));
  else actual.navigate.mockResolvedValue(null);
  await dispatch('notificationclick', { notification: { close: vi.fn() } });
  expect(actual.focus).not.toHaveBeenCalled(); expect(clients.openWindow).toHaveBeenCalledExactlyOnceWith(destino);
});
it('fallo de foco no duplica la ventana ya navegada', async () => {
  const actual = ventana(); actual.focus.mockRejectedValue(new Error('sin foco')); clients.matchAll.mockResolvedValue([actual]);
  await dispatch('notificationclick', { notification: { close: vi.fn() } });
  expect(clients.openWindow).not.toHaveBeenCalled();
});
it('fallo de listado/apertura se resuelve sin logs privados ni reintentos', async () => {
  clients.matchAll.mockRejectedValue(new Error('privado')); clients.openWindow.mockRejectedValue(new Error('privado'));
  await dispatch('notificationclick', { notification: { close: vi.fn() } });
  expect(clients.openWindow).toHaveBeenCalledExactlyOnceWith(destino);
});
