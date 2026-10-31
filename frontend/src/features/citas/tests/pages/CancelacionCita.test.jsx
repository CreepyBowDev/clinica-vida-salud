import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { cancelada, cita, detalle, gestion, sesion } from '../utils/gestionFixtures';
import { pagina } from '../../../agenda/tests/utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter;
let rol, calls, original, saved, respond, failRefresh;
const privado = 'MOTIVO-CANCELACION-PRIVADO-FICTICIO';
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
const failure = (config, status, code) => new AxiosError('SQL-PRIVADO', undefined, config, null, status ? {
  status, headers: { 'retry-after': '1' }, data: { error: { code, message: 'SQL-PRIVADO', details: { motivo: ['Revisa el motivo.'], password: ['NO-SALIR'] } } } } : undefined);
function server(config) {
  if (config.url === csrfURL) return ok(config, {}, 204);
  if (config.method === 'patch') { saved = cancelada(original); return ok(config, { data: saved }); }
  if (config.url.endsWith('/gestion')) {
    if (failRefresh && saved) throw failure(config, 500);
    return ok(config, { data: saved ? { ...original, ...saved, acciones: { reprogramar: false, cancelar: false }, impedimentos: ['CITA_GESTION_NO_PERMITIDA'] } : original });
  }
  if (config.url.endsWith('/disponibilidad-reprogramacion')) throw failure(config, 409, 'CITA_TURNO_NO_DISPONIBLE');
  return ok(config, { data: detalle(rol) });
}
function mount(entry = `/citas/${cita.id_cita}/gestion`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [entry] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function preparar() {
  await userEvent.click(await screen.findByRole('button', { name: 'Preparar cancelación' }));
  return screen.getByLabelText('Motivo de cancelación (opcional)');
}
async function revisar(motivo = '') {
  const input = await preparar(); if (motivo) fireEvent.change(input, { target: { value: motivo } });
  await userEvent.click(screen.getByRole('button', { name: 'Revisar cancelación' }));
  return screen.getByRole('button', { name: 'Confirmar cancelación' });
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; calls = []; original = gestion(); saved = null; failRefresh = false;
  consultarSesion.mockImplementation(async () => sesion(rol)); respond = server;
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista'])('%s revisa motivo privado y cancela la misma cita sin cambiar programación/versión', async role => {
  rol = role; original.estado = 'Confirmada'; mount(); const confirmar = await revisar(`  ${privado}  `);
  const revision = screen.getByRole('region', { name: `Confirmar cancelación de ${cita.codigo_cita}` });
  expect(revision).toHaveTextContent('Paciente De prueba'); expect(revision).toHaveTextContent('Profesional Ficticio');
  expect(revision).toHaveTextContent('Confirmada'); expect(revision).toHaveTextContent('15 minutos'); expect(revision).toHaveTextContent('Sala conservada ficticia');
  expect(revision).toHaveTextContent(privado); expect(screen.getByRole('heading', { name: `Confirmar cancelación de ${cita.codigo_cita}` })).toHaveFocus();
  expect(calls.every(c => c.method === 'get')).toBe(true); await userEvent.click(confirmar);
  await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  await waitFor(() => expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Cancelada'));
  const main = screen.getByRole('main'); expect(main).not.toHaveTextContent(privado); expect(main).not.toHaveTextContent('MOTIVO-FICTICIO');
  const patch = calls.find(c => c.method === 'patch'); expect(patch.url).toBe(`/citas/${cita.id_cita}/cancelacion`);
  expect(JSON.parse(patch.data)).toEqual({ version_programacion_original: 1, actualizada_en_original: original.actualizada_en, motivo: privado });
  expect(saved.inicio_at).toBe(original.inicio_at); expect(saved.version_programacion).toBe(1);
  expect(screen.getByRole('heading', { name: 'Resultado de cancelación' })).toHaveFocus();
  expect(screen.queryByRole('button', { name: 'Preparar cancelación' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(calls.some(c => /disponibilidad|reservas/.test(c.url))).toBe(false);
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['', '   '])('motivo %j es opcional: revisión informa Sin motivo y guarda null', async motivo => {
  mount(); const confirmar = await revisar(motivo); expect(screen.getByRole('region', { name: `Confirmar cancelación de ${cita.codigo_cita}` })).toHaveTextContent('Sin motivo');
  await userEvent.click(confirmar); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  expect(JSON.parse(calls.find(c => c.method === 'patch').data).motivo).toBeNull();
});
it.each(['Administrador', 'Recepcionista'])('%s vuelve a detalle e historial con estado Cancelada sin perder registro ni programación', async role => {
  rol = role;
  respond = config => {
    if (config.url === '/pacientes/31/citas') return ok(config, pagina([{ ...cita, ...(saved ? { estado: saved.estado } : {}) }], {
      paciente: cita.paciente, filtros: { periodo: config.params.periodo ?? 'todas', estado: config.params.estado ?? null } }));
    if (config.url === `/citas/${cita.id_cita}`) return ok(config, { data: { ...detalle(rol), ...(saved ? { estado: saved.estado } : {}) } });
    return server(config);
  };
  const router = mount('/recepcion/pacientes/31/citas');
  await userEvent.click(await screen.findByRole('link', { name: `Ver cita ${cita.codigo_cita}` }));
  await userEvent.click(await screen.findByRole('link', { name: 'Gestionar cita' }));
  await userEvent.click(await revisar(privado)); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  await userEvent.click(screen.getByRole('link', { name: 'Volver al detalle de la cita' }));
  expect(await screen.findByRole('region', { name: `Cita ${cita.codigo_cita}` })).toHaveTextContent('Cancelada');
  await userEvent.click(screen.getByRole('link', { name: 'Volver al historial del paciente' }));
  const table = await screen.findByRole('table'); expect(table).toHaveTextContent('Cancelada'); expect(table).toHaveTextContent('Sala conservada ficticia');
  expect(within(table).getByRole('link', { name: `Ver cita ${cita.codigo_cita}` })).toBeInTheDocument();
  expect(router.state.location.pathname).toBe('/recepcion/pacientes/31/citas'); expect(calls.filter(c => c.url === '/pacientes/31/citas')).toHaveLength(2);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
});
it('Enter sobre el motivo solo prepara revisión, la confirmación destructiva es una acción posterior por teclado', async () => {
  mount(); const user = userEvent.setup(); const input = await preparar();
  await user.click(input); await user.type(input, privado); await user.keyboard('{Enter}');
  await screen.findByRole('button', { name: 'Confirmar cancelación' }); expect(calls.every(c => c.method === 'get')).toBe(true);
  expect(screen.getByRole('heading', { name: `Confirmar cancelación de ${cita.codigo_cita}` })).toHaveFocus();
  await user.tab(); expect(screen.getByRole('button', { name: 'Confirmar cancelación' })).toHaveFocus(); await user.keyboard('{Enter}');
  await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
});
it('editar motivo retira confirmación y exige revisar nuevamente', async () => {
  mount(); await revisar(privado); fireEvent.change(screen.getByLabelText('Motivo de cancelación (opcional)'), { target: { value: 'Otro motivo' } });
  expect(screen.queryByRole('button', { name: 'Confirmar cancelación' })).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Revisar cancelación' })); expect(screen.getByRole('region', { name: `Confirmar cancelación de ${cita.codigo_cita}` })).toHaveTextContent('Otro motivo');
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('501 caracteres no habilitan revisión y enfocan campo, sin peticiones nuevas', async () => {
  mount(); const input = await preparar(); fireEvent.change(input, { target: { value: 'x'.repeat(501) } });
  await userEvent.click(screen.getByRole('button', { name: 'Revisar cancelación' })); await screen.findByText('Introduce hasta 500 caracteres, sin saltos de línea ni caracteres de control.');
  expect(input).toHaveFocus(); expect(input).toHaveAttribute('aria-invalid', 'true'); expect(calls).toHaveLength(2);
  expect(screen.queryByRole('button', { name: 'Confirmar cancelación' })).not.toBeInTheDocument();
});
it.each(['Volver sin cancelar', 'Abandonar cancelación'])('%s limpia motivo y no hace PATCH', async label => {
  mount(); await revisar(privado); await userEvent.click(screen.getByRole('button', { name: label }));
  expect(screen.queryByRole('button', { name: 'Confirmar cancelación' })).not.toBeInTheDocument();
  const input = await preparar(); expect(input).toHaveValue(''); expect(calls).toHaveLength(2); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
});
it('actualizar gestión invalida borrador de cancelación y exige revisión nueva', async () => {
  mount(); await revisar(privado); await userEvent.click(screen.getByRole('button', { name: 'Actualizar gestión' }));
  await screen.findByRole('button', { name: 'Preparar cancelación' }); expect(await preparar()).toHaveValue(''); expect(calls).toHaveLength(4);
});
it('fallo de disponibilidad por recursos inactivos no impide cancelar, sin volver a consultarlos', async () => {
  mount(); await screen.findByLabelText('Nueva fecha de atención'); fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByRole('alert');
  await userEvent.click(await revisar()); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  expect(calls.filter(c => c.url.endsWith('/disponibilidad-reprogramacion'))).toHaveLength(1); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(['Atendida', 'Cancelada', 'Inasistencia'])('estado %s no permite formulario ni reapertura desde UI', async estado => {
  original = { ...original, estado, acciones: { reprogramar: false, cancelar: false }, impedimentos: ['CITA_GESTION_NO_PERMITIDA'] };
  mount(); await screen.findByRole('region', { name: 'Condiciones de gestión' });
  expect(screen.queryByRole('button', { name: 'Preparar cancelación' })).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('después de cancelar una nueva revisión conserva estado final sin reabrir la cita', async () => {
  mount(); await userEvent.click(await revisar()); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar gestión y comenzar una nueva revisión' }));
  await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(screen.queryByRole('button', { name: 'Preparar cancelación' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('doble clic y acción contraria no pueden enviar otra escritura mientras se cancela', async () => {
  let complete; respond = config => config.method === 'patch' ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.dblClick(await revisar(privado)); await screen.findByText('Guardando cancelación…');
  expect(screen.queryByRole('button', { name: 'Preparar cancelación' })).not.toBeInTheDocument(); expect(screen.queryByLabelText('Nueva fecha de atención')).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  await act(async () => complete()); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
});
it.each(['CITA_EDICION_OBSOLETA', 'CITA_GESTION_NO_PERMITIDA', 'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_CON_ATENCION_DOCUMENTADA'])('409 %s exige reconsulta y revisión explícita, sin adoptar originales', async code => {
  respond = config => { if (config.method === 'patch') throw failure(config, 409, code); return server(config); };
  mount(); await userEvent.click(await revisar(privado)); await screen.findByRole('alert'); expect(screen.getByRole('alert')).not.toHaveTextContent('SQL-PRIVADO');
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(privado); expect(screen.queryByRole('button', { name: 'Confirmar cancelación' })).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Consultar gestión y comenzar una nueva revisión' }));
  expect(await preparar()).toHaveValue(''); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each([403, 404, 419, 422, 429])('rechazo conocido %s retira motivo/revisión, no reenvía ni publica éxito', async status => {
  respond = config => { if (config.method === 'patch') throw failure(config, status); return server(config); };
  mount(); await userEvent.click(await revisar(privado)); await screen.findByRole('alert');
  expect(screen.getByRole('main')).not.toHaveTextContent(/SQL-PRIVADO|NO-SALIR|MOTIVO-CANCELACION-PRIVADO/);
  expect(screen.queryByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.')).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.queryByRole('button', { name: 'Consultar estado actual' })).not.toBeInTheDocument();
  if (status === 422) expect(screen.getByRole('alert')).toHaveTextContent('Revisa el motivo.');
  if (status === 419) expect(screen.getByRole('alert')).toHaveTextContent('No se reenviará la cancelación automáticamente');
  if (status === 429) expect(screen.getByRole('button', { name: 'Consultar gestión y comenzar una nueva revisión' })).toBeDisabled();
});
it.each(['red', '500', '200-invalido'])('%s después de guardar exige lectura de estado, no replay ni afirmación de causalidad', async tipo => {
  respond = config => {
    if (config.method === 'patch') { server(config); if (tipo === '200-invalido') return ok(config, { data: {} }); throw failure(config, tipo === '500' ? 500 : undefined); }
    return server(config);
  };
  mount(); await userEvent.click(await revisar(privado)); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Nueva fecha de atención')).not.toBeInTheDocument(); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/Estado actual consultado. No demuestra/);
  expect(screen.getByRole('region', { name: 'Resultado de cancelación' })).toHaveTextContent('Cancelada'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  expect(screen.queryByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.')).not.toBeInTheDocument();
});
it('CSRF fallido antes de enviar no hace PATCH y no produce incertidumbre de cancelación', async () => {
  respond = config => { if (config.url === csrfURL) throw failure(config); return server(config); };
  mount(); await userEvent.click(await revisar(privado)); await screen.findByText(/No se envió la cancelación/);
  expect(calls.some(c => c.method === 'patch')).toBe(false); expect(screen.queryByRole('button', { name: 'Consultar estado actual' })).not.toBeInTheDocument();
});
it('guardado confirmado seguido de GET fallido no pierde el éxito ni conserva motivo', async () => {
  failRefresh = true; mount(); await userEvent.click(await revisar(privado)); await screen.findByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.');
  await screen.findByText('El guardado está confirmado; falta actualizar la consulta de gestión.'); expect(screen.getByRole('main')).not.toHaveTextContent(privado);
});
it.each(['Médico', 'Paciente'])('%s no recibe acceso ni escribe cancelación por URL directa', async role => {
  rol = role; mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it('401 al confirmar retira datos y dirige al login, sin reenvío', async () => {
  respond = config => { if (config.method === 'patch') throw failure(config, 401); return server(config); };
  mount(); await userEvent.click(await revisar(privado)); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText(privado)).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('pagehide durante borrador limpia motivo y revisión al restaurar', async () => {
  mount(); await revisar(privado); await act(async () => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByDisplayValue(privado)).not.toBeInTheDocument(); expect(screen.queryByText(privado)).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow'))); expect(await preparar()).toHaveValue(''); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('logout durante cancelación pendiente descarta respuesta tardía y motivo', async () => {
  let complete; respond = config => config.method === 'patch' ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); await userEvent.click(await revisar(privado)); await screen.findByText('Guardando cancelación…'); cerrarSesion.mockResolvedValue(undefined);
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  // El guardado pendiente activa el blocker existente; salir no cancela el PATCH en el servidor.
  const salir = screen.queryByRole('button', { name: 'Salir sin conservar la comprobación' }); if (salir) await userEvent.click(salir);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); await act(async () => complete());
  expect(screen.queryByText(privado)).not.toBeInTheDocument(); expect(screen.queryByText('Cancelación guardada. La cita quedó Cancelada y conserva su historial.')).not.toBeInTheDocument();
});
