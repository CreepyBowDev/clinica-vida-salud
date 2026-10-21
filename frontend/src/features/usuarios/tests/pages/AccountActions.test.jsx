import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion } from '../../../auth/services/auth.service';
import { cambiarEstadoCuenta, cambiarRolCuenta, consultarCuenta, emitirCredencialTemporal } from '../../services/usuario.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/usuario.service', async original => ({ ...await original(), consultarCuenta: vi.fn(), cambiarEstadoCuenta: vi.fn(), cambiarRolCuenta: vi.fn(), emitirCredencialTemporal: vi.fn() }));
const admin = { id_usuario: '1', nombre_visible: 'Administrador Ficticio', correo_acceso: 'admin@example.test', rol: 'Administrador' };
const staff = { id_usuario: '17', nombre_visible: 'Persona Ficticia', correo_acceso: 'equipo@example.test', rol: 'Recepcionista', estado_cuenta: 'Activa', correo_verificado_en: '2026-10-20T00:00:00Z', id_medico: null };
const secret = 'TemporalFicticia1234';
const receipt = id => ({ id_usuario: id, credencial_temporal: secret, expira_en: '2026-10-20T20:30:00Z' });
const error = (status, code, details, headers = {}) => ({ response: { status, headers, data: { error: { code, details, message: 'SQL secreto' } } } });
function mount(id = '17') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/administracion/cuentas/${id}`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockResolvedValue(admin); consultarCuenta.mockResolvedValue(staff); });
afterEach(() => vi.useRealTimers());

describe('estado de acceso', () => {
  it.each([['Activa', 'Desactivada', 'Desactivar acceso'], ['Desactivada', 'Activa', 'Reactivar acceso']])('cambia %s a %s con confirmación y reconsulta', async (from, to, name) => {
    consultarCuenta.mockResolvedValueOnce({ ...staff, rol: 'Paciente', estado_cuenta: from }).mockResolvedValue({ ...staff, rol: 'Paciente', estado_cuenta: to });
    cambiarEstadoCuenta.mockResolvedValue({ ...staff, estado_cuenta: to });
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name }));
    expect(cambiarEstadoCuenta).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Cambiar rol' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Emitir credencial temporal' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: `Confirmar: ${name.toLowerCase()}` }));
    expect(await screen.findByRole('status')).toHaveTextContent('Estado de acceso actualizado');
    expect(cambiarEstadoCuenta).toHaveBeenCalledWith('17', to);
    expect(consultarCuenta).toHaveBeenCalledTimes(2);
  });
  it('no ofrece cambios de estado/temporal a cuentas pendientes', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, estado_cuenta: 'Pendiente', correo_verificado_en: null });
    mount();
    await screen.findByText(staff.correo_acceso);
    expect(screen.queryByRole('button', { name: /Desactivar acceso|Reactivar acceso|Emitir credencial temporal/ })).not.toBeInTheDocument();
  });
  it('último Administrador rechazado conserva sesión y no anuncia éxito', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, id_usuario: '1', rol: 'Administrador' });
    cambiarEstadoCuenta.mockRejectedValue(error(409, 'ULTIMO_ADMINISTRADOR'));
    const user = userEvent.setup();
    mount('1');
    await user.click(await screen.findByRole('button', { name: 'Desactivar acceso' }));
    expect(screen.getByText(/Estás modificando tu propia cuenta/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Confirmar: desactivar acceso' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('sin un Administrador habilitado');
    expect(screen.getByRole('navigation')).toBeInTheDocument();
    expect(consultarSesion).toHaveBeenCalledTimes(1);
  });
  it('desactivación propia confirmada retira el área y exige login', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, id_usuario: '1', rol: 'Administrador' });
    consultarSesion.mockResolvedValueOnce(admin).mockRejectedValue(error(401));
    cambiarEstadoCuenta.mockResolvedValue({ ...staff, estado_cuenta: 'Desactivada' });
    const user = userEvent.setup();
    const router = mount('1');
    await user.click(await screen.findByRole('button', { name: 'Desactivar acceso' }));
    await user.click(screen.getByRole('button', { name: 'Confirmar: desactivar acceso' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });
});

describe('roles del personal', () => {
  it('reasignación propia confirmada retira permisos y exige nuevo login', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, id_usuario: '1', rol: 'Administrador' });
    consultarSesion.mockResolvedValueOnce(admin).mockRejectedValue(error(401));
    cambiarRolCuenta.mockResolvedValue({ ...staff, id_usuario: '1', rol: 'Recepcionista' });
    const user = userEvent.setup();
    mount('1');
    await user.click(await screen.findByRole('button', { name: 'Cambiar rol' }));
    await user.selectOptions(screen.getByLabelText('Nuevo rol del personal'), 'Recepcionista');
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de rol' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });
  it('nuevo vínculo Médico exige identidad y envía ID como texto', async () => {
    cambiarRolCuenta.mockResolvedValue({ ...staff, rol: 'Médico' });
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Cambiar rol' }));
    expect(screen.queryByRole('option', { name: 'Paciente' })).not.toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Nuevo rol del personal'), 'Médico');
    fireEvent.change(screen.getByLabelText('Identificador del registro médico'), { target: { value: '9223372036854775807' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Modificar acceso de cuenta' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('comprobar presencialmente');
    expect(cambiarRolCuenta).not.toHaveBeenCalled();
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de rol' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Rol actualizado');
    expect(cambiarRolCuenta).toHaveBeenCalledWith('17', { rol: 'Médico', id_medico: '9223372036854775807', identidad_verificada: true });
  });
  it('vínculo profesional histórico solo admite el mismo ID y no requiere nueva declaración', async () => {
    consultarCuenta.mockResolvedValue({ ...staff, id_medico: '88' });
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Cambiar rol' }));
    await user.selectOptions(screen.getByLabelText('Nuevo rol del personal'), 'Médico');
    expect(screen.getByLabelText('Identificador del registro médico')).toHaveValue('88');
    expect(screen.getByLabelText('Identificador del registro médico')).toHaveAttribute('readonly');
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });
  it.each(['MEDICO_OBLIGACIONES_NO_RESUELTAS', 'PERSONAL_IDENTIDAD_INCOMPATIBLE'])('rechazo %s no cambia rol ni expone clínica', async code => {
    consultarCuenta.mockResolvedValue({ ...staff, rol: 'Médico', id_medico: '88' });
    cambiarRolCuenta.mockRejectedValue(error(409, code));
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Cambiar rol' }));
    await user.selectOptions(screen.getByLabelText('Nuevo rol del personal'), 'Recepcionista');
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de rol' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.queryByText('Rol actualizado.')).not.toBeInTheDocument();
    expect(consultarCuenta).toHaveBeenCalledTimes(1);
  });
});

describe('credencial temporal', () => {
  it('una consulta de sesión anterior a la propia emisión no resucita el acceso al cerrar la entrega', async () => {
    let resolveOld;
    consultarCuenta.mockResolvedValue({ ...staff, id_usuario: '1', rol: 'Administrador' });
    consultarSesion.mockResolvedValueOnce(admin).mockImplementationOnce(() => new Promise(done => { resolveOld = done; })).mockRejectedValue(error(401));
    emitirCredencialTemporal.mockResolvedValue(receipt('1'));
    const user = userEvent.setup();
    mount('1');
    await screen.findByRole('button', { name: 'Emitir credencial temporal' });
    fireEvent(window, new Event('focus'));
    await user.click(screen.getByRole('button', { name: 'Emitir credencial temporal' }));
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Confirmar emisión' }));
    await screen.findByRole('heading', { name: 'Credencial temporal emitida' });
    await act(async () => resolveOld(admin));
    await user.click(screen.getByRole('button', { name: 'Ya comuniqué la credencial; ocultar' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });
  it('cambiar de ruta elimina la entrega y volver no restaura el secreto', async () => {
    emitirCredencialTemporal.mockResolvedValue(receipt('17'));
    const user = userEvent.setup();
    const router = mount();
    await user.click(await screen.findByRole('button', { name: 'Emitir credencial temporal' }));
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Confirmar emisión' }));
    await screen.findByRole('heading', { name: 'Credencial temporal emitida' });
    await act(async () => router.navigate('/inicio'));
    await screen.findByRole('heading', { name: 'Hola, Administrador Ficticio' });
    await act(async () => router.navigate(-1));
    await screen.findByRole('heading', { name: 'Detalle de cuenta' });
    expect(screen.queryByText(secret)).not.toBeInTheDocument();
    expect(emitirCredencialTemporal).toHaveBeenCalledTimes(1);
  });
  it.each(['17', '1'])('entrega una sola vez para cuenta %s, incluso con revocación propia', async id => {
    consultarCuenta.mockResolvedValue({ ...staff, id_usuario: id, rol: id === '1' ? 'Administrador' : 'Recepcionista' });
    if (id === '1') consultarSesion.mockResolvedValueOnce(admin).mockRejectedValue(error(401));
    emitirCredencialTemporal.mockResolvedValue(receipt(id));
    const user = userEvent.setup();
    mount(id);
    await user.click(await screen.findByRole('button', { name: 'Emitir credencial temporal' }));
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Confirmar emisión' }));
    expect(await screen.findByRole('heading', { name: 'Credencial temporal emitida' })).toHaveFocus();
    expect(screen.getByLabelText('Credencial temporal para entrega presencial')).toHaveTextContent(secret);
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(emitirCredencialTemporal).toHaveBeenCalledWith(id, true);
    expect(localStorage.length + sessionStorage.length).toBe(0);
    await waitFor(() => expect(consultarSesion).toHaveBeenCalledTimes(2));
    await user.click(screen.getByRole('button', { name: 'Ya comuniqué la credencial; ocultar' }));
    if (id === '1') await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    else await screen.findByRole('heading', { name: 'Detalle de cuenta' });
    expect(screen.queryByText(secret)).not.toBeInTheDocument();
  });
  it('no emite sin comprobación presencial y bloquea duplicación mientras responde', async () => {
    let resolve;
    emitirCredencialTemporal.mockImplementation(() => new Promise(done => { resolve = done; }));
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Emitir credencial temporal' }));
    fireEvent.submit(screen.getByRole('form', { name: 'Modificar acceso de cuenta' }));
    expect(emitirCredencialTemporal).not.toHaveBeenCalled();
    await user.click(screen.getByRole('checkbox'));
    const form = screen.getByRole('form', { name: 'Modificar acceso de cuenta' });
    fireEvent.submit(form); fireEvent.submit(form);
    expect(emitirCredencialTemporal).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Guardando cambio…' })).toBeDisabled();
    await act(async () => resolve(receipt('17')));
  });
  it.each([419, 429, 500, undefined])('fallo %s no fabrica ni repite una entrega', async status => {
    emitirCredencialTemporal.mockRejectedValue(status ? error(status, null, null, status === 429 ? { 'retry-after': '60' } : {}) : new Error('red'));
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Emitir credencial temporal' }));
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Confirmar emisión' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.queryByRole('heading', { name: 'Credencial temporal emitida' })).not.toBeInTheDocument();
    expect(emitirCredencialTemporal).toHaveBeenCalledTimes(1);
    if (status === 429) expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
  });
});
