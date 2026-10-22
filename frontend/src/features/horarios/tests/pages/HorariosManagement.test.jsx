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
const original = api.defaults.adapter, id = '9223372036854775807', h = '9007199254740993', version = '2026-10-22T12:00:00.123456Z', next = '2026-10-22T12:00:00.123457Z';
const root = `/administracion/horarios/medicos/${id}`;
let calls, rows, currentVersion, impacto, write, previewTask, pages;
const writes = () => calls.filter(c => ['post', 'patch'].includes(c.method) && !c.url.endsWith('/impacto'));
const previews = () => calls.filter(c => c.url.endsWith('/impacto'));
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
function mount(path = root) { const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router; }
beforeEach(() => {
  vi.resetAllMocks(); calls = []; currentVersion = version; write = null; previewTask = null; pages = 1;
  rows = [{ id_horario: h, id_medico: id, dia_semana: 7, hora_inicio: '08:00:01', hora_fin: '12:00:01', activo: true, actualizado_en: version, turnos_completos: 16 }];
  impacto = { puede_confirmar: true, totales: { conflictos: 0 }, elementos: [] };
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  api.defaults.adapter = async config => {
    calls.push(config); const ok = data => ({ config, status: 200, headers: {}, data });
    if (config.url.includes('csrf-cookie')) return ok('');
    if (config.url === '/medicos') return ok({ data: [{ id_medico: id, nombres: 'Profesional', apellidos: 'Ficticio', activo: false, codigo_medico: 'MED-FICTICIO' }], meta: { total: pages, current_page: config.params.page, last_page: pages } });
    if (config.url === `/medicos/${id}`) return ok({ data: { id_medico: id, nombres: 'Profesional', apellidos: 'Ficticio', activo: false, codigo_medico: 'MED-FICTICIO' } });
    if (config.url.endsWith('/consultorio')) return ok({ data: { id_medico: id, consultorio: { codigo_consultorio: 'C-1', nombre: 'Sala ficticia', activo: true } } });
    if (config.url.endsWith('/impacto')) return previewTask ? previewTask(config) : ok({ data: impacto, links: { next: 'https://ajeno.invalid/no-usar' }, meta: { total: impacto.elementos.length, current_page: JSON.parse(config.data).page, last_page: 2 } });
    if (config.method === 'get') return ok({ data: { id_medico: id, actualizado_en: currentVersion, duracion_turno_minutos: 15, elementos: rows }, meta: { total: rows.length, current_page: config.params.page, last_page: pages } });
    if (write) return write(config);
    const payload = JSON.parse(config.data); currentVersion = next;
    rows = config.method === 'post' ? [...rows, { ...rows[0], ...payload, id_horario: '3' }] : rows.map(item => ({ ...item, ...payload }));
    return ok({ data: { ...rows.at(-1), actualizado_en: next } });
  };
});
afterEach(() => { api.defaults.adapter = original; });
async function open(user, accion = 'EDITAR') {
  await user.click(await screen.findByRole('button', { name: accion === 'CREAR' ? 'Registrar horario' : `${accion === 'ESTADO' ? 'Desactivar' : 'Editar'} horario ${h}` }));
  if (accion === 'EDITAR') { const fin = screen.getByLabelText('Hora final'); await user.clear(fin); await user.type(fin, '13:00:01'); }
}
async function preview(user) { await user.click(screen.getByRole('button', { name: 'Consultar impacto del horario' })); await screen.findByRole('heading', { name: 'Impacto del horario' }); }
async function save(user) { await user.click(screen.getByRole('button', { name: 'Revisar cambio de horario' })); await user.click(screen.getByRole('button', { name: 'Guardar horario' })); }

it.each(['Recepcionista', 'Médico', 'Paciente'])('protege listado y detalle de %s sin llamadas', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol }); const router = mount('/administracion/horarios');
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); await act(() => router.navigate(root)); expect(calls).toHaveLength(0);
});
it('selecciona Médico inactivo con búsqueda y paginación del servidor', async () => {
  pages = 3; const user = userEvent.setup(); mount('/administracion/horarios'); await screen.findByRole('table');
  const before = calls.length; await user.type(screen.getByLabelText('Nombre del médico'), 'Ficticio'); expect(calls).toHaveLength(before);
  await user.selectOptions(screen.getByLabelText('Actividad profesional'), '0'); await user.click(screen.getByRole('button', { name: 'Buscar médico' })); await screen.findByRole('table');
  expect(calls.at(-1).params).toMatchObject({ nombre: 'Ficticio', activo: '0', page: 1 });
  await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByRole('table'); expect(calls.at(-1).params.page).toBe(2);
  await user.click(screen.getByRole('link', { name: 'Horarios de Profesional Ficticio' })); await screen.findByText(/Duración vigente: 15 minutos/);
  expect(screen.getByText(/Médico inactivo/)).toBeInTheDocument(); expect(screen.getByText('08:00:01 – 12:00:01')).toBeInTheDocument();
});
it('filtra/pagina horarios preservando contrato y sin cálculos de turnos en cliente', async () => {
  pages = 3; const user = userEvent.setup(); mount(); await screen.findByRole('table'); expect(screen.getByText('16 turnos completos')).toBeInTheDocument();
  await user.selectOptions(screen.getByLabelText('Estado de horario'), '0'); await screen.findByRole('table');
  expect(calls.at(-1).params.activo).toBe('0'); await user.selectOptions(screen.getByLabelText('Horarios por página'), '10'); await screen.findByRole('table');
  await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByRole('table'); expect(calls.at(-1).params).toEqual({ activo: '0', per_page: 10, page: 2 });
});
it('registra horario con segundos, impacto y confirmación, y reconsulta versión compartida', async () => {
  const user = userEvent.setup(); mount(); await open(user, 'CREAR');
  await user.selectOptions(screen.getByLabelText('Día de atención'), '3'); await user.type(screen.getByLabelText('Hora inicial'), '08:00:01'); await user.type(screen.getByLabelText('Hora final'), '08:50:01');
  await preview(user); expect(writes()).toHaveLength(0); await save(user); await screen.findByText('Horario guardado. Se consultó nuevamente el listado.');
  expect(JSON.parse(writes()[0].data)).toEqual({ dia_semana: 3, hora_inicio: '08:00:01', hora_fin: '08:50:01', actualizado_en_original: version });
  await open(user); await preview(user); expect(JSON.parse(previews().at(-1).data).actualizado_en_original).toBe(next);
});
it('edita solo campos cambiados y conserva segundos originales sin UTC', async () => {
  const user = userEvent.setup(); mount(); await open(user); await preview(user); await save(user); await screen.findByText(/Horario guardado/);
  expect(JSON.parse(writes()[0].data)).toEqual({ hora_fin: '13:00:01', actualizado_en_original: version }); expect(writes()[0].url).toBe(`/medicos/${id}/horarios/${h}`);
});
it.each([true, false])('actividad del horario desde activo=%s exige impacto y confirmación sin tocar Médico', async activo => {
  rows[0].activo = activo; const user = userEvent.setup(); mount();
  await user.click(await screen.findByRole('button', { name: `${activo ? 'Desactivar' : 'Reactivar'} horario ${h}` })); await preview(user); await save(user);
  await screen.findByText(/Horario guardado/); expect(JSON.parse(writes()[0].data)).toEqual({ activo: !activo, actualizado_en_original: version });
  expect(screen.getByText(/Médico inactivo/)).toBeInTheDocument(); expect(writes()[0].url).toBe(`/medicos/${id}/horarios/${h}/estado`);
});
it('no-op y horas inválidas no generan peticiones', async () => {
  const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('button', { name: `Editar horario ${h}` }));
  await user.click(screen.getByRole('button', { name: 'Consultar impacto del horario' })); await screen.findByText('No hay cambios para guardar.');
  fireEvent.change(screen.getByLabelText('Hora final'), { target: { value: '24:00:00' } }); await user.click(screen.getByRole('button', { name: 'Consultar impacto del horario' }));
  expect(screen.getByLabelText('Hora final')).toHaveAttribute('aria-invalid', 'true'); expect(previews()).toHaveLength(0); expect(writes()).toHaveLength(0);
});
it.each(['HORARIO_CITAS_SIN_COBERTURA', 'CONSULTORIO_NO_DISPONIBLE', 'PLANIFICACION_CONFLICTO', 'DURACION_INCOMPATIBLE'])('conflicto %s no habilita guardar ni muestra clínica', async codigo => {
  impacto = { puede_confirmar: false, totales: { conflictos: 42 }, elementos: [{ codigo, id_cita: '9007199254740995', id_medico: id, motivo_consulta: 'Clínica secreta', inicio_at: '2026-11-01T12:00:00.000000Z' }] };
  const user = userEvent.setup(); mount(); await open(user); await preview(user); expect(screen.getByText('42 conflictos en la propuesta completa.')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Revisar cambio de horario' })).not.toBeInTheDocument(); expect(screen.queryByText('Clínica secreta')).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Impacto siguiente' })); await screen.findByText(/página 2 de 2/);
  expect(JSON.parse(previews()[1].data)).toMatchObject({ accion: 'EDITAR', id_horario: h, hora_fin: '13:00:01', page: 2 }); expect(calls.some(c => c.url.includes('ajeno.invalid'))).toBe(false);
});
it('cambiar campos invalida diagnóstico y confirmación anteriores', async () => {
  const user = userEvent.setup(); mount(); await open(user); await preview(user); await user.click(screen.getByRole('button', { name: 'Revisar cambio de horario' }));
  fireEvent.change(screen.getByLabelText('Hora final'), { target: { value: '14:00:00' } });
  expect(screen.queryByRole('button', { name: 'Guardar horario' })).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('diagnóstico tardío se descarta al editar la propuesta', async () => {
  let resolve; previewTask = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: impacto, meta: { current_page: 1, last_page: 1, total: 0 } } }); });
  const user = userEvent.setup(); mount(); await open(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto del horario' }));
  await waitFor(() => expect(resolve).toBeTypeOf('function')); fireEvent.change(screen.getByLabelText('Hora final'), { target: { value: '14:00:00' } });
  await act(async () => resolve()); expect(screen.queryByRole('heading', { name: 'Impacto del horario' })).not.toBeInTheDocument();
});
it.each(['MEDICO_EDICION_OBSOLETA', undefined])('versión obsoleta/resultado incierto %s conserva borrador bloqueado y exige descarte explícito', async code => {
  write = config => { throw error(config, code ? 409 : undefined, code); }; const user = userEvent.setup(); mount(); await open(user); await preview(user); await save(user);
  await screen.findByRole('alert'); expect(screen.getByLabelText('Hora final')).toHaveValue('13:00:01'); expect(screen.getByLabelText('Hora final')).toBeDisabled(); expect(writes()).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Descartar borrador y consultar horarios actuales' })); await screen.findByRole('table'); expect(writes()).toHaveLength(1);
});
it('409 tras vista previa compatible obliga a revisar impacto nuevo', async () => {
  write = config => { throw error(config, 409, 'HORARIO_CITAS_SIN_COBERTURA'); }; const user = userEvent.setup(); mount(); await open(user); await preview(user); await save(user);
  await screen.findByText(/El cambio deja citas vigentes sin cobertura completa/); expect(screen.queryByRole('button', { name: 'Guardar horario' })).not.toBeInTheDocument();
  expect(screen.getByLabelText('Hora final')).toHaveValue('13:00:01'); expect(screen.getByLabelText('Hora final')).toBeEnabled(); expect(writes()).toHaveLength(1);
});
it.each([419, 422, 429, 403, 404, 500])('error de escritura HTTP %s no reenvía ni expone datos técnicos', async status => {
  write = config => { const e = error(config, status, 'VALIDACION', { hora_fin: ['Revisa la hora final.'], secreto: ['No mostrar'] }); if (status === 429) e.response.headers['retry-after'] = '60'; throw e; };
  const user = userEvent.setup(); mount(); await open(user); await preview(user); await save(user); await screen.findByRole('alert');
  expect(writes()).toHaveLength(1); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.queryByText('No mostrar')).not.toBeInTheDocument();
  if (status === 429) expect(screen.getByRole('button', { name: 'Consultar impacto del horario' })).toBeDisabled();
});
it('401 en escritura desmonta datos y conduce al login', async () => {
  write = config => { throw error(config, 401); }; const user = userEvent.setup(); mount(); await open(user); await preview(user); await save(user);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText(/Profesional Ficticio/)).not.toBeInTheDocument();
});
it('doble submit produce una sola escritura', async () => {
  let resolve; write = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: rows[0] } }); });
  const user = userEvent.setup(); mount(); await open(user); await preview(user); await user.click(screen.getByRole('button', { name: 'Revisar cambio de horario' }));
  const form = screen.getByRole('form', { name: 'Editar horario' }); fireEvent.submit(form); fireEvent.submit(form); await waitFor(() => expect(writes()).toHaveLength(1));
  await act(async () => resolve()); await screen.findByText(/Horario guardado/);
});
it.each(['0', '9223372036854775808'])('ID Médico inválido %s no consulta', async value => {
  mount(`/administracion/horarios/medicos/${value}`); await screen.findByRole('heading', { name: 'Médico no disponible' }); expect(calls).toHaveLength(0);
});
it('vacío permite alta sin inventar intervalos', async () => {
  rows = []; mount(); await screen.findByRole('heading', { name: 'No hay horarios para estos filtros' }); expect(screen.getByRole('button', { name: 'Registrar horario' })).toBeEnabled();
});
