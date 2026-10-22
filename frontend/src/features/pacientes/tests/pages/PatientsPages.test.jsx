import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api } from '../../../../api/api';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion } from '../../../auth/services/auth.service';
import { consultarPaciente, listarPacientes } from '../../services/paciente.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/paciente.service', () => ({ consultarPaciente: vi.fn(), listarPacientes: vi.fn(), crearPaciente: vi.fn(), actualizarPaciente: vi.fn(), cambiarEstadoPaciente: vi.fn() }));
const person = rol => ({ id_usuario: '1', nombre_visible: 'Recepción Ficticia', correo_acceso: 'recepcion@example.test', rol });
const patient = { id_paciente: '9223372036854775807', codigo_paciente: 'PAC-001', documento_identidad: null, nombres: 'Persona', apellidos: 'Ficticia', activo: false, fecha_nacimiento: '1990-01-02', sexo: 'Femenino', telefono: '70000000', correo_contacto: null, direccion: null, cuenta_vinculada: true, contacto_emergencia: null, recordatorios_habilitados: null, actualizado_en: '2026-10-20T10:00:00.123456Z' };
const listing = (patients = [patient], current = 1, last = 1) => ({ data: patients, meta: { total: patients.length, current_page: current, last_page: last }, links: { next: 'https://ajeno.test/no-seguir' } });
const failure = (status, details) => ({ response: { status, data: { error: { message: 'SQL secreto', details } }, headers: { 'retry-after': '30' } } });
const original = api.defaults.adapter;
function mount(path = '/recepcion/pacientes') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockResolvedValue(person('Recepcionista')); listarPacientes.mockResolvedValue(listing()); consultarPaciente.mockResolvedValue(patient); });
afterEach(() => { api.defaults.adapter = original; });

describe('consulta administrativa de pacientes CU-07', () => {
  it('tabla adaptable conserva CI ausente, código, estado y destino de ficha', async () => {
    mount(); const table = await screen.findByRole('table');
    expect(table.parentElement).toHaveClass('query-table'); expect(within(table).getAllByRole('columnheader')).toHaveLength(5);
    expect(table).toHaveTextContent('Sin CI registrado'); expect(table).toHaveTextContent('PAC-001'); expect(table).toHaveTextContent('Inactiva');
    expect(within(table).getByRole('link', { name: 'Ver ficha de Persona Ficticia' })).toHaveAttribute('href', `/recepcion/pacientes/${patient.id_paciente}`);
    expect(screen.getByRole('navigation', { name: 'Paginación de pacientes' })).toHaveClass('pagination'); expect(listarPacientes).toHaveBeenCalledTimes(1);
  });
  it.each(['Administrador', 'Médico', 'Paciente'])('deniega listado y detalle a %s sin consultar pacientes', async rol => {
    consultarSesion.mockResolvedValue(person(rol));
    const router = mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    await act(() => router.navigate('/recepcion/pacientes/17'));
    expect(listarPacientes).not.toHaveBeenCalled();
    expect(consultarPaciente).not.toHaveBeenCalled();
    expect(screen.queryByRole('link', { name: 'Pacientes' })).not.toBeInTheDocument();
  });
  it('espera restauración de sesión y dirige al login al visitante', async () => {
    let reject;
    consultarSesion.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    const router = mount();
    expect(listarPacientes).not.toHaveBeenCalled();
    await act(async () => reject(failure(401)));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login');
    expect(listarPacientes).not.toHaveBeenCalled();
  });
  it('busca solo al enviar, pagina por meta, reinicia página y limpia filtros', async () => {
    listarPacientes.mockResolvedValue(listing([patient], 1, 2));
    const user = userEvent.setup();
    mount();
    await screen.findByRole('table');
    expect(screen.getByRole('link', { name: 'Gestionar pacientes' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled();
    await user.type(screen.getByLabelText('CI'), ' 1234 ');
    await user.type(screen.getByLabelText('Nombres'), ' Persona ');
    await user.type(screen.getByLabelText('Apellidos'), ' Ficticia ');
    await user.selectOptions(screen.getByLabelText('Estado de ficha'), '0');
    expect(listarPacientes).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await waitFor(() => expect(listarPacientes).toHaveBeenCalledTimes(2));
    expect(listarPacientes.mock.calls[1][0]).toEqual({ documento_identidad: '1234', nombres: 'Persona', apellidos: 'Ficticia', activo: '0', page: 1 });
    listarPacientes.mockResolvedValue(listing([patient], 2, 2));
    await user.click(screen.getByRole('button', { name: 'Siguiente' }));
    await waitFor(() => expect(listarPacientes).toHaveBeenCalledTimes(3));
    expect(listarPacientes.mock.calls[2][0].page).toBe(2);
    expect(await screen.findByRole('button', { name: 'Siguiente' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await waitFor(() => expect(listarPacientes).toHaveBeenCalledTimes(4));
    expect(listarPacientes.mock.calls[3][0].page).toBe(1);
    await user.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
    await waitFor(() => expect(listarPacientes).toHaveBeenCalledTimes(5));
    expect(listarPacientes.mock.calls[4][0]).toEqual({ documento_identidad: '', nombres: '', apellidos: '', activo: '', page: 1 });
    expect(screen.getByLabelText('CI')).toHaveValue('');
  });
  it('cancela e ignora una respuesta tardía al cambiar filtros', async () => {
    let resolveOld;
    listarPacientes.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; })).mockResolvedValue(listing([]));
    mount();
    await screen.findByRole('heading', { name: 'Pacientes' });
    expect(screen.getByRole('status')).toHaveTextContent('Consultando pacientes');
    fireEvent.change(screen.getByLabelText('Nombres'), { target: { value: 'Otro' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Buscar pacientes' }));
    await screen.findByRole('heading', { name: 'No hay pacientes para estos filtros' });
    expect(listarPacientes.mock.calls[0][1].aborted).toBe(true);
    await act(async () => resolveOld(listing()));
    expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
  });
  it('abre ficha sin CI, conserva BIGINT y muestra nulos y estado de cuenta independientes', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('link', { name: 'Ver ficha de Persona Ficticia' }));
    await screen.findByRole('heading', { name: 'Persona Ficticia' });
    expect(consultarPaciente.mock.calls[0][0]).toBe(patient.id_paciente);
    expect(screen.getByText(patient.id_paciente)).toBeInTheDocument();
    expect(screen.getByText('Sin CI registrado')).toBeInTheDocument();
    expect(screen.getByText('Inactiva')).toBeInTheDocument();
    expect(screen.getByText('Vinculada')).toBeInTheDocument();
    expect(screen.getByText('02/01/1990')).toHaveAttribute('dateTime', '1990-01-02');
    expect(screen.getByText(/Sin contacto de emergencia/)).toBeInTheDocument();
    expect(screen.getAllByText('No registrado')).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Reactivar ficha' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /contraseña|eliminar|recordatorio/i })).not.toBeInTheDocument();
  });
  it('muestra contacto y emergencia autorizados sin representar campos clínicos ajenos', async () => {
    consultarPaciente.mockResolvedValue({ ...patient, activo: true, documento_identidad: '1234', correo_contacto: 'contacto@example.test', cuenta_vinculada: false, contacto_emergencia: { nombre: 'Contacto Ficticio', parentesco: 'Hermana', telefono: '71111111' }, antecedentes: 'NO MOSTRAR', password: 'NO MOSTRAR' });
    mount(`/recepcion/pacientes/${patient.id_paciente}`);
    await screen.findByText('Contacto Ficticio');
    expect(screen.getByText('contacto@example.test')).toBeInTheDocument();
    expect(screen.getByText('Sin cuenta vinculada')).toBeInTheDocument();
    expect(screen.getByText('Activa')).toBeInTheDocument();
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument();
  });
  it.each(['0', '01', '9223372036854775808'])('ID inválido %s no consulta HTTP', async id => {
    mount(`/recepcion/pacientes/${id}`);
    await screen.findByRole('heading', { name: 'Ficha no disponible' });
    expect(consultarPaciente).not.toHaveBeenCalled();
  });
  it('404 de detalle es neutro y permite regresar al listado', async () => {
    consultarPaciente.mockRejectedValue(failure(404));
    mount('/recepcion/pacientes/17');
    expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró una ficha disponible');
    expect(screen.getByRole('link', { name: 'Volver a pacientes' })).toHaveAttribute('href', '/recepcion/pacientes');
  });
  it.each([403, 419, 429, 500, undefined])('maneja error %s sin filtrar cuerpo técnico ni datos anteriores y permite reconsulta', async status => {
    listarPacientes.mockRejectedValue(status ? failure(status) : new Error('SQL secreto'));
    const user = userEvent.setup();
    const router = mount();
    const alert = await screen.findByRole('alert');
    expect(alert).not.toHaveTextContent('SQL secreto');
    if (status === 429) expect(alert).toHaveTextContent('30 segundos');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/recepcion/pacientes');
    listarPacientes.mockResolvedValue(listing());
    await user.click(screen.getByRole('button', { name: 'Volver a consultar' }));
    await screen.findByRole('table');
  });
  it('presenta 422 por campo y conserva filtros para corregir', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByRole('table');
    listarPacientes.mockRejectedValue(failure(422, { nombres: ['Revisa los nombres.'], password: ['NO MOSTRAR'] }));
    await user.type(screen.getByLabelText('Nombres'), 'Persona');
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await screen.findByText('Revisa los nombres.');
    expect(screen.getByLabelText('Nombres')).toHaveValue('Persona');
    expect(screen.getByLabelText('Nombres')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument();
  });
  it('cancela detalle al navegar y descarta respuesta de otra ficha', async () => {
    let resolveOld;
    consultarPaciente.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; })).mockResolvedValue({ ...patient, id_paciente: '18', nombres: 'Otra' });
    const router = mount('/recepcion/pacientes/17');
    await screen.findByText('Consultando ficha…');
    await act(() => router.navigate('/recepcion/pacientes/18'));
    await screen.findByRole('heading', { name: 'Otra Ficticia' });
    expect(consultarPaciente.mock.calls[0][1].aborted).toBe(true);
    await act(async () => resolveOld(patient));
    expect(screen.queryByRole('heading', { name: 'Persona Ficticia' })).not.toBeInTheDocument();
  });
  it('un 401 real del interceptor limpia la sesión y retira los datos privados', async () => {
    const user = userEvent.setup();
    const router = mount();
    await screen.findByRole('table');
    api.defaults.adapter = async config => { throw new AxiosError('sesión finalizada', undefined, config, null, { status: 401, data: {}, headers: {} }); };
    listarPacientes.mockImplementation(() => api.get('/pacientes', { sessionBound: true }));
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });
});
