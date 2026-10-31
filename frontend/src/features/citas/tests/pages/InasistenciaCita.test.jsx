import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { ausenciaElegible, ausente, cita, detalle, inasistencia, sesion } from '../utils/asistenciaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter, path = `/citas/${cita.id_cita}/asistencia`;
const declaracion = 'He comprobado que el paciente no se presentó a esta cita.';
let rol, calls, body, responder;
const response = (config, d) => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {},
  data: { data: d ?? (config.url.endsWith('/asistencia') ? body : detalle(rol)) } });
const fail = (config, status, code) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { code, message: 'SQL-PRIVADO', details: { diagnostico: ['NO-SALIR'] } } } } : undefined);
function mount(entry = path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [entry] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function preparar(user = userEvent.setup()) {
  await user.click(await screen.findByRole('button', { name: 'Preparar inasistencia' })); return user;
}
async function revisar(user = userEvent.setup()) {
  await preparar(user); await user.click(screen.getByRole('checkbox', { name: declaracion }));
  await user.click(screen.getByRole('button', { name: 'Revisar inasistencia' })); return user;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; body = ausenciaElegible(); calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol)); responder = config => response(config);
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista'])('%s prepara, declara, revisa y confirma una ausencia sin afirmar éxito prematuro', async role => {
  rol = role; body = ausenciaElegible(rol);
  responder = config => { if (config.method === 'patch') { body = ausente(rol); return response(config, inasistencia()); } return response(config); };
  mount(); const user = await preparar();
  expect(screen.getByRole('heading', { name: 'Preparación de inasistencia' })).toHaveFocus();
  expect(screen.getByRole('checkbox', { name: declaracion })).not.toBeChecked();
  expect(screen.queryByRole('button', { name: 'Registrar inasistencia' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Revisar inasistencia' })); expect(screen.getByRole('checkbox')).toHaveFocus();
  expect(screen.getByText(/Comprueba que el paciente no se presentó/)).toBeVisible(); expect(calls.every(c => c.method === 'get')).toBe(true);
  await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar inasistencia' }));
  expect(screen.getByRole('heading', { name: `Revisar inasistencia de ${cita.codigo_cita}` })).toHaveFocus();
  expect(screen.getByText(/estado final sin reapertura ordinaria/)).toBeVisible();
  expect(screen.getByText(/se retiran los avisos propios vigentes/)).toBeVisible();
  expect(screen.getByText(/no se deduce automáticamente/)).toBeVisible();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByText(/Inasistencia guardada/);
  await waitFor(() => expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Inasistencia'));
  const writes = calls.filter(c => c.method === 'patch'); expect(writes).toHaveLength(1);
  expect(writes[0].url).toBe(`/citas/${cita.id_cita}/inasistencia`);
  expect(JSON.parse(writes[0].data)).toEqual({ version_programacion_original: 1, actualizada_en_original: ausenciaElegible().actualizada_en, ausencia_verificada: true });
  expect(screen.queryByRole('button', { name: /Preparar inasistencia|Registrar inasistencia|Confirmar llegada|Marcar como Atendida/ })).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(/MOTIVO-FICTICIO|70000000|SQL-PRIVADO/);
  expect(localStorage.length + sessionStorage.length).toBe(0);
  expect(calls.some(c => /recordatorios|consultas|laboratorio/.test(c.url))).toBe(false);
});
it.each(['Médico', 'Paciente'])('%s no obtiene registro de ausencia ni lo provoca al navegar', async role => {
  rol = role; body = ausenciaElegible(rol); mount();
  await screen.findByRole(role === 'Paciente' ? 'heading' : 'region', { name: role === 'Paciente' ? 'Acceso no permitido' : 'Condiciones de asistencia' });
  expect(screen.queryByRole('button', { name: /Preparar inasistencia|Registrar inasistencia/ })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method !== 'get')).toHaveLength(0); if (role === 'Paciente') expect(calls).toHaveLength(0);
});
it.each(['anonymous', 'restricted'])('%s no lee ni registra ausencia', async kind => {
  if (kind === 'anonymous') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  mount(); await screen.findByRole('heading', { name: kind === 'anonymous' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' }); expect(calls).toHaveLength(0);
});
it.each(['Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('estado %s no ofrece ausencia ni reapertura', async estado => {
  body.estado = estado; body.acciones.registrar_inasistencia = false; body.impedimentos.registrar_inasistencia = ['CITA_TRANSICION_NO_PERMITIDA'];
  mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(screen.queryByRole('button', { name: 'Preparar inasistencia' })).not.toBeInTheDocument();
});
it.each(['CITA_INASISTENCIA_ANTICIPADA', 'CITA_CON_ATENCION_DOCUMENTADA'])('impedimento del servidor %s no se reemplaza por reloj local', async code => {
  body.acciones.registrar_inasistencia = false; body.impedimentos.registrar_inasistencia = [code];
  mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(screen.queryByRole('button', { name: 'Preparar inasistencia' })).not.toBeInTheDocument();
});
it('volver, cambiar declaración, abandonar y reconsultar limpian revisión sin escritura', async () => {
  mount(); const user = await revisar();
  await user.click(screen.getByRole('button', { name: 'Volver sin registrar' })); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar inasistencia' }));
  await user.click(screen.getByRole('checkbox')); expect(screen.queryByRole('button', { name: 'Registrar inasistencia' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Abandonar inasistencia' })); expect(screen.getByRole('button', { name: 'Preparar inasistencia' })).toHaveFocus();
  await preparar(user); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar inasistencia' }));
  await user.click(screen.getByRole('button', { name: 'Actualizar asistencia' }));
  await screen.findByRole('button', { name: 'Preparar inasistencia' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(['CITA_EDICION_OBSOLETA', 'CITA_TRANSICION_NO_PERMITIDA', 'CITA_INASISTENCIA_ANTICIPADA', 'CITA_CON_ATENCION_DOCUMENTADA'])('409 %s retira revisión y obliga a consultar antes de decidir', async code => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 409, code)) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByRole('region', { name: 'Resultado de inasistencia' });
  await waitFor(() => expect(screen.getByRole('region', { name: 'Resultado de inasistencia' })).not.toHaveAttribute('aria-busy', 'true'));
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent('SQL-PRIVADO');
  await user.click(screen.getByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' }));
  await preparar(user); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each([403, 404, 419, 422])('PATCH %s no anuncia éxito ni reenvía', async status => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, status)) : response(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByRole('alert'); expect(screen.queryByText(/Inasistencia guardada/)).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(/SQL-PRIVADO|NO-SALIR/); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('PATCH 401 limpia sesión y flujo de ausencia', async () => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 401)) : response(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(['red', 'inválida', '500'])('respuesta %s después del envío se recupera solo con GET, no replay', async kind => {
  responder = config => config.method === 'patch' ? (kind === 'inválida' ? response(config, {}) : Promise.reject(fail(config, kind === '500' ? 500 : undefined))) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByText(/La inasistencia podría haberse guardado/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  body = ausente(rol); responder = config => response(config);
  await user.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/No demuestra qué petición concreta/);
  expect(screen.getByRole('region', { name: 'Resultado de inasistencia' })).toHaveTextContent('Inasistencia');
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('429 retira declaración y no consulta ni reenvía al vencer', async () => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 429)) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  const button = await screen.findByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' }); expect(button).toBeDisabled();
  const n = calls.length; await waitFor(() => expect(button).toBeEnabled(), { timeout: 2500 }); expect(calls).toHaveLength(n);
});
it('200 confirmado se conserva aunque falle refresco', async () => {
  let done = false;
  responder = config => { if (config.method === 'patch') { done = true; return response(config, inasistencia()); }
    return done ? Promise.reject(fail(config, 500)) : response(config); };
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Registrar inasistencia' }));
  await screen.findByText(/El guardado está confirmado/); expect(screen.getByText(/Inasistencia guardada/)).toBeVisible();
});
it('pagehide/pageshow retiran borrador de ausencia sin enviarlo', async () => {
  mount(); await revisar(); await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow'))); await preparar(); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
