import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { asistencia, atencionElegible, atencion, atendida, cita, detalle, sesion } from '../utils/asistenciaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter;
const declaracion = 'He comprobado que el Médico responsable realizó efectivamente la atención de esta cita.';
let rol, calls, body, responder;
const response = (config, d) => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {},
  data: { data: d ?? (config.url.endsWith('/asistencia') ? body : detalle(rol)) } });
const fail = (config, status, code) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { code, message: 'SQL-PRIVADO', details: { diagnostico: ['NO-SALIR'] } } } } : undefined);
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/citas/${cita.id_cita}/asistencia`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function preparar(user = userEvent.setup()) {
  await user.click(await screen.findByRole('button', { name: 'Preparar atención' })); return user;
}
async function revisar(user = userEvent.setup()) {
  await preparar(user); await user.click(screen.getByRole('checkbox', { name: declaracion }));
  await user.click(screen.getByRole('button', { name: 'Revisar atención' })); return user;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; body = atencionElegible(); calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol)); responder = config => response(config);
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Médico'].flatMap(role => ['Programada', 'Confirmada'].map(estado => [role, estado])))('%s declara y marca %s sin modificar clínica ni Médico responsable', async (role, estado) => {
  rol = role; body = atencionElegible(rol, estado); const original = body;
  responder = config => { if (config.method === 'patch') { body = atendida(rol); return response(config, atencion(original)); } return response(config); };
  mount(); const user = await preparar();
  expect(screen.getByRole('heading', { name: 'Preparación de atención' })).toHaveFocus();
  expect(screen.getByRole('checkbox', { name: declaracion })).not.toBeChecked();
  expect(screen.queryByRole('button', { name: 'Marcar como Atendida' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Revisar atención' })); expect(screen.getByRole('checkbox')).toHaveFocus();
  expect(screen.getByText(/Comprueba que la atención fue efectivamente realizada/)).toBeVisible();
  await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar atención' }));
  expect(screen.getByRole('heading', { name: `Revisar atención de ${cita.codigo_cita}` })).toHaveFocus();
  expect(screen.getByText(/Atendida es un estado final/)).toBeVisible(); expect(screen.getByText(/no sustituye al Médico responsable/)).toBeVisible();
  expect(screen.getByText(/no demuestran automáticamente la atención/)).toBeVisible(); expect(calls.every(c => c.method === 'get')).toBe(true);
  await user.click(screen.getByRole('button', { name: 'Marcar como Atendida' })); await screen.findByText(/Atención guardada/);
  await waitFor(() => expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Atendida'));
  const writes = calls.filter(c => c.method === 'patch'); expect(writes).toHaveLength(1);
  expect(writes[0].url).toBe(`/citas/${cita.id_cita}/atencion`);
  expect(JSON.parse(writes[0].data)).toEqual({ version_programacion_original: original.version_programacion,
    actualizada_en_original: original.actualizada_en, atencion_realizada: true });
  expect(screen.queryByRole('button', { name: /Preparar atención|Marcar como Atendida|Preparar inasistencia|Confirmar llegada/ })).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(/MOTIVO-FICTICIO|70000000|SQL-PRIVADO/);
  expect(calls.some(c => /consultas|antecedentes|laboratorio|recordatorios/.test(c.url))).toBe(false);
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['Recepcionista', 'Paciente'])('%s no prepara atención', async role => {
  rol = role; body = atencionElegible(rol); mount();
  await screen.findByRole(role === 'Paciente' ? 'heading' : 'region', { name: role === 'Paciente' ? 'Acceso no permitido' : 'Condiciones de asistencia' });
  expect(screen.queryByRole('button', { name: 'Preparar atención' })).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each([403, 404])('Médico sin vínculo/ajeno (%s) no accede ni prepara', async status => {
  rol = 'Médico'; responder = config => Promise.reject(fail(config, status)); mount();
  await screen.findByRole('alert'); expect(screen.queryByRole('button', { name: 'Preparar atención' })).not.toBeInTheDocument();
  expect(calls.every(c => c.method === 'get')).toBe(true); expect(screen.getByRole('main')).not.toHaveTextContent('SQL-PRIVADO');
});
it.each(['Atendida', 'Cancelada', 'Inasistencia'])('estado final %s no ofrece reapertura', async estado => {
  body = { ...atendida(), estado }; mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(screen.queryByRole('button', { name: 'Preparar atención' })).not.toBeInTheDocument();
});
it('atención anticipada según servidor no se habilita con reloj local', async () => {
  body = asistencia(); mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(screen.queryByRole('button', { name: 'Preparar atención' })).not.toBeInTheDocument();
});
it('volver, abandonar, cambiar de acción y actualizar eliminan declaración/revisión', async () => {
  body.acciones.registrar_inasistencia = true; body.impedimentos.registrar_inasistencia = [];
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Volver sin registrar' }));
  expect(screen.getByRole('checkbox')).not.toBeChecked(); await user.click(screen.getByRole('checkbox'));
  await user.click(screen.getByRole('button', { name: 'Revisar atención' })); await user.click(screen.getByRole('checkbox'));
  expect(screen.queryByRole('button', { name: 'Marcar como Atendida' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Abandonar atención' })); expect(screen.getByRole('button', { name: 'Preparar atención' })).toHaveFocus();
  await revisar(user); await user.click(screen.getByRole('button', { name: 'Preparar inasistencia' })); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await preparar(user); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await user.click(screen.getByRole('button', { name: 'Actualizar asistencia' })); await preparar(user);
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(['CITA_EDICION_OBSOLETA', 'CITA_TRANSICION_NO_PERMITIDA', 'CITA_ATENCION_ANTICIPADA'])('409 %s exige consultar/declarar/revisar de nuevo', async code => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 409, code)) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  const button = await screen.findByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' });
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.getByRole('main')).not.toHaveTextContent('SQL-PRIVADO');
  await user.click(button); await preparar(user); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each([403, 404, 419, 422])('PATCH %s no confirma éxito ni reenvía', async status => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, status)) : response(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  await screen.findByRole('alert'); expect(screen.queryByText(/Atención guardada/)).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(/SQL-PRIVADO|NO-SALIR/); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('401 limpia sesión y declaración', async () => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 401)) : response(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(['red', 'inválida', '500'])('resultado %s incierto bloquea acciones hasta GET explícito sin atribuir causalidad', async kind => {
  responder = config => config.method === 'patch' ? (kind === 'inválida' ? response(config, {}) : Promise.reject(fail(config, kind === '500' ? 500 : undefined))) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  await screen.findByText(/La atención podría haberse guardado/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  body = atendida(); responder = config => response(config); await user.click(screen.getByRole('button', { name: 'Consultar estado actual' }));
  await screen.findByText(/No demuestra qué petición concreta/); expect(screen.getByRole('region', { name: 'Resultado de atención' })).toHaveTextContent('Atendida');
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('429 retira revisión y espera sin autoenvío', async () => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 429)) : response(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  const button = await screen.findByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' }); expect(button).toBeDisabled();
  const n = calls.length; await waitFor(() => expect(button).toBeEnabled(), { timeout: 2500 }); expect(calls).toHaveLength(n);
});
it('éxito confirmado no se pierde al fallar refresco', async () => {
  let done = false; responder = config => { if (config.method === 'patch') { done = true; return response(config, atencion()); }
    return done ? Promise.reject(fail(config, 500)) : response(config); };
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Marcar como Atendida' }));
  await screen.findByText(/El guardado está confirmado/); expect(screen.getByText(/Atención guardada/)).toBeVisible();
});
it('pagehide/pageshow limpian borrador sin enviar', async () => {
  mount(); await revisar(); await act(async () => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); await act(async () => window.dispatchEvent(new Event('pageshow')));
  await preparar(); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
