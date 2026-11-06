export const ESTADOS_INTENTO = ['Preparando', 'RequiereAccion', 'Procesando', 'Exitoso', 'Fallido', 'Cerrado', 'Incierto'];
export const PROVEEDORES = { stripe: 'Stripe Checkout test', paypal: 'PayPal Sandbox', simulado: 'Simulación académica' };
export const esUuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value);
export function montoEntrada(value) {
  if (typeof value !== 'string' || !/^(?:0|[1-9][0-9]{0,3})(?:[.,][0-9]{1,2})?$/.test(value)) throw new Error('Introduce un monto entre Bs 7,00 y Bs 7.000,00, sin miles y con hasta dos decimales.');
  const [entero, decimal = ''] = value.replace(',', '.').split('.');
  const centavos = BigInt(entero) * 100n + BigInt(decimal.padEnd(2, '0'));
  if (centavos < 700n || centavos > 700000n) throw new Error('El monto debe estar entre Bs 7,00 y Bs 7.000,00.');
  return `${entero}.${decimal.padEnd(2, '0')}`;
}
export function decimalVisible(value) {
  if (typeof value !== 'string' || !/^(0|[1-9]\d*)\.\d{2}$/.test(value)) return 'No disponible';
  const [entero, decimal] = value.split('.');
  return `${entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimal}`;
}
export function usdCentavos(value) {
  if (!Number.isSafeInteger(value) || value < 0) return 'No disponible';
  const n = BigInt(value);
  return `USD ${decimalVisible(`${n / 100n}.${String(n % 100n).padStart(2, '0')}`)}`;
}
export function situacionObligacion(o) {
  return !o.exigible ? 'No exigible' : o.liquidada ? (o.origen_liquidacion === 'Simulado' ? 'Pagada — simulación académica' : 'Pagada') : o.vencida ? 'Vencida regularizable' : 'Pendiente de pago';
}
export const SITUACIONES_PRECIO = { NoIncorporada: 'No incorporada al módulo financiero', PendienteAsignacion: 'Pendiente de asignación de precio', Asignado: 'Precio asignado' };
export function urlProveedor(accion, proveedor) {
  if (!accion || accion.tipo !== 'Redirigir' || typeof accion.url !== 'string') return null;
  try {
    const url = new URL(accion.url);
    return url.protocol === 'https:' && !url.username && !url.password && !url.port && (proveedor === 'stripe' || !url.hash)
      && url.hostname === ({ stripe: 'checkout.stripe.com', paypal: 'www.sandbox.paypal.com' })[proveedor] ? url.href : null;
  } catch { return null; }
}
// Solo se conserva el localizador local en el pathname: nunca token, PayerID o session_id.
export function limpiarRetornoPago(location = window.location, history = window.history) {
  if (/^\/pagos\/retorno\/(stripe|paypal)\//.test(location.pathname)) {
    if (location.search || location.hash) history.replaceState(null, '', location.pathname);
    return;
  }
  const match = location.pathname.match(/^\/pagos\/retorno\/(stripe|paypal)$/);
  if (!match) return;
  const id = new URLSearchParams(location.search).get('intento');
  history.replaceState(null, '', esUuid(id) ? `${location.pathname}/${id}` : '/pagos/retorno/invalido');
}
