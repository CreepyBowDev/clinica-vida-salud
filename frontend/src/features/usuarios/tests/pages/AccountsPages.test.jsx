import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion } from '../../../auth/services/auth.service';
import { consultarCuenta, crearPersonal, listarCuentas, reenviarInvitacion } from '../../services/usuario.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/usuario.service', async original => ({ ...await original(), consultarCuenta: vi.fn(), crearPersonal: vi.fn(), listarCuentas: vi.fn(), reenviarInvitacion: vi.fn() }));
const person = rol => ({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'admin@example.test', rol });
const staff = { id_usuario: '17', correo_acceso: 'equipo@example.test', nombre_visible: 'Equipo Ficticio', rol: 'Recepcionista', estado_cuenta: 'Pendiente', correo_verificado_en: null };
const error = (status, code, details) => ({ response: { status, data: { error: { code, details, message: 'SQL secreto' } }, headers: {} } });
const listing = (accounts = [staff], current = 1, last = 1) => ({ data: accounts, meta: { total: accounts.length, current_page: current, last_page: last }, links: { next: 'https://ajeno.test/no-seguir' } });
function mount(path = '/administracion/cuentas') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockResolvedValue(person('Administrador')); listarCuentas.mockResolvedValue(listing()); consultarCuenta.mockResolvedValue(staff); });
afterEach(() => vi.useRealTimers());

describe('consulta administrativa', () => {
  it('presentación responsive conserva columnas, privacidad y enlaces de cuentas', async () => {
    const email = 'cuenta-con-correo-extenso-de-prueba@example.test';
    listarCuentas.mockResolvedValue(listing([{ ...staff, correo_acceso: email, rol: 'Paciente', nombre_visible: 'NOMBRE_NO_AUTORIZADO' }]));
    mount(); const table = await screen.findByRole('table');
    expect(table.parentElement).toHaveClass('query-table'); expect(within(table).getAllByRole('columnheader')).toHaveLength(4);
    expect(within(table).getByRole('link', { name: `Ver cuenta ${email}` })).toHaveAttribute('href', '/administracion/cuentas/17');
    expect(table).not.toHaveTextContent('NOMBRE_NO_AUTORIZADO'); expect(table).toHaveTextContent('Paciente'); expect(table).toHaveTextContent('Pendiente');
    expect(screen.getByRole('heading', { name: 'Cuentas de usuario' }).closest('header')).toHaveClass('page-heading--actions'); expect(listarCuentas).toHaveBeenCalledTimes(1);
  });
  it.each(['Recepcionista', 'Médico', 'Paciente'])('deniega rutas y navegación a %s sin consultar la API', async rol => {
    consultarSesion.mockResolvedValue(person(rol));
    mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(listarCuentas).not.toHaveBeenCalled();
    expect(screen.queryByRole('link', { name: 'Cuentas de usuario' })).not.toBeInTheDocument();
  });
  it('aplica filtros solo al enviarlos y pagina con valores meta, sin seguir links', async () => {
    listarCuentas.mockResolvedValue(listing([staff], 1, 2));
    const user = userEvent.setup();
    mount();
    await screen.findByRole('link', { name: `Ver cuenta ${staff.correo_acceso}` });
    await user.selectOptions(screen.getByLabelText('Rol'), 'Paciente');
    await user.type(screen.getByLabelText('Correo de acceso exacto'), 'paciente@example.test');
    expect(listarCuentas).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole('button', { name: 'Aplicar filtros' }));
    await waitFor(() => expect(listarCuentas).toHaveBeenCalledTimes(2));
    expect(listarCuentas.mock.calls[1][0]).toMatchObject({ rol: 'Paciente', correo_acceso: 'paciente@example.test', page: 1 });
    await user.click(screen.getByRole('button', { name: 'Siguiente' }));
    await waitFor(() => expect(listarCuentas).toHaveBeenCalledTimes(3));
    expect(listarCuentas.mock.calls[2][0].page).toBe(2);
  });
  it('cancela e ignora respuesta tardía de filtros anteriores', async () => {
    let resolveOld;
    listarCuentas.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; })).mockResolvedValue(listing([]));
    const user = userEvent.setup();
    mount();
    await screen.findByRole('heading', { name: 'Cuentas de usuario' });
    await user.selectOptions(screen.getByLabelText('Rol'), 'Paciente');
    await user.click(screen.getByRole('button', { name: 'Aplicar filtros' }));
    await screen.findByRole('heading', { name: 'No hay cuentas para estos filtros' });
    expect(listarCuentas.mock.calls[0][1].aborted).toBe(true);
    await act(async () => resolveOld(listing()));
    expect(screen.queryByText(staff.correo_acceso)).not.toBeInTheDocument();
  });
  it.each([403, 500])('maneja consulta HTTP %s sin mostrar cuerpos técnicos ni datos antiguos', async status => {
    listarCuentas.mockRejectedValue(error(status));
    mount();
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });
  it('Paciente solo muestra datos mínimos y no ofrece invitación aunque sea manipulado como pendiente', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, rol: 'Paciente', nombre_visible: 'NO MOSTRAR', id_medico: '99', nombres_personal: 'NO MOSTRAR' });
    mount('/administracion/cuentas/17');
    await screen.findByText(staff.correo_acceso);
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument();
    expect(screen.queryByText('Registro médico vinculado')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reenviar invitación' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /contraseña|rol|activar/i })).not.toBeInTheDocument();
  });
  it('ruta de identificador inválido no consulta la API', async () => {
    mount('/administracion/cuentas/9223372036854775808');
    await screen.findByRole('heading', { name: 'Cuenta no disponible' });
    expect(consultarCuenta).not.toHaveBeenCalled();
  });
  it('404 de detalle conserva respuesta neutra', async () => {
    consultarCuenta.mockRejectedValue(error(404));
    mount('/administracion/cuentas/17');
    expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró una cuenta disponible');
  });
});

describe('alta de personal', () => {
  it.each(['simulado', 'aceptado', 'fallido', 'pendiente'])('crea una sola cuenta y representa envío %s sin afirmar entrega', async envio => {
    crearPersonal.mockResolvedValue({ data: staff, meta: { invitacion: { envio } } });
    const user = userEvent.setup();
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Rol del personal');
    expect(screen.queryByRole('option', { name: 'Paciente' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/contraseña/i)).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Correo de acceso'), 'equipo@example.test');
    await user.type(screen.getByLabelText('Nombres'), 'Equipo');
    await user.type(screen.getByLabelText('Apellidos'), 'Ficticio');
    await user.click(screen.getByRole('button', { name: 'Crear cuenta e invitar' }));
    expect(await screen.findByRole('heading', { name: 'Cuenta del personal creada' })).toHaveFocus();
    expect(crearPersonal).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('link', { name: 'Ver cuenta creada' })).toHaveAttribute('href', '/administracion/cuentas/17');
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent({ simulado: 'No se envió un correo externo', aceptado: 'Esto no confirma su entrega', fallido: 'No se confirmó el envío', pendiente: 'pendiente de confirmación' }[envio]);
  });
  it('Médico usa solo referencia existente y valida BIGINT antes de enviar', async () => {
    const user = userEvent.setup();
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Rol del personal');
    await user.selectOptions(screen.getByLabelText('Rol del personal'), 'Médico');
    expect(screen.queryByLabelText('Nombres')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Correo de acceso'), { target: { value: 'equipo@example.test' } });
    fireEvent.change(screen.getByLabelText('Identificador del registro médico'), { target: { value: '9223372036854775808' } });
    fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Revisa el identificador');
    expect(crearPersonal).not.toHaveBeenCalled();
    crearPersonal.mockResolvedValue({ data: { ...staff, rol: 'Médico' }, meta: { invitacion: { envio: 'simulado' } } });
    fireEvent.change(screen.getByLabelText('Identificador del registro médico'), { target: { value: '9223372036854775807' } });
    fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Cuenta del personal creada' });
    expect(crearPersonal.mock.calls[0][0].id_medico).toBe('9223372036854775807');
  });
  it('deshabilita alta duplicada y conserva campos ante conflicto de correo', async () => {
    let reject;
    crearPersonal.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Rol del personal');
    fireEvent.change(screen.getByLabelText('Correo de acceso'), { target: { value: 'equipo@example.test' } });
    fireEvent.change(screen.getByLabelText('Nombres'), { target: { value: 'Equipo' } });
    fireEvent.change(screen.getByLabelText('Apellidos'), { target: { value: 'Ficticio' } });
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    expect(crearPersonal).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Creando cuenta…' })).toBeDisabled();
    await act(async () => reject(error(409, 'PERSONAL_CORREO_NO_DISPONIBLE')));
    expect(await screen.findByRole('alert')).toHaveTextContent('El correo de acceso no está disponible');
    expect(screen.getByLabelText('Nombres')).toHaveValue('Equipo');
  });
  it('422 enfoca el campo administrativo y conserva datos corregibles', async () => {
    crearPersonal.mockRejectedValue(error(422, 'VALIDACION_ERROR', { nombres_personal: ['Revisa los nombres.'] }));
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Rol del personal');
    for (const [label, value] of [['Correo de acceso', 'equipo@example.test'], ['Nombres', 'Equipo'], ['Apellidos', 'Ficticio']]) fireEvent.change(screen.getByLabelText(label), { target: { value } });
    fireEvent.submit(screen.getByRole('form'));
    await waitFor(() => expect(screen.getByLabelText('Nombres')).toHaveFocus());
    expect(screen.getByLabelText('Nombres')).toHaveAccessibleDescription('Revisa los nombres.');
    expect(screen.getByLabelText('Correo de acceso')).toHaveValue('equipo@example.test');
  });
  it('un timeout de alta comunica resultado incierto y orienta a consultar, sin repetir', async () => {
    crearPersonal.mockRejectedValue(new Error('timeout'));
    mount('/administracion/cuentas/nueva');
    await screen.findByLabelText('Rol del personal');
    for (const [label, value] of [['Correo de acceso', 'equipo@example.test'], ['Nombres', 'Equipo'], ['Apellidos', 'Ficticio']]) fireEvent.change(screen.getByLabelText(label), { target: { value } });
    fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('La operación podría haberse completado');
    expect(screen.getByText(/Consulta el correo exacto en el listado/)).toBeInTheDocument();
    expect(crearPersonal).toHaveBeenCalledTimes(1);
  });
});

describe('reemisión de invitación', () => {
  it('requiere confirmación y vuelve a consultar la cuenta sin duplicarla', async () => {
    reenviarInvitacion.mockResolvedValue({ data: staff, meta: { invitacion: { envio: 'fallido' } } });
    const user = userEvent.setup();
    mount('/administracion/cuentas/17');
    await screen.findByText(staff.correo_acceso);
    await user.click(screen.getByRole('button', { name: 'Reenviar invitación' }));
    expect(reenviarInvitacion).not.toHaveBeenCalled();
    expect(screen.getByText(/¿Emitir una nueva invitación/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Confirmar nuevo envío' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('No se confirmó el envío'));
    expect(reenviarInvitacion).toHaveBeenCalledWith('17');
    expect(consultarCuenta).toHaveBeenCalledTimes(2);
    expect(crearPersonal).not.toHaveBeenCalled();
  });
  it.each(['Activa', 'Desactivada'])('no ofrece reenvío para personal %s', async estado_cuenta => {
    consultarCuenta.mockResolvedValue({ ...staff, estado_cuenta, correo_verificado_en: '2026-10-20T00:00:00Z' });
    mount('/administracion/cuentas/17');
    await screen.findByText(staff.correo_acceso);
    expect(screen.queryByRole('button', { name: 'Reenviar invitación' })).not.toBeInTheDocument();
  });
  it('respeta Retry-After de reenvío y no reinicia el envío automáticamente', async () => {
    const failure = error(429);
    failure.response.headers = { 'retry-after': '60' };
    reenviarInvitacion.mockRejectedValue(failure);
    const user = userEvent.setup();
    mount('/administracion/cuentas/17');
    await screen.findByText(staff.correo_acceso);
    await user.click(screen.getByRole('button', { name: 'Reenviar invitación' }));
    expect(screen.getByRole('button', { name: 'Confirmar nuevo envío' })).toHaveFocus();
    vi.useFakeTimers();
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Confirmar nuevo envío' })).toBeEnabled();
    expect(reenviarInvitacion).toHaveBeenCalledTimes(1);
  });
});
