import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { cita, detalle, pagina, sesion } from '../utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const doctors = [{ ...cita.medico, activo: false }, ...Array.from({ length: 5 }, (_, i) => ({ id_medico: String(i + 40), codigo_medico: `MED-${i}`, nombres: 'Otro', apellidos: `Profesional ${i}`, activo: true }))];
let rol, calls, respond, rows;
const ok = (config, data) => ({ config, status: 200, headers: {}, data });
const fail = (config, status, fields = {}) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { message: 'SQL-PRIVADO', details: fields } } } : undefined);
function defaultResponse(config) {
  if (config.url.startsWith('/citas/')) return ok(config, { data: { ...detalle(rol), id_cita: config.url.split('/').at(-1) } });
  const p = config.params;
  if (config.url === '/agenda/medicos') {
    const filtered = doctors.filter(doc => !p.nombre || `${doc.nombres} ${doc.apellidos}`.includes(p.nombre));
    return ok(config, pagina(filtered.slice((p.page - 1) * p.per_page, p.page * p.per_page), {
      total: filtered.length, current_page: p.page, per_page: p.per_page, last_page: Math.max(1, Math.ceil(filtered.length / p.per_page)),
    }));
  }
  const medico = doctors.find(doc => doc.id_medico === p.id_medico) ?? doctors[0];
  const data = Array.from({ length: rows }, (_, i) => ({ ...structuredClone(cita), id_cita: String(i + 1), codigo_cita: `CIT-${i + 1}`, medico,
    estado: p.estado || 'Programada', motivo_breve: 'NO-SALIR', paciente: { ...cita.paciente, telefono: 'NO-SALIR', documento_identidad: 'NO-SALIR' } }));
  return ok(config, pagina(data.slice((p.page - 1) * p.per_page, p.page * p.per_page), {
    total: rows, current_page: p.page, per_page: p.per_page, last_page: Math.max(1, Math.ceil(rows / p.per_page)), medico,
    filtros: { id_medico: medico.id_medico, fecha: p.fecha ?? '2026-10-31', estado: p.estado ?? null },
  }));
}
function mount(path = '/agenda') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const agendaCalls = () => calls.filter(call => call.url === '/agenda');
const selectFirst = async () => {
  await userEvent.click(await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' }));
  await screen.findByRole('link', { name: 'Ver cita CIT-1' });
};
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; calls = []; rows = 1;
  consultarSesion.mockImplementation(async () => sesion(rol)); respond = defaultResponse;
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });

it.each(['Administrador', 'Recepcionista'])('%s selecciona explícitamente Médico, incluidos inactivos, sin agenda arbitraria', async role => {
  rol = role; mount();
  await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' });
  expect(screen.getByRole('heading', { name: 'Selecciona un médico para consultar su agenda' })).toBeInTheDocument();
  expect(agendaCalls()).toHaveLength(0);
  expect(screen.getByRole('button', { name: 'Consultar agenda', exact: true })).toBeDisabled();
  expect(screen.getByText(/MED-FICTICIO · Inactivo/)).toBeInTheDocument();
  await selectFirst();
  expect(agendaCalls()).toHaveLength(1); expect(agendaCalls()[0].params).toEqual({ page: 1, per_page: 20, id_medico: cita.medico.id_medico });
  expect(screen.getByLabelText('Fecha de atención')).toHaveValue('2026-10-31');
  expect(screen.getByRole('region', { name: 'Contexto de la agenda' })).toHaveTextContent('31/10/2026');
  expect(within(screen.getByRole('main')).getByText('Sala conservada ficticia')).toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent('NO-SALIR');
  expect(calls.every(call => call.method === 'get')).toBe(true);
});
it('Médico consulta su agenda sin selector ni id_medico/fecha del dispositivo', async () => {
  rol = 'Médico'; mount(); await screen.findByRole('link', { name: 'Ver cita CIT-1' });
  expect(calls).toHaveLength(1); expect(agendaCalls()[0].params).toEqual({ page: 1, per_page: 20 });
  expect(screen.queryByLabelText('Nombre, apellidos o código de médico')).not.toBeInTheDocument();
  expect(screen.getByLabelText('Fecha de atención')).toHaveValue('2026-10-31');
  expect(screen.getByRole('link', { name: 'Consultar agenda médica' })).toHaveAttribute('aria-current', 'page');
});
it('Paciente no obtiene agenda ni selector por ruta directa, y no recibe enlace de sidebar', async () => {
  rol = 'Paciente'; mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(calls).toHaveLength(0); expect(screen.queryByRole('link', { name: 'Consultar agenda médica' })).not.toBeInTheDocument();
});
it.each(['guest', 'restricted'])('%s no obtiene datos de agenda', async kind => {
  if (kind === 'guest') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  mount(); await screen.findByRole('heading', { name: kind === 'guest' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' });
  expect(calls).toHaveLength(0);
});
it('filtros se aplican expresamente, reinician página y mantienen fecha civil/estado independientes', async () => {
  rol = 'Médico'; rows = 21; mount(); await screen.findByRole('link', { name: 'Ver cita CIT-1' }); const user = userEvent.setup();
  await user.click(within(screen.getByRole('navigation', { name: 'Paginación de citas' })).getByRole('button', { name: 'Siguiente' }));
  await screen.findByRole('link', { name: 'Ver cita CIT-21' });
  expect(agendaCalls().at(-1).params).toMatchObject({ page: 2, fecha: '2026-10-31' });
  await user.clear(screen.getByLabelText('Fecha de atención')); await user.type(screen.getByLabelText('Fecha de atención'), '2000-02-29');
  await user.selectOptions(screen.getByLabelText('Estado de cita'), 'Cancelada'); await user.selectOptions(screen.getByLabelText('Citas por página'), '10');
  expect(agendaCalls()).toHaveLength(2);
  await user.click(screen.getByRole('button', { name: 'Consultar agenda', exact: true }));
  await screen.findByRole('link', { name: 'Ver cita CIT-1' });
  expect(agendaCalls().at(-1).params).toEqual({ page: 1, per_page: 10, fecha: '2000-02-29', estado: 'Cancelada' });
  await user.click(screen.getByRole('button', { name: 'Restablecer filtros' }));
  await waitFor(() => expect(agendaCalls().at(-1).params).toEqual({ page: 1, per_page: 20 }));
});
it('selector tiene búsqueda y paginación propias, sin alterar selección hasta elegir otro Médico', async () => {
  mount(); const user = userEvent.setup();
  await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' });
  await user.click(within(screen.getByRole('navigation', { name: 'Paginación de médicos' })).getByRole('button', { name: 'Siguiente' }));
  await screen.findByRole('button', { name: 'Consultar agenda de Otro Profesional 4' });
  expect(agendaCalls()).toHaveLength(0);
  await user.type(screen.getByLabelText('Nombre, apellidos o código de médico'), 'Profesional 0');
  await user.click(screen.getByRole('button', { name: 'Buscar médico' }));
  await screen.findByRole('button', { name: 'Consultar agenda de Otro Profesional 0' });
  expect(calls.at(-1).params).toEqual({ nombre: 'Profesional 0', page: 1, per_page: 5 });
  await user.click(screen.getByRole('button', { name: 'Consultar agenda de Otro Profesional 0' }));
  await screen.findByRole('link', { name: 'Ver cita CIT-1' }); expect(agendaCalls()[0].params.id_medico).toBe('40');
});
it('selector vacío no dispara consulta global de agenda', async () => {
  mount(); await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' });
  const user = userEvent.setup(); await user.type(screen.getByLabelText('Nombre, apellidos o código de médico'), 'Inexistente');
  await user.click(screen.getByRole('button', { name: 'Buscar médico' }));
  await screen.findByRole('heading', { name: 'No hay médicos para esta búsqueda' }); expect(agendaCalls()).toHaveLength(0);
});
it('agenda vacía mantiene contexto y no afirma disponibilidad o cambio de estado', async () => {
  rol = 'Médico'; rows = 0; mount(); await screen.findByRole('heading', { name: 'No hay citas para estos filtros' });
  expect(screen.getByRole('region', { name: 'Contexto de la agenda' })).toHaveTextContent('Profesional Ficticio');
  expect(screen.getByText(/no indica disponibilidad para reservar/)).toBeInTheDocument();
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista', 'Médico'])('%s listado → detalle → volver conserva solo criterios y reconsulta datos', async role => {
  rol = role; rows = 21; mount(); const user = userEvent.setup();
  if (rol !== 'Médico') await selectFirst(); else await screen.findByRole('link', { name: 'Ver cita CIT-1' });
  await user.click(within(screen.getByRole('navigation', { name: 'Paginación de citas' })).getByRole('button', { name: 'Siguiente' }));
  await user.click(await screen.findByRole('link', { name: 'Ver cita CIT-21' }));
  await screen.findByRole('region', { name: `Cita ${cita.codigo_cita}` });
  expect(screen.getByRole('main')).toHaveFocus();
  expect(screen.getByRole('link', { name: 'Volver a agenda médica' })).toHaveAttribute('href', '/agenda');
  if (rol === 'Administrador') expect(screen.queryByRole('region', { name: 'Motivo breve' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('link', { name: 'Volver a agenda médica' }));
  await screen.findByRole('link', { name: 'Ver cita CIT-21' });
  expect(agendaCalls()).toHaveLength(3); expect(agendaCalls().at(-1).params).toMatchObject({ page: 2, fecha: '2026-10-31' });
  expect(screen.getByRole('main')).not.toHaveTextContent('MOTIVO-FICTICIO');
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it('navegar fuera del ámbito borra criterios para la siguiente entrada a agenda', async () => {
  const router = mount(); await selectFirst();
  await act(async () => router.navigate('/inicio')); await screen.findByRole('heading', { level: 1 });
  await act(async () => router.navigate('/agenda')); await screen.findByRole('heading', { name: 'Selecciona un médico para consultar su agenda' });
  expect(agendaCalls()).toHaveLength(1);
});
it('cambiar de Médico descarta filas anteriores y una respuesta pendiente fuera de orden', async () => {
  const pending = [];
  respond = config => config.url === '/agenda' ? new Promise(resolve => pending.push({ config, complete: () => resolve(defaultResponse(config)) })) : defaultResponse(config);
  mount(); const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' }));
  await waitFor(() => expect(pending).toHaveLength(1));
  await user.click(screen.getByText('Cambiar médico de la agenda', { selector: 'summary' }));
  await user.click(screen.getByRole('button', { name: 'Consultar agenda de Otro Profesional 0' }));
  await waitFor(() => expect(pending).toHaveLength(2)); expect(pending[0].config.signal.aborted).toBe(true);
  await act(async () => pending[1].complete()); await screen.findByRole('link', { name: 'Ver cita CIT-1' });
  await act(async () => pending[0].complete());
  expect(screen.getByRole('region', { name: 'Contexto de la agenda' })).toHaveTextContent('Otro Profesional 0');
});
it.each([403, 404, 419, 422, 500, undefined])('error %s de agenda no deja datos ni expone técnicos y conserva filtros', async status => {
  rol = 'Médico'; respond = config => Promise.reject(fail(config, status, { fecha: ['Selecciona una fecha válida.'] })); mount();
  await screen.findByRole('alert'); expect(screen.getByRole('main')).not.toHaveTextContent('SQL-PRIVADO');
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  if (status === 422) { expect(screen.getByLabelText('Fecha de atención')).toHaveAccessibleDescription(/Selecciona una fecha válida/); expect(screen.getByLabelText('Fecha de atención')).toHaveFocus(); }
});
it('401 de agenda invalida la sesión existente y retira navegación privada', async () => {
  rol = 'Médico'; respond = config => Promise.reject(fail(config, 401)); mount();
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it('429 no reconsulta automáticamente al vencer el límite y permite reintento explícito', async () => {
  rol = 'Médico'; respond = config => Promise.reject(fail(config, 429)); mount(); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Consultar agenda', exact: true })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeEnabled(), { timeout: 3000 });
  expect(agendaCalls()).toHaveLength(1); respond = defaultResponse;
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('link', { name: 'Ver cita CIT-1' }); expect(agendaCalls()).toHaveLength(2);
});
it('logout cancela agenda pendiente y no restaura su respuesta tardía', async () => {
  rol = 'Médico'; let complete, signal;
  respond = config => new Promise(resolve => { signal = config.signal; complete = () => resolve(defaultResponse(config)); });
  mount(); await waitFor(() => expect(complete).toBeTypeOf('function'));
  cerrarSesion.mockResolvedValue(undefined); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(signal.aborted).toBe(true);
  await act(async () => complete()); expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it('cambio de identidad/rol reinicia criterios y elimina las filas anteriores', async () => {
  mount(); await selectFirst();
  rol = 'Recepcionista'; consultarSesion.mockResolvedValue(sesion(rol, '2'));
  await act(async () => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Selecciona un médico para consultar su agenda' });
  expect(screen.queryByRole('table')).not.toBeInTheDocument(); expect(agendaCalls()).toHaveLength(1);
});
it('pagehide retira filas y selección, pageshow exige seleccionar de nuevo para Admin', async () => {
  mount(); await selectFirst(); await act(async () => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow')));
  await screen.findByRole('heading', { name: 'Selecciona un médico para consultar su agenda' }); expect(agendaCalls()).toHaveLength(1);
});
