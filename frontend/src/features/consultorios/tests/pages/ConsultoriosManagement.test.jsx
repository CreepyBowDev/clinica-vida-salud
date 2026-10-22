import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-22T12:00:00.123456Z';
const next = '2026-10-22T12:00:00.123457Z';
const root = '/administracion/consultorios';
let current, calls, write, read, pages;
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
const writes = () => calls.filter(c => c.method !== 'get');
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  current = { id_consultorio: id, codigo_consultorio: 'C-01', nombre: 'Sala ficticia', activo: true, actualizado_en: version, medicos_asignados: 2, citas_vigentes: 3 };
  calls = []; write = null; read = null; pages = 1;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url.includes('csrf-cookie')) return { config, status: 204, headers: {}, data: '' };
    if (config.method !== 'get') {
      if (write) return write(config);
      current = { ...current, ...JSON.parse(config.data), actualizado_en: next };
    } else if (read) return read(config);
    return { config, status: 200, headers: {}, data: config.url === '/consultorios' && config.method === 'get'
      ? { data: [current], meta: { total: pages, current_page: config.params?.page || 1, last_page: pages } } : { data: current } };
  };
});
afterEach(() => { api.defaults.adapter = original; });

it.each(['Recepcionista', 'Médico', 'Paciente'])('protege todas las rutas ante %s sin consultar el catálogo', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol });
  const router = mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  for (const suffix of ['/nuevo', `/${id}`, `/${id}/editar`]) await act(() => router.navigate(root + suffix));
  expect(calls).toHaveLength(0); expect(screen.queryByRole('link', { name: 'Consultorios' })).not.toBeInTheDocument();
});
it('recorre listado, alta, detalle reconsultado y edición con Axios y Router reales', async () => {
  const user = userEvent.setup(); mount();
  await user.click(await screen.findByRole('link', { name: 'Registrar consultorio' }));
  await user.type(screen.getByLabelText('Código'), ' Mi-Codigo '); await user.type(screen.getByLabelText('Nombre'), ' Nueva sala ');
  await user.click(screen.getByRole('button', { name: 'Registrar consultorio' }));
  await screen.findByRole('heading', { name: 'Detalle de consultorio' }); await screen.findByText('Nueva sala');
  expect(JSON.parse(writes()[0].data)).toEqual({ codigo_consultorio: 'Mi-Codigo', nombre: 'Nueva sala' });
  expect(screen.getByText('Médicos asignados')).toBeInTheDocument(); expect(screen.getByText('Citas vigentes')).toBeInTheDocument();
  await user.click(screen.getByRole('link', { name: 'Editar consultorio' }));
  const name = await screen.findByLabelText('Nombre'); await user.clear(name); await user.type(name, 'Corregida');
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('Corregida');
  expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(next);
  expect(document.title).toBe('Consultorios | Clínica Vida Salud');
});
it('aplica filtros solo al buscar, pagina con filtros aplicados y limpia a primera página', async () => {
  pages = 3; const user = userEvent.setup(); mount(); await screen.findByRole('table');
  const before = calls.length; await user.type(screen.getByLabelText('Código o nombre'), ' C_% ');
  expect(calls).toHaveLength(before);
  await user.selectOptions(screen.getByLabelText('Estado de consultorio'), '0');
  await user.selectOptions(screen.getByLabelText('Consultorios por página'), '50');
  await user.click(screen.getByRole('button', { name: 'Buscar', exact: true })); await screen.findByRole('table');
  expect(calls.at(-1).params).toEqual({ q: 'C_%', activo: '0', per_page: '50', page: 1 });
  await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByRole('table'); expect(calls.at(-1).params.page).toBe(2);
  await user.click(screen.getByRole('button', { name: 'Limpiar filtros' })); await screen.findByRole('table');
  expect(calls.at(-1).params).toEqual({ per_page: '20', page: 1 });
});
it('valida obligatorios, controles y no-op sin enviar escrituras', async () => {
  const user = userEvent.setup(); const router = mount(`${root}/nuevo`);
  await user.click(await screen.findByRole('button', { name: 'Registrar consultorio' })); expect(screen.getByLabelText('Código')).toHaveAttribute('aria-invalid', 'true');
  fireEvent.change(screen.getByLabelText('Código'), { target: { value: 'A\tB' } }); fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Sala' } });
  fireEvent.submit(screen.getByRole('form', { name: 'Registrar consultorio' })); expect(writes()).toHaveLength(0);
  await act(() => router.navigate(`${root}/${id}/editar`)); await screen.findByLabelText('Nombre');
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); expect(await screen.findByText('No hay cambios para guardar.')).toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('código duplicado conserva borrador corregible sin exponer respuesta técnica', async () => {
  write = config => { throw error(config, 409, 'CONSULTORIO_CODIGO_DUPLICADO'); };
  const user = userEvent.setup(); mount(`${root}/${id}/editar`); const input = await screen.findByLabelText('Código');
  await user.clear(input); await user.type(input, 'Duplicado'); await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
  await waitFor(() => expect(input).toHaveAttribute('aria-invalid', 'true')); expect(input).toHaveValue('Duplicado'); expect(input).toBeEnabled();
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(writes()).toHaveLength(1);
});
it('versión obsoleta bloquea envíos hasta revisión y adopción explícita de datos', async () => {
  const user = userEvent.setup(); mount(`${root}/${id}/editar`); const name = await screen.findByLabelText('Nombre');
  await user.clear(name); await user.type(name, 'Mi borrador');
  write = config => { current = { ...current, nombre: 'Concurrente', actualizado_en: next }; throw error(config, 409, 'CONSULTORIO_EDICION_OBSOLETA'); };
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('Concurrente');
  expect(name).toHaveValue('Mi borrador'); expect(name).toBeDisabled(); expect(writes()).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' })); expect(name).toHaveValue('Concurrente'); expect(name).toBeEnabled();
  write = null; await user.clear(name); await user.type(name, 'Revisada'); await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('Revisada');
  expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(next);
});
it.each([true, false])('confirma estado desde activo=%s, permite cancelar y reconsulta al guardar', async activo => {
  current.activo = activo; const user = userEvent.setup(); mount(`${root}/${id}`);
  const action = activo ? 'Desactivar consultorio' : 'Reactivar consultorio';
  await user.click(await screen.findByRole('button', { name: action })); expect(writes()).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: 'Volver a revisar' })); expect(writes()).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: action })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
  await screen.findByRole('button', { name: activo ? 'Reactivar consultorio' : 'Desactivar consultorio' });
  expect(JSON.parse(writes()[0].data)).toEqual({ activo: !activo, actualizado_en_original: version });
});
it.each(['CONSULTORIO_CON_ASIGNACIONES', 'CONSULTORIO_CON_OBLIGACIONES'])('muestra rechazo %s sin desactivar ni prometer cambios automáticos', async code => {
  write = config => { throw error(config, 409, code); }; const user = userEvent.setup(); mount(`${root}/${id}`);
  await user.click(await screen.findByRole('button', { name: 'Desactivar consultorio' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
  await screen.findByRole('alert'); expect(screen.getByText('Activo', { selector: '.catalog-status' })).toBeInTheDocument(); expect(writes()).toHaveLength(1);
  expect(screen.getByRole('alert')).toHaveTextContent('No se puede desactivar'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
});
it.each([undefined, 500])('alta con resultado incierto %s no permite repetir automáticamente', async status => {
  write = config => { throw error(config, status); }; const user = userEvent.setup(); mount(`${root}/nuevo`);
  await user.type(await screen.findByLabelText('Código'), 'A'); await user.type(screen.getByLabelText('Nombre'), 'Sala');
  await user.click(screen.getByRole('button', { name: 'Registrar consultorio' })); await screen.findByText(/consultorio podría haberse registrado/);
  expect(screen.getByRole('button', { name: 'Registrar consultorio' })).toBeDisabled(); expect(writes()).toHaveLength(1);
  expect(screen.getByRole('link', { name: 'Consultar catálogo' })).toBeInTheDocument();
});
it('doble submit durante petición pendiente produce solo una escritura', async () => {
  let resolve; write = config => new Promise(done => { resolve = () => done({ config, status: 201, headers: {}, data: { data: current } }); });
  const user = userEvent.setup(); mount(`${root}/nuevo`); await user.type(await screen.findByLabelText('Código'), 'A'); await user.type(screen.getByLabelText('Nombre'), 'Sala');
  const form = screen.getByRole('form', { name: 'Registrar consultorio' }); fireEvent.submit(form); fireEvent.submit(form);
  await waitFor(() => expect(writes()).toHaveLength(1)); await act(async () => resolve()); await screen.findByRole('heading', { name: 'Detalle de consultorio' });
});
it.each(['0', '9223372036854775808', 'invalido'])('ID inválido %s no consulta la API', async value => {
  mount(`${root}/${value}`); await screen.findByRole('heading', { name: 'Consultorio no disponible' }); expect(calls).toHaveLength(0);
});
it.each([403, 404, 419, 500])('detalle maneja HTTP %s sin mostrar información técnica', async status => {
  read = config => { throw error(config, status); }; mount(`${root}/${id}`); await screen.findByRole('alert');
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeInTheDocument();
});
it('422 muestra solo campos permitidos y mantiene el formulario', async () => {
  write = config => { throw error(config, 422, 'VALIDACION', { nombre: ['Revisa el nombre.'], secreto: ['No mostrar'] }); };
  const user = userEvent.setup(); mount(`${root}/${id}/editar`); const input = await screen.findByLabelText('Nombre'); await user.clear(input); await user.type(input, 'Borrador');
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('Revisa el nombre.');
  expect(input).toHaveValue('Borrador'); expect(screen.queryByText('No mostrar')).not.toBeInTheDocument();
});
it('lista vacía permite limpiar criterios sin inventar totales', async () => {
  read = config => ({ config, status: 200, headers: {}, data: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
  mount(); await screen.findByRole('heading', { name: 'No hay consultorios para estos filtros' }); expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
});

it('419 no reenvía escritura y permite un nuevo envío explícito', async () => {
  write = config => { throw error(config, 419); }; const user = userEvent.setup(); mount(`${root}/${id}/editar`);
  const name = await screen.findByLabelText('Nombre'); await user.clear(name); await user.type(name, 'Cambio');
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText(/Revisa el formulario y vuelve a enviarlo/);
  expect(writes()).toHaveLength(1); expect(name).toHaveValue('Cambio'); write = null;
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' })); await screen.findByText('Cambio'); expect(writes()).toHaveLength(2);
});
it('429 bloquea reenvío durante Retry-After', async () => {
  write = config => { const failure = error(config, 429); failure.response.headers['retry-after'] = '60'; throw failure; };
  const user = userEvent.setup(); mount(`${root}/${id}/editar`); const name = await screen.findByLabelText('Nombre');
  await user.clear(name); await user.type(name, 'Borrador'); await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
  await screen.findByText(/Espera 60 segundos/); expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeDisabled(); expect(writes()).toHaveLength(1);
});
it('401 retira datos privados y dirige al login sin reintentar', async () => {
  const user = userEvent.setup(); mount(`${root}/${id}`); await screen.findByText('Sala ficticia');
  write = config => { throw error(config, 401); };
  await user.click(screen.getByRole('button', { name: 'Desactivar consultorio' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('Sala ficticia')).not.toBeInTheDocument(); expect(writes()).toHaveLength(1);
});
it('estado con resultado incierto exige reconsulta y nueva confirmación', async () => {
  const user = userEvent.setup(); mount(`${root}/${id}`); await screen.findByText('Sala ficticia');
  write = config => { current = { ...current, activo: false, actualizado_en: next }; throw error(config, undefined); };
  await user.click(screen.getByRole('button', { name: 'Desactivar consultorio' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de estado' }));
  await screen.findByRole('heading', { name: 'Revisa el consultorio actual' });
  expect(screen.getByRole('button', { name: 'Desactivar consultorio' })).toBeDisabled();
  await user.click(await screen.findByRole('button', { name: 'Usar estado actual y volver a revisar' }));
  expect(screen.getByRole('button', { name: 'Reactivar consultorio' })).toBeEnabled(); expect(writes()).toHaveLength(1);
});
it('desmontar ante cambio de ID descarta detalle pendiente anterior', async () => {
  let resolve;
  read = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: current } }); });
  const router = mount(`${root}/${id}`); await screen.findByRole('status');
  await waitFor(() => expect(resolve).toBeTypeOf('function'));
  await act(() => router.navigate(`${root}/invalido`)); await act(async () => resolve());
  expect(screen.getByRole('heading', { name: 'Consultorio no disponible' })).toBeInTheDocument(); expect(screen.queryByText('Sala ficticia')).not.toBeInTheDocument();
});
