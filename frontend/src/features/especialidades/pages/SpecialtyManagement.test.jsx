import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../auth/context/AuthContext';
import { consultarSesion } from '../../auth/services/auth.service';
import { crearRutas } from '../../../app/router';
import { api } from '../../../api/api';

vi.mock('../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-21T12:00:00.123456Z';
const nextVersion = '2026-10-21T12:00:00.123457Z';
const root = '/administracion/especialidades';
let current, calls, write;
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: { 'retry-after': '2' }, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  current = { id_especialidad: id, nombre: 'Geriatría ficticia', descripcion: 'Atención ficticia', activa: true, actualizado_en: version };
  calls = []; write = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url.includes('csrf-cookie')) return { config, status: 204, headers: {}, data: '' };
    if (config.method !== 'get') {
      if (write) return write(config);
      current = { ...current, ...JSON.parse(config.data), actualizado_en: nextVersion };
    }
    return { config, status: 200, headers: {}, data: config.url === '/especialidades' && config.method === 'get'
      ? { data: [current], meta: { total: 1, current_page: 1, last_page: 1 } } : { data: current } };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });
const writes = () => calls.filter(c => c.method !== 'get');

describe('gestión de especialidades CU-09 fase 2', () => {
  it.each(['Recepcionista', 'Médico', 'Paciente'])('protege alta, edición y detalle para %s sin consultar API', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
    const router = mount(`${root}/nueva`); await screen.findByRole('heading', { name: 'Acceso no permitido' });
    await act(() => router.navigate(`${root}/${id}/editar`)); await act(() => router.navigate(`${root}/${id}`)); expect(calls).toHaveLength(0);
  });
  it('recorre lista → alta → detalle consultado → edición, con servicios/Axios reales', async () => {
    const user = userEvent.setup(); mount();
    await user.click(await screen.findByRole('link', { name: 'Registrar especialidad' }));
    await user.type(screen.getByLabelText('Nombre'), ' Nueva especialidad ficticia ');
    await user.click(screen.getByRole('button', { name: 'Registrar especialidad' }));
    await screen.findByRole('heading', { name: 'Detalle de especialidad' }); await screen.findByText('Nueva especialidad ficticia');
    expect(writes()).toHaveLength(1); expect(JSON.parse(writes()[0].data)).toEqual({ nombre: 'Nueva especialidad ficticia', descripcion: null });
    expect(calls.some(c => c.method === 'get' && c.url === `/especialidades/${id}`)).toBe(true);
    await user.click(screen.getByRole('link', { name: 'Editar especialidad' }));
    const name = await screen.findByLabelText('Nombre'); await user.clear(name); await user.type(name, 'Nombre corregido');
    await user.type(screen.getByLabelText('Descripción (opcional)'), 'Descripción ficticia');
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    await screen.findByRole('heading', { name: 'Detalle de especialidad' }); await screen.findByText('Nombre corregido');
    expect(JSON.parse(writes()[1].data)).toEqual({ nombre: 'Nombre corregido', descripcion: 'Descripción ficticia', actualizado_en_original: nextVersion });
  });
  it('enlaza del listado al detalle y valida nombre obligatorio/controles antes de escribir', async () => {
    const user = userEvent.setup(); mount();
    await user.click(await screen.findByRole('link', { name: 'Ver especialidad Geriatría ficticia' })); await screen.findByRole('button', { name: 'Inactivar especialidad' });
    await user.click(screen.getByRole('link', { name: 'Volver al catálogo' }));
    await user.click(await screen.findByRole('link', { name: 'Registrar especialidad' }));
    await user.click(screen.getByRole('button', { name: 'Registrar especialidad' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Revisa los campos');
    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Nombre\tinválido' } });
    fireEvent.change(screen.getByLabelText('Descripción (opcional)'), { target: { value: 'Con\nsalto' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Registrar especialidad' }));
    expect(screen.getByLabelText('Descripción (opcional)')).toHaveAttribute('aria-invalid', 'true'); expect(writes()).toHaveLength(0);
  });
  it.each(['nueva', `${id}/editar`])('conserva borrador y permite corregir nombre duplicado en %s', async suffix => {
    write = config => { throw error(config, 409, 'ESPECIALIDAD_NOMBRE_DUPLICADO'); };
    const user = userEvent.setup(); mount(`${root}/${suffix}`);
    const input = await screen.findByLabelText('Nombre'); await user.clear(input); await user.type(input, 'Nombre duplicado');
    await user.click(screen.getByRole('button', { name: suffix === 'nueva' ? 'Registrar especialidad' : 'Guardar cambios' }));
    await screen.findByText('Este nombre ya está registrado.'); expect(input).toHaveValue('Nombre duplicado'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
    expect(input).toBeEnabled(); expect(writes()).toHaveLength(1);
  });
  it('bloquea borrador obsoleto, consulta versión y exige adopción explícita antes de otro envío', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}/editar`);
    const name = await screen.findByLabelText('Nombre'); await user.clear(name); await user.type(name, 'Mi borrador');
    write = config => { current = { ...current, nombre: 'Versión concurrente', actualizado_en: nextVersion }; throw error(config, 409, 'ESPECIALIDAD_EDICION_OBSOLETA'); };
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    await screen.findByText('Versión concurrente'); expect(name).toHaveValue('Mi borrador'); expect(name).toBeDisabled(); expect(writes()).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' }));
    expect(name).toHaveValue('Versión concurrente'); expect(name).toBeEnabled();
    await user.clear(name); await user.type(name, 'Cambio revisado');
    write = null; await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    await screen.findByRole('heading', { name: 'Detalle de especialidad' }); expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(nextVersion);
  });
  it.each([true, false])('confirma cambio de estado desde activa=%s y vuelve a consultar sin alterar otros datos', async active => {
    current.activa = active; const user = userEvent.setup(); mount(`${root}/${id}`);
    await user.click(await screen.findByRole('button', { name: active ? 'Inactivar especialidad' : 'Activar especialidad' }));
    expect(writes()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: 'Volver a revisar' })); expect(writes()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: active ? 'Inactivar especialidad' : 'Activar especialidad' }));
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
    await screen.findByRole('button', { name: active ? 'Activar especialidad' : 'Inactivar especialidad' });
    expect(JSON.parse(writes()[0].data)).toEqual({ activa: !active, actualizado_en_original: version });
    expect(writes()[0].url).toBe(`/especialidades/${id}/estado`); expect(current.nombre).toBe('Geriatría ficticia');
  });
  it('estado obsoleto no se reenvía; adopción reinicia confirmación con versión vigente', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}`);
    await user.click(await screen.findByRole('button', { name: 'Inactivar especialidad' }));
    write = config => { current = { ...current, actualizado_en: nextVersion }; throw error(config, 409, 'ESPECIALIDAD_EDICION_OBSOLETA'); };
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
    await screen.findByRole('button', { name: 'Usar estado actual y reiniciar confirmación' });
    expect(screen.getByRole('button', { name: 'Inactivar especialidad' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Usar estado actual y reiniciar confirmación' })); expect(writes()).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Confirmar cambio de estado' })).not.toBeInTheDocument();
    write = null; await user.click(screen.getByRole('button', { name: 'Inactivar especialidad' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
    await screen.findByRole('button', { name: 'Activar especialidad' }); expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(nextVersion);
  });
  it.each([419, 422, 403])('error de alta %s conserva datos y no reenvía automáticamente', async status => {
    write = config => { throw error(config, status, undefined, { nombre: ['Revisa este nombre.'], password: ['SECRETO'] }); };
    const user = userEvent.setup(); mount(`${root}/nueva`); await user.type(await screen.findByLabelText('Nombre'), 'Borrador ficticio');
    await user.click(screen.getByRole('button', { name: 'Registrar especialidad' })); await screen.findByRole('alert');
    expect(screen.getByLabelText('Nombre')).toHaveValue('Borrador ficticio'); expect(writes()).toHaveLength(1);
    expect(screen.queryByText(/SQL secreto|SECRETO/)).not.toBeInTheDocument();
    if (status === 422) expect(screen.getByText('Revisa este nombre.')).toBeInTheDocument();
  });
  it.each([500, undefined])('alta incierta %s bloquea duplicación y dirige a consulta de catálogo', async status => {
    write = config => { throw error(config, status); }; const user = userEvent.setup(); mount(`${root}/nueva`);
    await user.type(await screen.findByLabelText('Nombre'), 'Borrador ficticio'); await user.click(screen.getByRole('button', { name: 'Registrar especialidad' }));
    await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Registrar especialidad' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Consultar catálogo' })).toHaveAttribute('href', root); expect(writes()).toHaveLength(1);
  });
  it('doble envío mientras guarda no produce dos escrituras', async () => {
    let finish; write = config => new Promise(resolve => { finish = () => resolve({ config, status: 201, headers: {}, data: { data: current } }); });
    mount(`${root}/nueva`); fireEvent.change(await screen.findByLabelText('Nombre'), { target: { value: 'Alta ficticia' } });
    const form = screen.getByRole('form', { name: 'Registrar especialidad' }); fireEvent.submit(form); fireEvent.submit(form);
    await waitFor(() => expect(writes()).toHaveLength(1)); expect(screen.getByRole('button', { name: 'Guardando…' })).toBeDisabled();
    await act(async () => finish()); await screen.findByRole('heading', { name: 'Detalle de especialidad' });
  });
  it('sin cambios no escribe y retirar descripción envía null manteniendo versión exacta', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}/editar`); await screen.findByLabelText('Nombre');
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('No hay cambios para guardar.'); expect(writes()).toHaveLength(0);
    await user.clear(screen.getByLabelText('Descripción (opcional)')); await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    await screen.findByRole('heading', { name: 'Detalle de especialidad' }); expect(JSON.parse(writes()[0].data)).toEqual({ nombre: 'Geriatría ficticia', descripcion: null, actualizado_en_original: version });
  });
  it.each(['editar', 'estado'])('resultado incierto en %s exige consulta actual sin repetir escritura', async operation => {
    const user = userEvent.setup(); mount(`${root}/${id}${operation === 'editar' ? '/editar' : ''}`);
    if (operation === 'editar') {
      const name = await screen.findByLabelText('Nombre'); await user.clear(name); await user.type(name, 'Borrador incierto');
    } else await user.click(await screen.findByRole('button', { name: 'Inactivar especialidad' }));
    write = config => { throw error(config); };
    await user.click(screen.getByRole('button', { name: operation === 'editar' ? 'Guardar cambios' : 'Confirmar cambio de estado' }));
    await screen.findByRole('heading', { name: 'Versión actual de la especialidad' });
    expect(screen.getByRole('button', { name: operation === 'editar' ? 'Guardar cambios' : 'Inactivar especialidad' })).toBeDisabled();
    expect(writes()).toHaveLength(1);
    if (operation === 'editar') expect(screen.getByLabelText('Nombre')).toHaveValue('Borrador incierto');
  });
  it('429 respeta espera y requiere nuevo envío manual', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    write = config => { throw error(config, 429); }; mount(`${root}/nueva`);
    fireEvent.change(await screen.findByLabelText('Nombre'), { target: { value: 'Alta ficticia' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Registrar especialidad' })); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: 'Registrar especialidad' })).toBeDisabled();
    fireEvent.submit(screen.getByRole('form', { name: 'Registrar especialidad' })); expect(writes()).toHaveLength(1);
    await act(async () => vi.advanceTimersByTime(2500)); expect(screen.getByRole('button', { name: 'Registrar especialidad' })).toBeEnabled(); expect(writes()).toHaveLength(1);
  });
  it('404 seguro y parámetro inválido sin petición HTTP', async () => {
    const router = mount(`${root}/0`); await screen.findByRole('heading', { name: 'Especialidad no disponible' }); expect(calls).toHaveLength(0);
    api.defaults.adapter = async config => { throw error(config, 404); };
    await act(() => router.navigate(`${root}/${id}`)); expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró la especialidad'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
  });
  it('401 durante escritura retira formulario privado y dirige a login', async () => {
    write = config => { throw error(config, 401); }; const user = userEvent.setup(); const router = mount(`${root}/nueva`);
    await user.type(await screen.findByLabelText('Nombre'), 'Alta ficticia'); await user.click(screen.getByRole('button', { name: 'Registrar especialidad' }));
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(router.state.location.pathname).toBe('/login'); expect(screen.queryByLabelText('Nombre')).not.toBeInTheDocument();
  });
});
