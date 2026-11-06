export const O = '11111111-1111-4111-8111-111111111111';
export const I = '22222222-2222-4222-8222-222222222222';
export const C = '33333333-3333-4333-8333-333333333333';
export const KEY = '44444444-4444-4444-8444-444444444444';
export const at = '2026-11-06T12:00:00.000000Z';
export const precio = { monto_bob: '70.00', centavos_bob: 7000, moneda_original: 'BOB', tasa_bob_por_usd: '7.00', monto_usd: '10.00', centavos_usd: 1000, moneda_cobro: 'USD' };
export const pagina = (data = [], extra = {}) => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, calculado_at: at, ...extra } });
export function obligacion(extra = {}) { return { id: O, cita: { id_cita: '9', codigo_cita: 'CITA-FIN-FICTICIA', inicio_at: at, estado: 'Programada' },
  precio, emitida_at: at, vencimiento_at: at, exigible: true, liquidada: false, vencida: false, operacion_en_verificacion: false,
  puede_iniciar_pago: true, revision_pago: 'revision-opaca', proveedores_disponibles: ['stripe', 'paypal'], intento_actual: null, ...extra }; }
export function detalle(extra = {}) { return { data: { ...obligacion(), intentos: pagina(), devoluciones: pagina(), ...extra }, meta: { calculado_at: at } }; }
export function operacion(extra = {}) { return { id: I, obligacion_id: O, proveedor: 'stripe', estado: 'RequiereAccion', aplicado: false, accion: { tipo: 'Redirigir', url: 'https://checkout.stripe.com/c/pay/test-ficticio' },
  obligacion: { id: O, exigible: true, liquidada: false }, cobro: null, devolucion: null, puede_reintentar: false, operacion_en_verificacion: false, ultima_verificacion_at: at, ...extra }; }
export function intento(extra = {}) { return { id: I, obligacion_id: O, proveedor: 'stripe', estado: 'Exitoso', creado_at: at, actualizado_at: at, centavos_usd: 1000, moneda: 'USD', cobros: [], ...extra }; }
