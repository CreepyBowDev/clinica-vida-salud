import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api } from '../../../../api/api';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const path = `/administracion/cuentas/nueva?id_medico=${id}`;
let doctor, calls, write;
function mount(route = path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [route] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  doctor = { id_medico: id, codigo_medico: 'MED-ficticio', registro_profesional: 'REG-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'contacto@example.test', activo: false, cuenta_vinculada: false, especialidades: [], actualizado_en: '2026-10-21T12:00:00.123456Z' };
  calls = []; write = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url.includes('csrf-cookie')) return { config, status: 204, headers: {}, data: '' };
    if (config.method === 'post') {
      if (write) return write(config);
      doctor = { ...doctor, cuenta_vinculada: true };
      return { config, status: 201, headers: {}, data: { data: { id_usuario: '12', rol: 'Médico', correo_acceso: 'acceso@example.test', estado_cuenta: 'Pendiente' }, meta: { invitacion: { envio: 'simulado' } } } };
    }
    return { config, status: 200, headers: {}, data: { data: doctor } };
  };
});
afterEach(() => { api.defaults.adapter = original; });
describe('Médico seleccionado → CU-03', () => {
  it('detalle enlaza al alta, reconsulta, fija rol/ID y no copia correo de contacto al acceso', async () => {
    const user = userEvent.setup(); mount(`/administracion/medicos/${id}`);
    await user.click(await screen.findByRole('link', { name: 'Crear cuenta para este médico' }));
    const field = await screen.findByLabelText('Identificador del registro médico'); expect(field).toHaveValue(id); expect(field).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Rol del personal')).toBeDisabled(); expect(screen.getByLabelText('Rol del personal')).toHaveValue('Médico');
    expect(screen.getByLabelText('Correo de acceso')).toHaveValue(''); expect(calls.filter(c => c.url === `/medicos/${id}`)).toHaveLength(2);
    await user.type(screen.getByLabelText('Correo de acceso'), 'acceso@example.test'); await user.click(screen.getByRole('button', { name: 'Crear cuenta e invitar' }));
    await screen.findByRole('heading', { name: 'Cuenta del personal creada' }); expect(screen.getByText('Invitación simulada. No se envió un correo externo.')).toBeInTheDocument();
    expect(JSON.parse(calls.find(c => c.method === 'post').data)).toEqual({ rol: 'Médico', id_medico: id, correo_acceso: 'acceso@example.test' });
    expect(doctor.activo).toBe(false); expect(doctor.correo_contacto).toBe('contacto@example.test');
    await user.click(screen.getByRole('link', { name: 'Volver al médico' })); await screen.findByRole('link', { name: 'Gestionar habilitaciones' });
    expect(screen.queryByRole('link', { name: 'Crear cuenta para este médico' })).not.toBeInTheDocument();
  });
  it('cuenta ya vinculada impide otro alta tras reconsulta aunque se fuerce la URL', async () => {
    doctor.cuenta_vinculada = true; mount(); await screen.findByRole('heading', { name: 'El médico ya tiene una cuenta vinculada' });
    expect(screen.queryByRole('form')).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  });
  it.each(['0', '9223372036854775808', `${id}&id_medico=2`])('parámetro inválido/duplicado %s no consulta ni permite vincular', async value => {
    mount(`/administracion/cuentas/nueva?id_medico=${value}`); await screen.findByRole('heading', { name: 'Registro médico no disponible' }); expect(calls).toHaveLength(0);
  });
  it('rechazo concurrente 409 no anuncia creación ni modifica el Médico', async () => {
    write = config => { throw new AxiosError('SQL secreto', undefined, config, null, { status: 409, data: { error: { code: 'PERSONAL_VINCULO_INVALIDO' } } }); };
    const user = userEvent.setup(); mount(); await user.type(await screen.findByLabelText('Correo de acceso'), 'acceso@example.test'); await user.click(screen.getByRole('button', { name: 'Crear cuenta e invitar' }));
    await screen.findByRole('alert'); expect(screen.queryByRole('heading', { name: 'Cuenta del personal creada' })).not.toBeInTheDocument(); expect(doctor.cuenta_vinculada).toBe(false);
    expect(screen.getByRole('link', { name: 'Volver al médico' })).toBeInTheDocument();
  });
  it.each([500, undefined])('resultado incierto %s bloquea reenvío hasta revisar vínculo vigente', async status => {
    write = config => { throw new AxiosError('fallo', undefined, config, null, status ? { status, data: {} } : undefined); };
    const user = userEvent.setup(); mount(); await user.type(await screen.findByLabelText('Correo de acceso'), 'acceso@example.test'); await user.click(screen.getByRole('button', { name: 'Crear cuenta e invitar' }));
    await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Crear cuenta e invitar' })).toBeDisabled(); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
  });
  it.each(['Recepcionista', 'Médico', 'Paciente'])('deniega vínculo a %s sin consultar Médico', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol }); mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
  });
  it('respuesta tardía de consulta anterior no cambia la identidad al navegar entre médicos', async () => {
    let resolve; const adapter = api.defaults.adapter;
    api.defaults.adapter = config => config.url.endsWith(`/${id}`) ? new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: doctor } }); }) : adapter(config);
    const router = mount(); await waitFor(() => expect(resolve).toBeDefined());
    doctor = { ...doctor, id_medico: '2' }; await act(() => router.navigate('/administracion/cuentas/nueva?id_medico=2')); await screen.findByLabelText('Identificador del registro médico');
    await act(async () => resolve()); expect(screen.getByLabelText('Identificador del registro médico')).toHaveValue('2');
  });
});
