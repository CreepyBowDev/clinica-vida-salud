import { expect, it } from 'vitest';
import { DEMOSTRACION_PAGO } from '../../utils/demostracionPagoFixture';
import { validarFormularioDemo } from '../../utils/demostracionPagoValidacion';

it.each(['stripe', 'paypal'])('%s acepta fixture sin mutarla', proveedor => {
  expect(validarFormularioDemo(proveedor, DEMOSTRACION_PAGO[proveedor])).toEqual({});
  expect(Object.isFrozen(DEMOSTRACION_PAGO[proveedor])).toBe(true);
});
it.each(['00', '13', '-1', 'ab'])('rechaza mes %s', mes => {
  expect(validarFormularioDemo('stripe', { ...DEMOSTRACION_PAGO.stripe, mes })).toHaveProperty('mes');
});
it('admite cambio de medio ficticio pero no valores arbitrarios', () => {
  expect(validarFormularioDemo('paypal', { ...DEMOSTRACION_PAGO.paypal, medio: 'tarjeta-demo' })).toEqual({});
  expect(validarFormularioDemo('paypal', { ...DEMOSTRACION_PAGO.paypal, medio: 'real' })).toHaveProperty('medio');
});
it.each(['stripe', 'paypal'])('%s rechaza campos vacíos y espacios', proveedor => {
  const vacios = Object.fromEntries(Object.keys(DEMOSTRACION_PAGO[proveedor]).map(k => [k, '   ']));
  expect(Object.keys(validarFormularioDemo(proveedor, vacios)).sort()).toEqual(Object.keys(vacios).sort());
});
