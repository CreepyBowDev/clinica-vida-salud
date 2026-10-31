// CU-28: solo notificación genérica y navegación; sin caché ni interceptación HTTP.
// Coincide con el payload fijo de TransportePush. No interpretar datos recibidos:
// incluso un payload ausente/malformado no puede introducir texto clínico o destinos.
self.addEventListener('push', event => {
  event.waitUntil(self.registration.showNotification('Clínica Vida Salud', {
    body: 'Tienes un aviso disponible. Consulta el sistema.',
  }).catch(() => {}));
});

async function abrirRecordatorios() {
  const destino = new URL('/recordatorios', self.location.origin).href;
  // El scope /push/ no controla las páginas React: incluir ventanas no controladas.
  const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true }).catch(() => []);
  for (const ventana of ventanas) {
    if (ventana.url !== destino) continue;
    // Navegar también si ya está abierta: restaura sesión y consulta la API de nuevo,
    // sin reutilizar avisos visibles ni asumir vigencia a partir de la notificación.
    const actual = await ventana.navigate(destino).catch(() => null);
    if (actual) {
      await actual.focus().catch(() => {});
      return;
    }
  }
  await self.clients.openWindow(destino);
}

self.addEventListener('notificationclick', event => {
  event.notification.close();
  // No usar notification.data, acciones ni URLs del payload como autorización/destino.
  event.waitUntil(abrirRecordatorios().catch(() => {}));
});
