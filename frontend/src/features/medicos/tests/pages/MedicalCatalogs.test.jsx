import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';
import { listarMedicos } from '../../services/medico.service';
import { listarEspecialidades } from '../../../especialidades/services/especialidad.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/medico.service', () => ({ listarMedicos: vi.fn() }));
vi.mock('../../../especialidades/services/especialidad.service', () => ({ listarEspecialidades: vi.fn() }));
const specialty = { id_especialidad: '9223372036854775807', nombre: 'Geriatría ficticia', descripcion: null, activa: false, actualizado_en: '2026-10-21T10:00:00.123456Z' };
const doctor = { id_medico: '9223372036854775806', codigo_medico: 'MED-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', activo: false,
  especialidades: [{ ...specialty, habilitada: true }], password: 'NO MOSTRAR', correo_acceso: 'NO MOSTRAR', diagnostico: 'NO MOSTRAR' };
const listing = (data, page = 1, last = 1) => ({ data, meta: { total: data.length, current_page: page, last_page: last }, links: { next: 'https://ajeno.test/no-seguir' } });
const person = rol => ({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol });
const fail = (status, details = {}, code) => ({ response: { status, data: { error: { message: 'SQL secreto', code, details } }, headers: { 'retry-after': '2' } } });
const original = api.defaults.adapter;
const paths = ['/administracion/especialidades', '/administracion/medicos'];
function mount(path = paths[0]) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockResolvedValue(person('Administrador')); listarEspecialidades.mockResolvedValue(listing([specialty])); listarMedicos.mockResolvedValue(listing([doctor])); });
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });

describe('consulta de Médicos y Especialidades, fase 1', () => {
  it.each(['Recepcionista', 'Médico', 'Paciente'])('deniega ambas rutas a %s antes de consultar datos', async rol => {
    consultarSesion.mockResolvedValue(person(rol)); const router = mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    await act(() => router.navigate(paths[1]));
    expect(listarMedicos).not.toHaveBeenCalled(); expect(listarEspecialidades).not.toHaveBeenCalled();
    expect(screen.queryByRole('link', { name: 'Médicos' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Especialidades' })).not.toBeInTheDocument();
  });
  it.each(paths)('espera restauración de sesión y dirige al visitante a login en %s', async path => {
    let reject; consultarSesion.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    const router = mount(path); expect(listarMedicos).not.toHaveBeenCalled(); expect(listarEspecialidades).not.toHaveBeenCalled();
    await act(async () => reject(fail(401))); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login'); expect(router.state.location.state.from).toBe(path);
    expect(listarMedicos).not.toHaveBeenCalled(); expect(listarEspecialidades).not.toHaveBeenCalled();
  });
  it('navega desde menú, actualiza título, muestra estados independientes y no ofrece acciones futuras', async () => {
    const user = userEvent.setup(); mount('/administracion');
    await user.click(await screen.findByRole('link', { name: 'Especialidades' })); await screen.findByRole('table');
    expect(document.title).toBe('Especialidades | Clínica Vida Salud');
    expect(screen.getByRole('link', { name: 'Especialidades' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByText('Sin descripción')).toBeInTheDocument(); expect(within(screen.getByRole('table')).getByText('Inactiva')).toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: 'Médicos' })); await screen.findByRole('table');
    expect(document.title).toBe('Médicos | Clínica Vida Salud'); expect(within(screen.getByRole('table')).getByText('Inactivo')).toBeInTheDocument();
    expect(screen.getByText('(catálogo inactivo)')).toBeInTheDocument(); expect(screen.getByText('MED-ficticio')).toBeInTheDocument();
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /crear|editar|activar|eliminar|reservar/i })).not.toBeInTheDocument();
  });
  it('busca especialidades solo al enviar, conserva inactivas, pagina y reinicia/limpia filtros', async () => {
    listarEspecialidades.mockResolvedValue(listing([specialty], 1, 2)); const user = userEvent.setup(); mount(); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Nombre de especialidad'), ' Geriatría '); await user.selectOptions(screen.getByLabelText('Estado de especialidad'), '0');
    expect(listarEspecialidades).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await waitFor(() => expect(listarEspecialidades).toHaveBeenCalledTimes(2));
    expect(listarEspecialidades.mock.calls[1][0]).toEqual({ nombre: 'Geriatría', activa: '0', page: 1 });
    listarEspecialidades.mockResolvedValue(listing([specialty], 2, 2)); await user.click(screen.getByRole('button', { name: 'Siguiente' }));
    await waitFor(() => expect(listarEspecialidades.mock.calls[2][0].page).toBe(2)); expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Buscar' })); await waitFor(() => expect(listarEspecialidades.mock.calls[3][0].page).toBe(1));
    await user.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
    await waitFor(() => expect(listarEspecialidades.mock.calls[4][0]).toEqual({ nombre: '', activa: '', page: 1 })); expect(screen.getByLabelText('Nombre de especialidad')).toHaveValue('');
  });
  it('busca todo el catálogo paginado, conserva selección fuera de página y filtra médicos por BIGINT sin convertirlo', async () => {
    listarEspecialidades.mockResolvedValue(listing([specialty], 1, 2)); listarMedicos.mockResolvedValue(listing([doctor], 1, 2));
    const user = userEvent.setup(); mount(paths[1]); await screen.findByRole('table');
    const options = screen.getByRole('group', { name: 'Filtrar por especialidad' });
    await user.selectOptions(screen.getByLabelText('Especialidad del médico'), specialty.id_especialidad);
    listarEspecialidades.mockResolvedValue(listing([{ ...specialty, id_especialidad: '12', nombre: 'Otra especialidad', activa: true }], 2, 2));
    await user.click(within(options).getByRole('button', { name: 'Siguiente' }));
    await waitFor(() => expect(listarEspecialidades.mock.calls[1][0]).toEqual({ nombre: '', page: 2 }));
    expect(await screen.findByRole('option', { name: 'Geriatría ficticia (inactiva)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Especialidad del médico')).toHaveValue(specialty.id_especialidad);
    await user.type(screen.getByLabelText('Buscar nombre de especialidad'), ' Otra{Enter}');
    await waitFor(() => expect(listarEspecialidades.mock.calls[2][0]).toEqual({ nombre: 'Otra', page: 1 }));
    expect(listarMedicos).toHaveBeenCalledTimes(1);
    await user.type(screen.getByLabelText('Nombre o apellidos'), ' Profesional '); await user.selectOptions(screen.getByLabelText('Actividad profesional'), '0');
    await user.click(screen.getByRole('button', { name: 'Buscar', exact: true }));
    await waitFor(() => expect(listarMedicos.mock.calls[1][0]).toEqual({ nombre: 'Profesional', activo: '0', id_especialidad: specialty.id_especialidad, page: 1 }));
    const pages = screen.getByRole('navigation', { name: 'Paginación de médicos' });
    listarMedicos.mockResolvedValue(listing([doctor], 2, 2)); await user.click(within(pages).getByRole('button', { name: 'Siguiente' }));
    await waitFor(() => expect(listarMedicos.mock.calls[2][0].page).toBe(2));
    await user.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
    await waitFor(() => expect(listarMedicos.mock.calls[3][0]).toEqual({ nombre: '', activo: '', id_especialidad: '', page: 1 }));
    expect(screen.getByLabelText('Buscar nombre de especialidad')).toHaveValue(''); expect(screen.getByLabelText('Especialidad del médico')).toHaveValue('');
  });
  it.each(paths)('cancela e ignora respuestas antiguas en %s', async path => {
    const service = path === paths[0] ? listarEspecialidades : listarMedicos;
    let resolve; service.mockImplementationOnce(() => new Promise(done => { resolve = done; })).mockResolvedValue(listing([]));
    mount(path); await screen.findByRole('heading', { name: path === paths[0] ? 'Especialidades' : 'Médicos' });
    fireEvent.change(screen.getByLabelText(path === paths[0] ? 'Nombre de especialidad' : 'Nombre o apellidos'), { target: { value: 'Otra' } });
    fireEvent.submit(screen.getByRole('form', { name: path === paths[0] ? 'Buscar especialidades' : 'Buscar médicos' }));
    await screen.findByRole('heading', { name: /No hay (especialidades|médicos) para estos filtros/ });
    expect(service.mock.calls[0][1].aborted).toBe(true);
    await act(async () => resolve(listing(path === paths[0] ? [specialty] : [doctor])));
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });
  it.each([403, 419, 422, 500, undefined])('muestra error seguro %s, filtros conservados y recuperación manual', async status => {
    listarEspecialidades.mockResolvedValueOnce(listing([specialty])).mockRejectedValueOnce(status ? fail(status, { nombre: ['Revisa el nombre.'], password: ['NO MOSTRAR'] }) : new Error('SQL secreto')).mockResolvedValue(listing([]));
    const user = userEvent.setup(); mount(); await screen.findByRole('table'); await user.type(screen.getByLabelText('Nombre de especialidad'), 'Geriatría');
    await user.click(screen.getByRole('button', { name: 'Buscar' })); await screen.findByRole('alert');
    expect(screen.queryByText(/SQL secreto|NO MOSTRAR/)).not.toBeInTheDocument(); expect(screen.getByLabelText('Nombre de especialidad')).toHaveValue('Geriatría');
    if (status === 422) expect(screen.getByText('Revisa el nombre.')).toBeInTheDocument();
    expect(listarEspecialidades).toHaveBeenCalledTimes(2); await user.click(screen.getByRole('button', { name: 'Volver a consultar' }));
    await screen.findByRole('heading', { name: 'No hay especialidades para estos filtros' }); expect(listarEspecialidades).toHaveBeenCalledTimes(3);
  });
  it('respeta Retry-After al reconsultar o cambiar filtros sin repetir automáticamente', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    listarEspecialidades.mockRejectedValueOnce(fail(429)).mockResolvedValue(listing([])); mount(); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled(); expect(screen.getByRole('button', { name: 'Buscar' })).toBeDisabled();
    fireEvent.submit(screen.getByRole('form', { name: 'Buscar especialidades' }));
    await act(async () => {}); expect(listarEspecialidades).toHaveBeenCalledTimes(1);
    await act(async () => vi.advanceTimersByTime(2500));
    expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeEnabled(); expect(listarEspecialidades).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await act(async () => {});
    expect(listarEspecialidades).toHaveBeenCalledTimes(2);
  });
  it('un fallo del selector no bloquea consulta por nombre y actividad', async () => {
    listarEspecialidades.mockRejectedValue(fail(500)); const user = userEvent.setup(); mount(paths[1]); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Nombre o apellidos'), 'Profesional'); await user.click(screen.getByRole('button', { name: 'Buscar', exact: true }));
    await waitFor(() => expect(listarMedicos.mock.calls[1][0].nombre).toBe('Profesional'));
    expect(screen.getByRole('table')).toBeInTheDocument(); expect(screen.getByRole('alert')).toBeInTheDocument();
  });
  it('401 real por Axios invalida la sesión y retira el listado privado', async () => {
    const user = userEvent.setup(); const router = mount(); await screen.findByRole('table');
    api.defaults.adapter = async config => { throw new AxiosError('sesión', undefined, config, null, { status: 401, data: {} }); };
    listarEspecialidades.mockImplementation(() => api.get('/especialidades', { sessionBound: true }));
    await user.click(screen.getByRole('button', { name: 'Buscar' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.pathname).toBe('/login'); expect(screen.queryByText('Geriatría ficticia')).not.toBeInTheDocument();
  });
});
