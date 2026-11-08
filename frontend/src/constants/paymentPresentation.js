import { matchPath } from 'react-router-dom';
import { ROLES } from './roles';

export const PAYMENT_VIEWS = {
  obligaciones: { label: 'Consultar obligaciones de pago propias', cu: 'CU-31' },
  pagar: { label: 'Pagar una cita', cu: 'CU-32' },
  historial: { label: 'Consultar historial de pagos propio', cu: 'CU-33' },
};
export const PAYMENT_PATTERNS = ['/pagos', '/pagos/obligaciones/:id', '/pagos/obligaciones/:id/pagar', '/pagos/pacientes/:pacienteId', '/pagos/pacientes/:pacienteId/obligaciones/:id', '/pagos/retorno/:proveedor/:intento?', '/pagos/demostracion'];

// Presentación solamente: no se transmite a los servicios financieros.
export function paymentPresentation(pathname, search, rol) {
  const params = new URLSearchParams(search), values = params.getAll('vista');
  const adminPath = ['/pagos/pacientes/:pacienteId', '/pagos/pacientes/:pacienteId/obligaciones/:id'].some(p => matchPath(p, pathname));
  const fixedPayment = ['/pagos/obligaciones/:id/pagar', '/pagos/retorno/:proveedor/:intento?'].some(p => matchPath(p, pathname));
  const demo = Boolean(matchPath('/pagos/demostracion', pathname));
  const known = PAYMENT_PATTERNS.some(p => matchPath(p, pathname));
  const value = values[0] ?? null;
  const actor = rol === ROLES.ADMINISTRADOR ? !fixedPayment : rol === ROLES.PACIENTE && !adminPath;
  const valid = known && actor && values.length <= 1 && (!values.length ||
    (!demo && (rol === ROLES.ADMINISTRADOR ? ['obligaciones', 'historial'].includes(value) : Object.hasOwn(PAYMENT_VIEWS, value)) && (!fixedPayment || value === 'pagar')));
  return { valid, value: valid ? value : null, search: valid && value ? `?vista=${value}` : '',
    label: rol === ROLES.ADMINISTRADOR ? 'Consultar historial financiero de un Paciente' : PAYMENT_VIEWS[value]?.label };
}
