import { act, cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';
import { destinoSeguro, ROLES } from '../../../../constants/roles';
import { DEMOSTRACION_PAGO } from '../../utils/demostracionPagoFixture';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let requests, storage, xhr, open;
const notice = 'Demostración académica con datos ficticios. No realiza cobros ni modifica citas o pagos';
function mount(path = '/pagos/demostracion') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  const view = render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return { router, ...view };
}
const button = name => screen.getByRole('button', { name, exact: true });
async function click(name) {
  await userEvent.click(button(name));
  expect(screen.getByText(notice)).toBeVisible();
  expect(screen.getByText('No introduzcas tarjetas, contraseñas ni credenciales reales')).toBeVisible();
}
async function start(proveedor = 'stripe', escenario = 'completado') {
  await screen.findByRole('heading', { name: 'Seleccionar recorrido ficticio' });
  await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), proveedor);
  await userEvent.selectOptions(screen.getByLabelText('Escenario ficticio'), escenario);
  expect(screen.queryByRole('button', { name: 'Iniciar pago simulado' })).not.toBeInTheDocument();
  await click('Revisar inicio simulado');
  expect(screen.getByRole('heading', { name: 'Revisar inicio simulado' })).toHaveFocus();
  expect(screen.getByRole('region', { name: 'Confirmar importe ficticio' })).toHaveTextContent('USD 10,00');
  await click('Iniciar pago simulado');
  await click('Representar salida al proveedor (simulada)');
}
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'demo@example.test', rol: ROLES.PACIENTE });
  requests = vi.fn(() => { throw new Error('Prohibida cualquier petición de negocio en demo'); });
  api.defaults.adapter = requests;
  vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Red bloqueada'))));
  storage = ['getItem', 'setItem', 'removeItem', 'clear'].map(method => vi.spyOn(Storage.prototype, method));
  xhr = vi.spyOn(XMLHttpRequest.prototype, 'send'); open = vi.spyOn(window, 'open');
});
afterEach(() => {
  try {
  expect(requests).not.toHaveBeenCalled();
  expect(fetch).not.toHaveBeenCalled(); expect(xhr).not.toHaveBeenCalled(); expect(open).not.toHaveBeenCalled();
  // createMemoryRouter lee esta clave interna al construirse; la demo no persiste ni lee datos.
  expect(storage[0].mock.calls.every(args => args.length === 1 && args[0] === 'remix-router-transitions')).toBe(true);
  storage.slice(1).forEach(spy => expect(spy).not.toHaveBeenCalled());
  expect(document.querySelector('iframe, script[src*="stripe"], script[src*="paypal"]')).toBeNull();
  expect([...document.querySelectorAll('a[href]')].every(a => !/^(https?:)?\/\//.test(a.getAttribute('href')))).toBe(true);
  } finally {
    cleanup(); api.defaults.adapter = original; vi.useRealTimers(); vi.restoreAllMocks(); vi.unstubAllGlobals();
  }
});

it.each([ROLES.PACIENTE, ROLES.ADMINISTRADOR])('%s tiene acceso propio sin suplantación ni consulta de datos', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'demo@example.test', rol });
  mount('/pagos/demostracion?obligacion=REAL&proveedor=paypal&resultado=exitoso#captura');
  await screen.findByRole('heading', { name: 'Seleccionar recorrido ficticio' });
  expect(consultarSesion).toHaveBeenCalledTimes(1);
  expect(screen.getByText(/sin suplantar al Paciente/)).toBeInTheDocument();
  expect(screen.getByLabelText('Proveedor simulado')).toHaveValue('stripe');
  expect(screen.getByRole('link', { name: 'Demostración de pagos' })).toHaveAttribute('href', '/pagos/demostracion');
  expect(screen.getByRole('link', { name: 'Consultar pagos' })).toHaveAttribute('href', '/pagos');
  expect(destinoSeguro('/pagos/demostracion', rol)).toBe('/pagos/demostracion');
  const fixture = screen.getByRole('region', { name: 'Obligación ficticia' });
  expect(fixture).toHaveTextContent(DEMOSTRACION_PAGO.codigo_cita);
  expect(within(fixture).getByText('Bs 70,00')).toBeVisible();
  expect(within(fixture).getByText('USD 10,00')).toBeVisible();
  expect(fixture).not.toHaveTextContent('REAL');
  expect(BigInt(DEMOSTRACION_PAGO.precio.monto_bob.replace('.', ''))).toBe(7n * BigInt(DEMOSTRACION_PAGO.precio.monto_usd.replace('.', '')));
});
it.each([ROLES.RECEPCIONISTA, ROLES.MEDICO])('%s conserva denegación y navegación sin demo ni pagos', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'demo@example.test', rol });
  mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(screen.queryByRole('link', { name: 'Demostración de pagos' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Consultar pagos' })).not.toBeInTheDocument();
  expect(destinoSeguro('/pagos/demostracion', rol)).toBe('/inicio');
});
it('visitante pasa por login conservando destino, sin mostrar fixture', async () => {
  consultarSesion.mockRejectedValue({ response: { status: 401 } });
  const { router } = mount(); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(router.state.location.pathname).toBe('/login'); expect(router.state.location.state.from).toBe('/pagos/demostracion');
  expect(screen.queryByText(DEMOSTRACION_PAGO.codigo_cita)).not.toBeInTheDocument();
});
it('sesión restringida mantiene el guard de cambio obligatorio', async () => {
  consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  const { router } = mount();
  await screen.findByRole('heading', { name: 'Cambia tu contraseña' });
  expect(router.state.location.pathname).toBe('/clave-obligatoria');
  expect(screen.queryByText(DEMOSTRACION_PAGO.codigo_cita)).not.toBeInTheDocument();
});
it('Stripe exige revisión e inicio; retorno no implica éxito y solo la consulta muestra el resultado ficticio', async () => {
  mount(); await start();
  expect(screen.getByRole('heading', { name: 'Salida al proveedor simulada' })).toHaveFocus();
  await click('Simular retorno al portal');
  expect(screen.getByText(/El retorno simulado no acredita éxito/)).toBeInTheDocument();
  expect(screen.queryByText(/Pago completado simulado por/)).not.toBeInTheDocument();
  await click('Consultar resultado ficticio');
  expect(screen.getByText(/Pago completado simulado por USD 10,00/)).toHaveTextContent('no verificado por Stripe');
});
it('PayPal separa aprobación, retorno, consulta, revisión, captura humana por teclado y resultado', async () => {
  mount(); await start('paypal'); await click('Simular aprobación PayPal');
  expect(screen.getByText(/Aprobación ficticia concedida/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Simular captura PayPal' })).not.toBeInTheDocument();
  await click('Simular retorno al portal'); await click('Consultar resultado ficticio');
  expect(screen.getByText(/No hubo captura automática/)).toBeInTheDocument();
  expect(screen.queryByText(/Pago completado simulado por/)).not.toBeInTheDocument();
  await click('Revisar captura PayPal simulada');
  expect(screen.getByRole('region', { name: 'Confirmar captura ficticia PayPal' })).toHaveTextContent('USD 10,00');
  button('Simular captura PayPal').focus(); await userEvent.keyboard('{Enter}');
  expect(screen.getByRole('heading', { name: 'Captura humana simulada solicitada' })).toHaveFocus();
  expect(screen.queryByText(/Pago completado simulado por/)).not.toBeInTheDocument();
  await click('Consultar resultado ficticio de captura');
  expect(screen.getByText(/Pago completado simulado por USD 10,00/)).toHaveTextContent('no verificado por PayPal');
});
it.each(['stripe', 'paypal'])('%s pendiente permanece sin éxito, polling, captura ni nuevo inicio', async proveedor => {
  mount(); await start(proveedor, 'pendiente');
  if (proveedor === 'paypal') await click('Simular aprobación PayPal');
  await click('Simular retorno al portal'); await click('Consultar resultado ficticio');
  vi.useFakeTimers(); act(() => vi.advanceTimersByTime(120000));
  expect(screen.getByRole('heading', { name: 'Resultado ficticio: verificación pendiente' })).toBeInTheDocument();
  expect(vi.getTimerCount()).toBe(0);
  expect(screen.queryByText(/Pago completado simulado por/)).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /captur|Iniciar pago simulado/ })).not.toBeInTheDocument();
});
it('volver a seleccionar invalida la revisión; reiniciar y remontar descartan memoria sin persistir', async () => {
  const view = mount(); await screen.findByLabelText('Proveedor simulado');
  await click('Revisar inicio simulado'); await click('Volver a selección ficticia');
  await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), 'paypal');
  expect(screen.queryByRole('button', { name: 'Iniciar pago simulado' })).not.toBeInTheDocument();
  await start('paypal'); await click('Simular aprobación PayPal'); await click('Reiniciar demostración');
  expect(screen.getByRole('heading', { name: 'Seleccionar recorrido ficticio' })).toHaveFocus();
  expect(screen.getByLabelText('Proveedor simulado')).toHaveValue('stripe');
  await start('paypal'); view.unmount(); mount(); await screen.findByLabelText('Proveedor simulado');
  expect(screen.getByLabelText('Proveedor simulado')).toHaveValue('stripe');
  expect(screen.getByLabelText('Escenario ficticio')).toHaveValue('completado');
});

const camposStripe = [
  ['Nombre del titular ficticio', 'titular'], ['Correo ficticio', 'correo'], ['Número de tarjeta ficticio', 'tarjeta'],
  ['Mes de vencimiento ficticio', 'mes'], ['Año de vencimiento ficticio', 'anio'], ['Código de seguridad ficticio', 'seguridad'],
];
const camposPaypal = [['Correo de comprador ficticio', 'correo'], ['Clave exclusivamente de demostración', 'clave'], ['Medio de pago ficticio', 'medio']];
it.each(['stripe', 'paypal'])('%s precarga exclusivamente fixtures y explica límites del formulario', async proveedor => {
  mount(); await screen.findByLabelText('Proveedor simulado');
  await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), proveedor);
  for (const [label, campo] of proveedor === 'stripe' ? camposStripe : camposPaypal) expect(screen.getByLabelText(label)).toHaveValue(DEMOSTRACION_PAGO[proveedor][campo]);
  expect(screen.getByText(/Solo se comprueba formato local/)).toBeVisible();
  expect(screen.getByText(proveedor === 'stripe' ? /Stripe Checkout alojado/ : /la clínica no recibe las credenciales/)).toBeVisible();
});
it.each([...camposStripe.map(([label, campo]) => ['stripe', label, campo]), ...camposPaypal.map(([label, campo]) => ['paypal', label, campo])])(
  '%s bloquea avance sin %s, enfoca error y permite corregir', async (proveedor, label, campo) => {
    mount(); await screen.findByLabelText('Proveedor simulado');
    await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), proveedor);
    const input = screen.getByLabelText(label);
    if (campo === 'medio') await userEvent.selectOptions(input, ''); else await userEvent.clear(input);
    await click('Revisar inicio simulado');
    expect(input).toHaveAttribute('aria-invalid', 'true'); expect(input).toHaveFocus();
    expect(input).toHaveAccessibleDescription(/Completa este campo ficticio obligatorio/);
    expect(screen.queryByRole('button', { name: 'Iniciar pago simulado' })).not.toBeInTheDocument();
    if (campo === 'medio') await userEvent.selectOptions(input, DEMOSTRACION_PAGO[proveedor][campo]); else await userEvent.type(input, DEMOSTRACION_PAGO[proveedor][campo]);
    await click('Revisar inicio simulado');
    expect(screen.getByRole('heading', { name: 'Revisar inicio simulado' })).toHaveFocus();
  },
);
it.each([
  ['stripe', 'Correo ficticio', 'correo-invalido'], ['stripe', 'Correo ficticio', 'persona@dominio.test'],
  ['stripe', 'Nombre del titular ficticio', 'A'], ['stripe', 'Número de tarjeta ficticio', '1111'],
  ['stripe', 'Mes de vencimiento ficticio', '13'], ['stripe', 'Año de vencimiento ficticio', '2029'],
  ['stripe', 'Año de vencimiento ficticio', 'abcd'], ['stripe', 'Código de seguridad ficticio', 'ab'],
  ['paypal', 'Correo de comprador ficticio', 'correo-invalido'], ['paypal', 'Clave exclusivamente de demostración', 'no-demo'],
])('%s rechaza formato básico inválido en %s', async (proveedor, label, value) => {
  mount(); await screen.findByLabelText('Proveedor simulado'); await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), proveedor);
  const input = screen.getByLabelText(label); await userEvent.clear(input); await userEvent.type(input, value);
  await click('Revisar inicio simulado');
  expect(input).toHaveFocus(); expect(input).toHaveAttribute('aria-invalid', 'true');
  expect(screen.queryByRole('button', { name: 'Iniciar pago simulado' })).not.toBeInTheDocument();
});
it('edita antes de iniciar y reinicia valores/errores de ambos formularios aun estando en selección', async () => {
  mount(); await screen.findByLabelText('Nombre del titular ficticio');
  await userEvent.clear(screen.getByLabelText('Nombre del titular ficticio')); await userEvent.type(screen.getByLabelText('Nombre del titular ficticio'), 'Otro nombre ficticio');
  await click('Revisar inicio simulado'); await click('Volver a selección ficticia');
  expect(screen.getByLabelText('Nombre del titular ficticio')).toHaveValue('Otro nombre ficticio');
  await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), 'paypal');
  await userEvent.clear(screen.getByLabelText('Clave exclusivamente de demostración')); await click('Revisar inicio simulado');
  await click('Reiniciar demostración');
  expect(screen.getByLabelText('Nombre del titular ficticio')).toHaveValue(DEMOSTRACION_PAGO.stripe.titular);
  await userEvent.clear(screen.getByLabelText('Correo ficticio')); await click('Revisar inicio simulado'); await click('Reiniciar demostración');
  expect(screen.getByLabelText('Correo ficticio')).toHaveValue(DEMOSTRACION_PAGO.stripe.correo);
  expect(screen.getByLabelText('Correo ficticio')).toHaveAttribute('aria-invalid', 'false');
  await userEvent.selectOptions(screen.getByLabelText('Proveedor simulado'), 'paypal');
  expect(screen.getByLabelText('Clave exclusivamente de demostración')).toHaveValue(DEMOSTRACION_PAGO.paypal.clave);
});
