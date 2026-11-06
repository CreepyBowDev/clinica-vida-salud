import { expect, it, vi } from 'vitest';
import { decimalVisible, limpiarRetornoPago, montoEntrada, situacionObligacion, usdCentavos, urlProveedor } from '../../utils/pagoFormat';
import { destinoSeguro } from '../../../../constants/roles';
import { O, I } from './pagoFixtures';

it.each([['7', '7.00'], ['7,5', '7.50'], ['7000.00', '7000.00'], ['70.01', '70.01']])('monto exacto %s', (v, expected) => expect(montoEntrada(v)).toBe(expected));
it.each([' 7', '7 ', '07', '7.001', '7000.01', '6.99', '-7', '1e2', '7.000,00', '7,000', '', 70])('rechaza sin redondear %s', v => expect(() => montoEntrada(v)).toThrow());
it('formatea históricos sin calcular liquidación ni conversión', () => { expect(decimalVisible('7000.01')).toBe('7.000,01'); expect(usdCentavos(101)).toBe('USD 1,01'); });
it.each([[{ exigible: false, liquidada: true }, 'No exigible'], [{ exigible: true, liquidada: true }, 'Pagada'], [{ exigible: true, vencida: true }, 'Vencida regularizable'], [{ exigible: true }, 'Pendiente de pago']])('situación desde indicadores del servidor', (o, expected) => expect(situacionObligacion(o)).toBe(expected));
it.each(['javascript:alert(1)', 'https://checkout.stripe.com.evil.test/c', 'https://user@checkout.stripe.com/c', 'https://checkout.stripe.com:8443/c', 'https://www.paypal.com/c', 'https://www.sandbox.paypal.com/#token'])('rechaza acción externa incompatible %s', url => expect(urlProveedor({ tipo: 'Redirigir', url }, 'stripe')).toBeNull());
it('solo retiene UUID local y retira parámetros de retorno externos', () => {
  // Un fragmento de Checkout corroborado por el backend no es un parámetro de retorno al portal.
  expect(urlProveedor({ tipo: 'Redirigir', url: 'https://checkout.stripe.com/c/pay/ficticio#fragmento-del-proveedor' }, 'stripe'))
    .toBe('https://checkout.stripe.com/c/pay/ficticio#fragmento-del-proveedor');
  expect(urlProveedor({ tipo: 'Redirigir', url: 'https://www.sandbox.paypal.com/checkoutnow?token=ficticio#no-admitido' }, 'paypal')).toBeNull();
  const history = { replaceState: vi.fn() };
  limpiarRetornoPago({ pathname: '/pagos/retorno/paypal', search: `?intento=${I}&token=SECRETO&PayerID=EXTERNO`, hash: '#secreto' }, history);
  expect(history.replaceState).toHaveBeenCalledWith(null, '', `/pagos/retorno/paypal/${I}`);
  limpiarRetornoPago({ pathname: '/pagos/retorno/stripe', search: '?session_id=falso', hash: '' }, history);
  expect(history.replaceState).toHaveBeenLastCalledWith(null, '', '/pagos/retorno/invalido');
});
it('login conserva solo destinos financieros locales del rol', () => {
  expect(destinoSeguro(`/pagos/retorno/paypal/${I}`, 'Paciente')).toBe(`/pagos/retorno/paypal/${I}`);
  expect(destinoSeguro(`/pagos/obligaciones/${O}`, 'Recepcionista')).toBe('/inicio');
  expect(destinoSeguro(`/pagos/pacientes/9/obligaciones/${O}`, 'Administrador')).toBe(`/pagos/pacientes/9/obligaciones/${O}`);
  expect(destinoSeguro(`/pagos/pacientes/9/obligaciones/${O}`, 'Paciente')).toBe('/pagos');
});
