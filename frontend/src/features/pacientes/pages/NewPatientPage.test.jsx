import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../api/api';
import { AuthProvider } from '../../auth/context/AuthContext';
import { crearRutas } from '../../../app/router';
import { consultarSesion } from '../../auth/services/auth.service';
import { consultarPaciente, crearPaciente, listarPacientes } from '../services/paciente.service';

vi.mock('../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../services/paciente.service', () => ({ consultarPaciente: vi.fn(), listarPacientes: vi.fn(), crearPaciente: vi.fn(), actualizarPaciente: vi.fn(), cambiarEstadoPaciente: vi.fn() }));
const patient = { id_paciente: '9223372036854775807', codigo_paciente: 'PAC-001', nombres: 'Persona', apellidos: 'Ficticia', documento_identidad: null, activo: true };
const listing = (patients = [], current = 1, last = 1) => ({ data: patients, meta: { total: patients.length, current_page: current, last_page: last } });
const failure = (status, code, details) => ({ response: { status, data: { error: { code, message: 'SQL secreto', details } }, headers: {} } });
function mount(path = '/recepcion/pacientes/nuevo') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
function field(label, value) { fireEvent.change(screen.getByLabelText(label), { target: { value } }); }
async function fill(ci = '1234') {
  await screen.findByRole('form', { name: 'Registrar paciente' });
  field('CI (opcional)', ci); field('Nombres', 'Persona'); field('Apellidos', 'Ficticia'); field('Fecha de nacimiento', '1990-01-02'); field('Teléfono', '70000000');
}
const submit = () => fireEvent.submit(screen.getByRole('form', { name: 'Registrar paciente' }));
const originalAdapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = originalAdapter; });
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Recepción Ficticia', correo_acceso: 'recepcion@example.test', rol: 'Recepcionista' });
  listarPacientes.mockResolvedValue(listing()); crearPaciente.mockResolvedValue(patient);
});

describe('alta administrativa CU-07', () => {
  it.each(['Administrador', 'Médico', 'Paciente'])('deniega alta a %s sin consultar o escribir', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
    mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(crearPaciente).not.toHaveBeenCalled(); expect(listarPacientes).not.toHaveBeenCalled(); expect(consultarPaciente).not.toHaveBeenCalled();
  });
  it('enlaza desde listado al formulario sin solicitar campos clínicos, acceso o estados', async () => {
    const user = userEvent.setup(); mount('/recepcion/pacientes');
    await user.click(await screen.findByRole('link', { name: 'Registrar paciente' }));
    await screen.findByRole('form', { name: 'Registrar paciente' });
    expect(screen.getByLabelText('Nombres')).toBeRequired();
    expect(screen.getByLabelText('CI (opcional)')).not.toBeRequired();
    expect(screen.queryByLabelText(/contraseña|correo de acceso|antecedentes|recordatorios|estado/i)).not.toBeInTheDocument();
  });
  it('crea con CI tras confirmación HTTP, enfoca resultado y enlaza al BIGINT intacto', async () => {
    const user = userEvent.setup(); mount(); await fill();
    await user.click(screen.getByRole('button', { name: 'Crear ficha administrativa' }));
    expect(await screen.findByRole('heading', { name: 'Ficha de paciente creada' })).toHaveFocus();
    expect(screen.getByRole('link', { name: 'Ver ficha creada' })).toHaveAttribute('href', `/recepcion/pacientes/${patient.id_paciente}`);
    expect(screen.getByText(/no crea una cuenta web/)).toBeInTheDocument();
    expect(crearPaciente).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
  });
  it('no ofrece éxito hasta responder y bloquea doble clic/envío y edición', async () => {
    let resolve;
    crearPaciente.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount(); await fill(); submit(); submit();
    expect(crearPaciente).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Registrando paciente…' })).toBeDisabled();
    expect(screen.getByLabelText('Nombres')).toBeDisabled();
    expect(screen.queryByRole('heading', { name: 'Ficha de paciente creada' })).not.toBeInTheDocument();
    await act(async () => resolve(patient));
    await screen.findByRole('heading', { name: 'Ficha de paciente creada' });
  });
  it('sin CI exige búsqueda exitosa y confirmación explícita; envía revisión y emergencia', async () => {
    const user = userEvent.setup(); mount(); await fill(''); submit();
    expect(crearPaciente).not.toHaveBeenCalled();
    expect(screen.getByRole('checkbox', { name: /He revisado/ })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Buscar por nombres y apellidos' }));
    await screen.findByText(/0 fichas encontradas/);
    expect(listarPacientes.mock.calls[0][0]).toEqual({ nombres: 'Persona', apellidos: 'Ficticia', page: 1 });
    submit(); expect(crearPaciente).not.toHaveBeenCalled();
    await user.click(screen.getByRole('checkbox', { name: /He revisado/ }));
    await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' }));
    field('Nombre del contacto', 'Contacto Ficticio'); field('Parentesco', 'Hermana'); field('Teléfono de emergencia', '71111111'); submit();
    await screen.findByRole('heading', { name: 'Ficha de paciente creada' });
    expect(crearPaciente.mock.calls[0][0]).toMatchObject({ documento_identidad: '', posibles_duplicados_revisados: true, contacto_emergencia: { nombre: 'Contacto Ficticio', parentesco: 'Hermana', telefono: '71111111' } });
  });
  it('invalida revisión al cambiar identidad y descarta búsqueda tardía', async () => {
    let resolveOld;
    listarPacientes.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    const user = userEvent.setup(); mount(); await fill('');
    await user.click(screen.getByRole('button', { name: 'Buscar por nombres y apellidos' }));
    field('Nombres', 'Otra');
    expect(listarPacientes.mock.calls[0][1].aborted).toBe(true);
    await act(async () => resolveOld(listing([patient])));
    expect(screen.queryByText(patient.codigo_paciente)).not.toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /He revisado/ })).toBeDisabled();
    submit(); expect(crearPaciente).not.toHaveBeenCalled();
  });
  it('lista y pagina coincidencias sin excluir inactivas ni mezclar CI con nombres; abre sin perder formulario', async () => {
    const user = userEvent.setup(); listarPacientes.mockResolvedValue(listing([{ ...patient, activo: false }], 1, 2));
    mount(); await fill(); await user.click(screen.getByRole('button', { name: 'Buscar por CI' }));
    await screen.findByText(patient.codigo_paciente, { exact: false });
    expect(listarPacientes.mock.calls[0][0]).toEqual({ documento_identidad: '1234', page: 1 });
    const link = screen.getByRole('link', { name: 'Consultar ficha en otra pestaña' });
    expect(link).toHaveAttribute('target', '_blank'); expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    expect(screen.getByText(/Inactiva/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Fichas siguientes' }));
    await waitFor(() => expect(listarPacientes).toHaveBeenCalledTimes(2));
    expect(listarPacientes.mock.calls[1][0].page).toBe(2); expect(crearPaciente).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Nombres')).toHaveValue('Persona');
  });
  it('búsqueda fallida no autoriza confirmar revisión y permite reintentar sin crear ficha', async () => {
    const user = userEvent.setup(); listarPacientes.mockRejectedValue(failure(500)); mount(); await fill('');
    await user.click(screen.getByRole('button', { name: 'Buscar por nombres y apellidos' }));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByRole('checkbox', { name: /He revisado/ })).toBeDisabled();
    listarPacientes.mockResolvedValue(listing());
    await user.click(screen.getByRole('button', { name: 'Reintentar búsqueda de fichas' }));
    await screen.findByText(/0 fichas encontradas/); expect(crearPaciente).not.toHaveBeenCalled();
  });
  it('valida campos en blanco y emergencia parcial antes de enviar', async () => {
    const user = userEvent.setup(); mount(); await fill(); field('Nombres', '   '); submit();
    expect(crearPaciente).not.toHaveBeenCalled(); expect(screen.getByLabelText('Nombres')).toHaveAttribute('aria-invalid', 'true');
    field('Nombres', 'Persona'); await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' }));
    field('Nombre del contacto', 'Contacto'); submit(); expect(crearPaciente).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Parentesco')).toHaveAttribute('aria-invalid', 'true');
    await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' })); submit();
    await screen.findByRole('heading', { name: 'Ficha de paciente creada' }); expect(crearPaciente.mock.calls[0][0].contacto_emergencia).toBeNull();
  });
  it('422 conserva datos y muestra errores de emergencia, fecha y contacto con foco accesible', async () => {
    const user = userEvent.setup(); crearPaciente.mockRejectedValue(failure(422, 'VALIDACION_ERROR', { fecha_nacimiento: ['La fecha no puede ser futura.'], 'contacto_emergencia.telefono': ['Revisa el teléfono.'], password: ['NO MOSTRAR'] }));
    mount(); await fill(); await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' }));
    field('Nombre del contacto', 'Contacto'); field('Parentesco', 'Hermana'); field('Teléfono de emergencia', '71111111'); submit();
    await screen.findByText('La fecha no puede ser futura.');
    expect(screen.getByText('Revisa el teléfono.')).toBeInTheDocument();
    expect(screen.getByLabelText('Fecha de nacimiento')).toHaveValue('1990-01-02');
    await waitFor(() => expect(screen.getByLabelText('Fecha de nacimiento')).toHaveFocus());
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument();
  });
  it.each([409, 419, 403])('fallo HTTP %s conserva el formulario sin éxito ni reenvío', async status => {
    crearPaciente.mockRejectedValue(failure(status, 'PACIENTE_DOCUMENTO_NO_DISPONIBLE')); mount(); await fill(); submit();
    const alert = await screen.findByRole('alert'); expect(alert).not.toHaveTextContent('SQL secreto');
    if (status === 409) expect(alert).toHaveTextContent('CI no está disponible');
    expect(screen.getByLabelText('Nombres')).toHaveValue('Persona'); expect(crearPaciente).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('heading', { name: 'Ficha de paciente creada' })).not.toBeInTheDocument();
  });
  it.each([500, undefined])('resultado incierto %s bloquea otro envío hasta volver a consultar fichas', async status => {
    const user = userEvent.setup(); crearPaciente.mockRejectedValue(status ? failure(status) : new Error('sin conexión'));
    mount(); await fill(); submit();
    expect(await screen.findByRole('alert')).toHaveTextContent('La ficha podría haberse creado');
    expect(screen.getByRole('button', { name: 'Crear ficha administrativa' })).toBeDisabled();
    submit(); expect(crearPaciente).toHaveBeenCalledTimes(1);
    listarPacientes.mockResolvedValue(listing([patient]));
    await user.click(screen.getByRole('button', { name: 'Buscar por CI' }));
    await screen.findByRole('link', { name: 'Consultar ficha en otra pestaña' });
    expect(screen.getByRole('button', { name: 'Crear ficha administrativa' })).toBeEnabled();
    expect(crearPaciente).toHaveBeenCalledTimes(1);
  });
  it('429 respeta Retry-After y no envía otro alta durante la espera', async () => {
    crearPaciente.mockRejectedValue({ ...failure(429), response: { ...failure(429).response, headers: { 'retry-after': '60' } } });
    mount(); await fill(); submit(); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    submit(); expect(crearPaciente).toHaveBeenCalledTimes(1);
  });
  it('recorre búsqueda → alta sin CI → detalle con servicios y transporte Axios reales', async () => {
    const actual = await vi.importActual('../services/paciente.service');
    listarPacientes.mockImplementation(actual.listarPacientes); crearPaciente.mockImplementation(actual.crearPaciente); consultarPaciente.mockImplementation(actual.consultarPaciente);
    const calls = [];
    api.defaults.adapter = async config => {
      calls.push(config);
      const data = config.url === '/pacientes' && config.method === 'get' ? listing()
        : { data: { ...patient, fecha_nacimiento: '1990-01-02', sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, cuenta_vinculada: false, contacto_emergencia: null } };
      return { config, status: config.method === 'post' ? 201 : 200, data, headers: {} };
    };
    const user = userEvent.setup(); mount(); await fill('');
    await user.click(screen.getByRole('button', { name: 'Buscar por nombres y apellidos' })); await screen.findByText(/0 fichas encontradas/);
    await user.click(screen.getByRole('checkbox', { name: /He revisado/ })); await user.click(screen.getByRole('button', { name: 'Crear ficha administrativa' }));
    await screen.findByRole('heading', { name: 'Ficha de paciente creada' });
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', '/pacientes'], ['get', csrfURL], ['post', '/pacientes']]);
    expect(JSON.parse(calls[2].data)).toEqual({ documento_identidad: null, nombres: 'Persona', apellidos: 'Ficticia', fecha_nacimiento: '1990-01-02', sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, contacto_emergencia: null, posibles_duplicados_revisados: true });
    await user.click(screen.getByRole('link', { name: 'Ver ficha creada' })); await screen.findByRole('heading', { name: 'Persona Ficticia' });
    expect(calls.at(-1).url).toBe(`/pacientes/${patient.id_paciente}`);
    expect(screen.getByText('Sin cuenta vinculada')).toBeInTheDocument();
  });
  it('un 401 durante el alta retira el formulario y los datos privados mediante el interceptor real', async () => {
    const actual = await vi.importActual('../services/paciente.service'); crearPaciente.mockImplementation(actual.crearPaciente);
    api.defaults.adapter = async config => {
      if (config.method === 'post') throw new AxiosError('sesión finalizada', undefined, config, null, { status: 401, data: {}, headers: {} });
      return { config, status: 204, data: null, headers: {} };
    };
    const router = mount(); await fill(); submit();
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(screen.queryByRole('form', { name: 'Registrar paciente' })).not.toBeInTheDocument();
    expect(screen.queryByDisplayValue('70000000')).not.toBeInTheDocument();
  });
});
