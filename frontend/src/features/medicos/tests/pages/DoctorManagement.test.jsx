import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-21T12:00:00.123456Z';
const nextVersion = '2026-10-21T12:00:00.123457Z';
const root = '/administracion/medicos';
const specialties = [
  { id_especialidad: id, nombre: 'Geriatría ficticia', activa: false, habilitada: true },
  { id_especialidad: '2', nombre: 'Otra especialidad ficticia', activa: true, habilitada: false },
];
const fields = { 'Registro profesional': 'REG-ficticio', Nombres: 'Profesional', Apellidos: 'Ficticia', Teléfono: '70000000', 'Correo de contacto': 'contacto@example.test' };
let current, calls, write, catalog;
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: { 'retry-after': '2' }, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
const listing = (data, page = 1, last = 1) => ({ data, meta: { total: data.length, current_page: page, last_page: last } });
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  current = { id_medico: id, codigo_medico: 'MED-ficticio', registro_profesional: 'REG-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'contacto@example.test', activo: false, cuenta_vinculada: true, actualizado_en: version, especialidades: specialties, password: 'NO MOSTRAR', diagnostico: 'NO MOSTRAR' };
  calls = []; write = null; catalog = () => listing(specialties);
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url.includes('csrf-cookie')) return { config, status: 204, headers: {}, data: '' };
    if (config.url === '/especialidades') return { config, status: 200, headers: {}, data: catalog(config) };
    if (config.method !== 'get') {
      if (write) return write(config);
      const data = JSON.parse(config.data);
      current = { ...current, ...data, especialidades: data.especialidades ? specialties.filter(item => data.especialidades.includes(item.id_especialidad)).map(item => ({ ...item, habilitada: true })) : current.especialidades, actualizado_en: nextVersion };
    }
    return { config, status: 200, headers: {}, data: config.url === '/medicos' && config.method === 'get' ? listing([current]) : { data: current } };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });
const writes = () => calls.filter(c => c.method !== 'get');
async function fill(user) { for (const [label, value] of Object.entries(fields)) await user.type(await screen.findByLabelText(label, { exact: true }), value); }

describe('datos profesionales y alta CU-10 fase 3', () => {
  it.each(['Recepcionista', 'Médico', 'Paciente'])('protege las tres nuevas rutas frente a %s sin consultar datos', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
    const router = mount(`${root}/nuevo`); await screen.findByRole('heading', { name: 'Acceso no permitido' });
    await act(() => router.navigate(`${root}/${id}/editar`)); await act(() => router.navigate(`${root}/${id}`)); expect(calls).toHaveLength(0);
  });
  it('lista → detalle muestra historia, catálogo, cuenta y actividad independientes sin contenido ajeno', async () => {
    const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('link', { name: 'Ver médico Profesional Ficticia' }));
    await screen.findByText('Retirada; asociación histórica', { exact: false });
    expect(screen.getByText('Cuenta vinculada; su estado se consulta en gestión de cuentas')).toBeInTheDocument();
    expect(screen.getByText('Inactivo', { exact: true })).toBeInTheDocument(); expect(screen.getByText('MED-ficticio')).toBeInTheDocument();
    expect(screen.queryByText('NO MOSTRAR')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Activar profesional' })).toBeInTheDocument(); expect(screen.queryByRole('button', { name: /retirar|reservar/i })).not.toBeInTheDocument();
  });
  it('alta con selección multipágina conserva IDs de texto y reconsulta detalle confirmado', async () => {
    catalog = config => listing([config.params.page === 2 ? specialties[1] : specialties[0]], config.params.page || 1, 2);
    const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('link', { name: 'Registrar médico' })); await fill(user);
    await user.click(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' }));
    await user.click(screen.getByRole('button', { name: 'Siguiente' }));
    await user.click(await screen.findByRole('checkbox', { name: 'Otra especialidad ficticia' }));
    const selected = screen.getByRole('region', { name: 'Especialidades seleccionadas' });
    expect(within(selected).getByText('Geriatría ficticia (catálogo inactivo)')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Registrar médico' })); await screen.findByRole('heading', { name: 'Detalle de médico' });
    await screen.findByRole('link', { name: 'Editar datos profesionales' });
    expect(writes()).toHaveLength(1); expect(JSON.parse(writes()[0].data)).toEqual({ registro_profesional: 'REG-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'contacto@example.test', especialidades: [id, '2'] });
    expect(calls.filter(c => c.method === 'get' && c.url === `/medicos/${id}`)).toHaveLength(1);
  });
  it('búsqueda de especialidades con Enter no envía el alta, conserva selección y permite quitar', async () => {
    const user = userEvent.setup(); mount(`${root}/nuevo`);
    await user.click(await screen.findByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' }));
    await user.type(screen.getByLabelText('Buscar especialidad por nombre'), ' Otra{Enter}');
    await waitFor(() => expect(calls.filter(c => c.url === '/especialidades').at(-1).params).toEqual({ nombre: 'Otra', page: 1, per_page: 20 }));
    expect(writes()).toHaveLength(0); await user.click(screen.getByRole('button', { name: 'Quitar Geriatría ficticia' })); expect(screen.getByText('No has seleccionado especialidades.')).toBeInTheDocument();
  });
  it('validación obligatoria y al menos una especialidad no escribe', async () => {
    const user = userEvent.setup(); mount(`${root}/nuevo`); await screen.findByLabelText('Nombres');
    await user.click(screen.getByRole('button', { name: 'Registrar médico' })); await screen.findByRole('alert');
    expect(screen.getByLabelText('Nombres')).toHaveAttribute('aria-invalid', 'true'); expect(screen.getByText('Selecciona al menos una especialidad inicial.')).toBeInTheDocument(); expect(writes()).toHaveLength(0);
    fireEvent.change(screen.getByLabelText('Registro profesional'), { target: { value: 'REG\tinválido' } });
    fireEvent.change(screen.getByLabelText('Correo de contacto'), { target: { value: 'correo-invalido' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Registrar médico' })); expect(screen.getByText('Introduce un correo electrónico válido.')).toBeInTheDocument(); expect(writes()).toHaveLength(0);
  });
  it('catálogo vacío y fallo no permiten inventar IDs, se puede reconsultar manualmente', async () => {
    catalog = () => listing([]); const user = userEvent.setup(); mount(`${root}/nuevo`);
    await screen.findByRole('heading', { name: 'No hay especialidades para este nombre' });
    catalog = config => { throw error(config, 500); };
    await user.click(screen.getByRole('button', { name: 'Buscar especialidades' })); await screen.findByRole('alert');
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    catalog = () => listing(specialties); await user.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('checkbox', { name: 'Otra especialidad ficticia' });
    expect(writes()).toHaveLength(0);
  });
  it('edición no modifica asociaciones, cuenta, actividad ni código; no-op no escribe', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}`); await user.click(await screen.findByRole('link', { name: 'Editar datos profesionales' }));
    await screen.findByLabelText('Nombres'); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Guardar datos profesionales' })); await screen.findByText('No hay cambios para guardar.'); expect(writes()).toHaveLength(0);
    const contact = screen.getByLabelText('Correo de contacto'); await user.clear(contact); await user.type(contact, ' NUEVO@example.test ');
    await user.click(screen.getByRole('button', { name: 'Guardar datos profesionales' })); await screen.findByRole('heading', { name: 'Detalle de médico' }); await screen.findByText('nuevo@example.test');
    expect(JSON.parse(writes()[0].data)).toEqual({ registro_profesional: 'REG-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'nuevo@example.test', actualizado_en_original: version });
    expect(current.activo).toBe(false); expect(current.cuenta_vinculada).toBe(true); expect(current.especialidades).toEqual(specialties); expect(current.codigo_medico).toBe('MED-ficticio');
  });
  it('409 obsoleto conserva borrador y compara versión antes de adoptar y guardar', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}/editar`); const name = await screen.findByLabelText('Nombres'); await user.clear(name); await user.type(name, 'Mi borrador');
    write = config => { current = { ...current, nombres: 'Cambio concurrente', actualizado_en: nextVersion }; throw error(config, 409, 'MEDICO_EDICION_OBSOLETA'); };
    await user.click(screen.getByRole('button', { name: 'Guardar datos profesionales' })); await screen.findByText('Cambio concurrente Ficticia');
    expect(name).toHaveValue('Mi borrador'); expect(name).toBeDisabled(); expect(writes()).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' })); expect(name).toHaveValue('Cambio concurrente');
    await user.clear(name); await user.type(name, 'Revisado'); write = null;
    await user.click(screen.getByRole('button', { name: 'Guardar datos profesionales' })); await screen.findByRole('heading', { name: 'Detalle de médico' }); expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(nextVersion);
  });
  it.each([403, 419, 422, 409])('error %s conserva datos y selección, sin reenvío automático', async status => {
    write = config => { throw error(config, status, status === 409 ? 'MEDICO_CONFLICTO_UNICIDAD' : undefined, { correo_contacto: ['Revisa este correo.'], 'especialidades.0': ['Inválida'], password: ['SECRETO'] }); };
    const user = userEvent.setup(); mount(`${root}/nuevo`); await fill(user); await user.click(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' }));
    await user.click(screen.getByRole('button', { name: 'Registrar médico' })); await screen.findByRole('alert');
    expect(screen.getByLabelText('Nombres')).toHaveValue('Profesional'); expect(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })).toBeChecked();
    if (status === 422) { expect(screen.getByText('Revisa este correo.')).toBeInTheDocument(); expect(screen.getByText('Revisa las especialidades seleccionadas; alguna opción no es válida.')).toBeInTheDocument(); }
    expect(screen.queryByText(/SQL secreto|SECRETO/)).not.toBeInTheDocument(); expect(writes()).toHaveLength(1);
  });
  it.each(['nuevo', `${id}/editar`])('red incierta en %s bloquea reenvío y orienta a consulta', async suffix => {
    const user = userEvent.setup(); mount(`${root}/${suffix}`);
    if (suffix === 'nuevo') { await fill(user); await user.click(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })); }
    else { const name = await screen.findByLabelText('Nombres'); await user.clear(name); await user.type(name, 'Borrador incierto'); }
    write = config => { throw error(config); }; await user.click(screen.getByRole('button', { name: suffix === 'nuevo' ? 'Registrar médico' : 'Guardar datos profesionales' })); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: suffix === 'nuevo' ? 'Registrar médico' : 'Guardar datos profesionales' })).toBeDisabled(); expect(writes()).toHaveLength(1);
    if (suffix === 'nuevo') expect(screen.getByRole('link', { name: 'Consultar médicos' })).toHaveAttribute('href', root);
    else await screen.findByRole('heading', { name: 'Versión actual del profesional' });
  });
  it('doble envío solo crea una escritura', async () => {
    let finish; write = config => new Promise(resolve => { finish = () => resolve({ config, status: 201, headers: {}, data: { data: current } }); });
    const user = userEvent.setup(); mount(`${root}/nuevo`); await fill(user); await user.click(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' }));
    const form = screen.getByRole('form', { name: 'Registrar médico' }); fireEvent.submit(form); fireEvent.submit(form);
    await waitFor(() => expect(writes()).toHaveLength(1)); expect(screen.getByRole('button', { name: 'Guardando…' })).toBeDisabled();
    await act(async () => finish()); await screen.findByRole('heading', { name: 'Detalle de médico' });
  });
  it('429 espera sin reenviar automáticamente', async () => {
    const user = userEvent.setup(); mount(`${root}/${id}/editar`); const phone = await screen.findByLabelText('Teléfono'); await user.clear(phone); await user.type(phone, '70000001');
    vi.useFakeTimers({ shouldAdvanceTime: true }); write = config => { throw error(config, 429); };
    fireEvent.submit(screen.getByRole('form', { name: 'Editar médico' })); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: 'Guardar datos profesionales' })).toBeDisabled();
    fireEvent.submit(screen.getByRole('form', { name: 'Editar médico' })); expect(writes()).toHaveLength(1);
    await act(async () => vi.advanceTimersByTime(2500)); expect(screen.getByRole('button', { name: 'Guardar datos profesionales' })).toBeEnabled(); expect(writes()).toHaveLength(1);
  });
  it('404 seguro y parámetros inválidos sin HTTP', async () => {
    const router = mount(`${root}/0`); await screen.findByRole('heading', { name: 'Médico no disponible' }); expect(calls).toHaveLength(0);
    api.defaults.adapter = async config => { throw error(config, 404); }; await act(() => router.navigate(`${root}/${id}/editar`));
    expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró el médico'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
  });
  it('401 durante edición retira datos y lleva a login', async () => {
    const user = userEvent.setup(); const router = mount(`${root}/${id}/editar`); const phone = await screen.findByLabelText('Teléfono'); await user.clear(phone); await user.type(phone, '70000001');
    write = config => { throw error(config, 401); }; await user.click(screen.getByRole('button', { name: 'Guardar datos profesionales' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(router.state.location.pathname).toBe('/login'); expect(screen.queryByLabelText('Teléfono')).not.toBeInTheDocument();
  });
});
