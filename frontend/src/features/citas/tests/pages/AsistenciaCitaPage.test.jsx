import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro } from '../../../../constants/roles';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { asistencia, cita, confirmada, detalle, llegada, sesion } from '../utils/asistenciaFixtures';
import { pagina } from '../../../agenda/tests/utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter, path = `/citas/${cita.id_cita}/asistencia`;
const declaracion = 'He verificado presencialmente la presencia y la identidad del paciente.';
let rol, calls, body, responder;
const response = (config, d) => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {},
  data: { data: d ?? (config.url.endsWith('/asistencia') ? body : detalle(rol)) } });
const fail = (config, status, code) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { code, message: 'SQL-PRIVADO', details: { diagnostico: ['NO-SALIR'] } } } } : undefined);
function mount(entry = path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [entry] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function revisar(user = userEvent.setup()) {
  await user.click(await screen.findByRole('checkbox', { name: declaracion }));
  await user.click(screen.getByRole('button', { name: 'Revisar llegada' })); return user;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; body = asistencia(); calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol)); responder = config => response(config);
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista'])('%s lee identidades mínimas, zona, acciones por operación y declaración desmarcada', async role => {
  rol = role; body = asistencia(rol); mount();
  const region = await screen.findByRole('region', { name: `Programación actual: ${cita.codigo_cita}` });
  expect(region).toHaveTextContent('Paciente De prueba'); expect(region).toHaveTextContent('Profesional Ficticio');
  expect(region).toHaveTextContent('31/10/2026'); expect(region).toHaveTextContent('22:30');
  expect(screen.getByRole('checkbox', { name: declaracion })).not.toBeChecked();
  expect(screen.queryByRole('button', { name: 'Confirmar llegada' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Registrar inasistencia|Marcar como Atendida/ })).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent(/70000000|MOTIVO-FICTICIO|fecha_nacimiento/);
  expect(calls).toHaveLength(2); expect(calls.every(c => c.method === 'get' && c.sessionBound)).toBe(true);
  expect(document.title).toBe('Asistencia de la cita | Clínica Vida Salud');
  expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Asistencia y estados');
});
it('Médico lee propia asistencia sin llegada y prepara atención solo mediante elección explícita', async () => {
  rol = 'Médico'; body = asistencia(rol); body.acciones.marcar_atendida = true; body.impedimentos.marcar_atendida = [];
  mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: /Confirmar llegada|Marcar como Atendida/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Preparar atención' })).toBeVisible(); expect(calls).toHaveLength(2);
});
it('Paciente no accede a ruta de asistencia ni consume API', async () => {
  rol = 'Paciente'; mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['anonymous', 'restricted'])('%s no consulta y no obtiene formulario', async tipo => {
  if (tipo === 'anonymous') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  const router = mount(); await screen.findByRole('heading', { name: tipo === 'anonymous' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' });
  expect(calls).toHaveLength(0); if (tipo === 'anonymous') expect(destinoSeguro(router.state.location.state.from)).toBe(path);
});
it.each(['0', '01', 'invalido', '9223372036854775808'])('ID %s inválido no llama HTTP', async id => {
  mount(`/citas/${id}/asistencia`); await screen.findByRole('heading', { name: 'Cita no disponible' }); expect(calls).toHaveLength(0);
});
it.each(['Administrador', 'Recepcionista'])('%s exige declaración, revisión y confirmación antes de un PATCH cerrado', async role => {
  rol = role; body = asistencia(rol);
  responder = config => { if (config.method === 'patch') { body = confirmada(rol); return response(config, llegada(asistencia(rol))); } return response(config); };
  mount(); const user = userEvent.setup();
  await user.click(await screen.findByRole('button', { name: 'Revisar llegada' }));
  expect(screen.getByRole('checkbox')).toHaveFocus(); expect(screen.getByText(/Comprueba presencialmente la presencia/)).toBeVisible();
  expect(calls.every(c => c.method === 'get')).toBe(true);
  await revisar(user); expect(screen.getByRole('heading', { name: `Revisar llegada de ${cita.codigo_cita}` })).toHaveFocus();
  expect(calls).toHaveLength(2); await user.click(screen.getByRole('button', { name: 'Volver sin confirmar' }));
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(screen.queryByRole('button', { name: 'Confirmar llegada' })).not.toBeInTheDocument();
  await revisar(user); await user.click(screen.getByRole('button', { name: 'Confirmar llegada' }));
  await screen.findByText(/Llegada guardada/);
  await waitFor(() => expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Confirmada'));
  const writes = calls.filter(c => c.method === 'patch'); expect(writes).toHaveLength(1);
  expect(writes[0].url).toBe(`/citas/${cita.id_cita}/llegada`);
  expect(JSON.parse(writes[0].data)).toEqual({ version_programacion_original: 1, actualizada_en_original: asistencia().actualizada_en, presencia_verificada: true });
  expect(calls.some(c => c.url === csrfURL)).toBe(true);
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('estado %s consultable no ofrece llegada', async estado => {
  body = asistencia('Administrador', { estado }); body.acciones.confirmar_llegada = false; body.impedimentos.confirmar_llegada = ['CITA_TRANSICION_NO_PERMITIDA'];
  mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(['CITA_CON_ATENCION_DOCUMENTADA', 'CITA_LLEGADA_FUERA_DE_PLAZO'])('impedimento %s impide formulario sin simular una transición', async codigo => {
  body.acciones.confirmar_llegada = false; body.impedimentos.confirmar_llegada = [codigo];
  mount(); await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Programada');
});
it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('%s recibe enlace del detalle únicamente si corresponde', async role => {
  rol = role; body = asistencia(rol); mount(`/citas/${cita.id_cita}`); await screen.findByRole('region', { name: 'Paciente' });
  const link = screen.queryByRole('link', { name: 'Gestionar asistencia' });
  if (rol === 'Paciente') expect(link).not.toBeInTheDocument();
  else { await userEvent.click(link); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
    expect(screen.getByRole('link', { name: 'Volver al detalle de la cita' })).toHaveAttribute('href', `/citas/${cita.id_cita}`); }
});
it.each(['Administrador', 'Recepcionista'])('%s conserva retorno al detalle de historial', async role => {
  rol = role; body = asistencia(rol); mount(`/recepcion/historial-citas/${cita.id_cita}`);
  await userEvent.click(await screen.findByRole('link', { name: 'Gestionar asistencia' })); await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(screen.getByRole('link', { name: 'Volver al detalle de la cita' })).toHaveAttribute('href', `/recepcion/historial-citas/${cita.id_cita}`);
});
it('state arbitrario no genera retorno externo', async () => {
  mount({ pathname: path, state: { origen: 'https://ajeno.example.test', returnTo: '//ajeno.example.test', motivo: 'NO-SALIR' } });
  await screen.findByRole('region', { name: 'Condiciones de asistencia' });
  expect(screen.getByRole('link', { name: 'Volver al detalle de la cita' })).toHaveAttribute('href', `/citas/${cita.id_cita}`);
  expect(screen.getByRole('main')).not.toHaveTextContent('NO-SALIR');
});
it('Médico entra por la misma agenda sin selector ajeno y retorna con criterios', async () => {
  rol = 'Médico'; body = asistencia(rol);
  responder = config => config.url === '/agenda' ? { ...response(config), data: pagina([cita], {
    medico: cita.medico, filtros: { id_medico: cita.medico.id_medico, fecha: '2026-10-31', estado: null } }) } : response(config);
  const router = mount('/asistencia'); await screen.findByRole('heading', { name: 'Seleccionar cita para asistencia' });
  expect(screen.queryByRole('textbox', { name: /médico/i })).not.toBeInTheDocument();
  await userEvent.click(await screen.findByRole('link', { name: `Ver cita ${cita.codigo_cita}` }));
  await screen.findByRole('region', { name: 'Condiciones de asistencia' }); expect(router.state.location.pathname).toBe(path);
  await userEvent.click(screen.getByRole('link', { name: 'Volver a seleccionar una cita' }));
  await screen.findByRole('table'); expect(calls.filter(c => c.url === '/agenda')).toHaveLength(2);
  expect(calls.filter(c => c.url === '/agenda').every(c => !c.params.id_medico)).toBe(true);
});
it.each([403, 404, 422])('GET %s retira datos sin exponer mensajes técnicos', async status => {
  responder = config => Promise.reject(fail(config, status)); mount(); expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL-PRIVADO');
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Volver a consultar' })).not.toBeInTheDocument();
});
it.each([419, 500, undefined])('GET %s permite reconsulta explícita sin PATCH', async status => {
  responder = config => Promise.reject(fail(config, status)); mount(); await screen.findByRole('alert'); responder = config => response(config);
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' })); await screen.findByRole('checkbox'); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('GET 401 invalida sesión y limpia asistencia', async () => {
  responder = config => Promise.reject(fail(config, 401)); mount(); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it('actualizar asistencia invalida declaración y revisión sin escribir', async () => {
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Actualizar asistencia' }));
  expect(await screen.findByRole('checkbox')).not.toBeChecked(); expect(screen.queryByRole('button', { name: 'Confirmar llegada' })).not.toBeInTheDocument(); expect(calls).toHaveLength(4);
});
it('409 retira revisión/programación vieja y exige consulta antes de otro envío', async () => {
  responder = config => config.method === 'patch' ? Promise.reject(fail(config, 409, 'CITA_EDICION_OBSOLETA')) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Confirmar llegada' }));
  await screen.findByText(/La cita cambió/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Consultar asistencia y comenzar una nueva revisión' }));
  expect(await screen.findByRole('checkbox')).not.toBeChecked(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(['red', 'respuesta inválida'])('%s tras guardar: solo GET explícito recupera sin replay ni causalidad inequívoca', async tipo => {
  responder = config => config.method === 'patch' ? (tipo === 'red' ? Promise.reject(fail(config)) : response(config, {})) : response(config);
  mount(); const user = await revisar(); await user.click(screen.getByRole('button', { name: 'Confirmar llegada' }));
  await screen.findByText(/La llegada podría haberse guardado/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  body = confirmada(rol); responder = config => response(config);
  await user.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/No demuestra qué petición concreta/);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it('éxito confirmado no se pierde si falla GET posterior', async () => {
  let guardado = false;
  responder = config => { if (config.method === 'patch') { guardado = true; return response(config, llegada()); }
    return guardado ? Promise.reject(fail(config, 500)) : response(config); };
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Confirmar llegada' }));
  await screen.findByText(/El guardado está confirmado/); expect(screen.getByText(/Llegada guardada/)).toBeVisible();
});
it('pagehide limpia declaración y pageshow no restaura revisión ni envía PATCH', async () => {
  mount(); await revisar(); await act(async () => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow'))); expect(await screen.findByRole('checkbox')).not.toBeChecked();
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('logout y respuesta tardía no restauran datos', async () => {
  const pending = []; responder = config => new Promise(resolve => pending.push({ config, done: () => resolve(response(config)) }));
  mount(); await waitFor(() => expect(pending).toHaveLength(2)); cerrarSesion.mockResolvedValue(undefined);
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(pending.every(p => p.config.signal.aborted)).toBe(true); await act(async () => pending.forEach(p => p.done()));
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(['/asistencia?token=privado', '/citas/1/asistencia#privado', '/citas/01/asistencia', '//ajeno.example.test'])('destino inseguro %s no se restaura', value => {
  expect(destinoSeguro(value)).toBe('/inicio');
});
