import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro } from '../../../../constants/roles';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { cerrarSesion, consultarSesion } from '../../../auth/services/auth.service';
import { cita, detalle, gestion, sesion } from '../utils/gestionFixtures';
import { pagina } from '../../../agenda/tests/utils/agendaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, respond;
const path = `/citas/${cita.id_cita}/gestion`;
const response = (config, data) => ({ config, status: 200, headers: {}, data: { data: data ?? (config.url.endsWith('/gestion') ? gestion() : detalle(rol)) } });
const fail = (config, status) => new AxiosError('SQL-PRIVADO', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { message: 'SQL-PRIVADO' } } } : undefined);
function mount(entry = path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [entry] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol)); respond = config => response(config);
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });

it.each(['Administrador', 'Recepcionista'])('%s consulta programación y acciones básicas sin confirmación de escritura prematura', async role => {
  rol = role; mount();
  const region = await screen.findByRole('region', { name: `Programación actual: ${cita.codigo_cita}` });
  expect(region).toHaveTextContent('31/10/2026'); expect(region).toHaveTextContent('22:30');
  expect(region).toHaveTextContent('Paciente De prueba'); expect(region).toHaveTextContent('Profesional Ficticio');
  const main = screen.getByRole('main'); expect(main).not.toHaveTextContent('70000000'); expect(main).not.toHaveTextContent('MOTIVO-FICTICIO');
  expect(main.querySelector('.gestion-cita-page')).toBeInTheDocument();
  expect(main).toHaveTextContent('No hay cambios de gestión registrados'); expect(main).toHaveTextContent('sin impedimentos básicos');
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancelar cita' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Actualizar gestión' })).toBeEnabled();
  expect(document.title).toBe('Gestionar cita | Clínica Vida Salud'); expect(main).toHaveFocus();
  expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent('Reprogramación y cancelación');
  expect(screen.getByRole('navigation', { name: 'Ubicación actual' })).toHaveTextContent(role === 'Administrador' ? 'Administración' : 'Recepción');
  expect(calls).toHaveLength(2); expect(calls.every(c => c.method === 'get' && c.sessionBound)).toBe(true);
  expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0);
});
it.each(['Médico', 'Paciente'])('%s no consulta gestión ni dispone de acceso en el detalle', async role => {
  rol = role; const router = mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
  await act(async () => router.navigate(`/citas/${cita.id_cita}`)); await screen.findByRole('region', { name: 'Paciente' });
  expect(screen.queryByRole('link', { name: 'Gestionar cita' })).not.toBeInTheDocument();
});
it.each(['Médico', 'Paciente'])('%s tampoco puede abrir la selección administrativa de gestión', async role => {
  rol = role; mount('/recepcion/citas/gestion'); await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(calls).toHaveLength(0); expect(screen.queryByRole('link', { name: 'Gestionar citas' })).not.toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista'])('%s: sidebar → ficha inactiva sin CI/cuenta → historial → detalle → gestión → retorno, solo GET', async role => {
  rol = role;
  const patient = { ...cita.paciente, documento_identidad: null, activo: false, cuenta_vinculada: false, fecha_nacimiento: '1990-01-01',
    sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, contacto_emergencia: null, recordatorios_habilitados: null,
    actualizado_en: '2026-10-26T12:00:00.000000Z' };
  respond = config => {
    if (config.url === '/pacientes') return { ...response(config), data: pagina([patient]) };
    if (config.url === `/pacientes/${patient.id_paciente}`) return response(config, patient);
    if (config.url === `/pacientes/${patient.id_paciente}/citas`) return { ...response(config), data: pagina([cita], {
      paciente: cita.paciente, filtros: { periodo: config.params.periodo ?? 'todas', estado: config.params.estado ?? null } }) };
    return response(config);
  };
  const router = mount('/inicio'); const user = userEvent.setup();
  await user.click(await screen.findByRole('button', { name: 'Reprogramación y cancelación' }));
  await user.click(within(screen.getByRole('navigation', { name: 'Navegación principal' })).getByRole('link', { name: 'Gestionar citas' }));
  await screen.findByRole('heading', { name: 'Seleccionar paciente para gestionar una cita' });
  expect(screen.queryByRole('link', { name: 'Registrar paciente' })).not.toBeInTheDocument();
  await user.click(await screen.findByRole('link', { name: 'Ver ficha de Paciente De prueba' }));
  await screen.findByRole('heading', { name: 'Ficha administrativa' });
  await user.click(within(screen.getByRole('main')).getByRole('link', { name: 'Consultar historial de citas' }));
  await user.click(await screen.findByRole('link', { name: `Ver cita ${cita.codigo_cita}` }));
  await user.click(await screen.findByRole('link', { name: 'Gestionar cita' }));
  await screen.findByRole('region', { name: 'Condiciones de gestión' });
  expect(router.state.location.pathname).toBe(path);
  await user.click(screen.getByRole('link', { name: 'Volver al detalle de la cita' }));
  await user.click(await screen.findByRole('link', { name: 'Volver al historial del paciente' }));
  await screen.findByRole('table'); expect(router.state.location.pathname).toBe('/recepcion/pacientes/31/citas');
  expect(calls.filter(c => c.url === '/pacientes/31/citas')).toHaveLength(2);
  expect(calls.every(c => c.method === 'get')).toBe(true);
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['Administrador', 'Recepcionista'])('%s entra desde detalle de historial y vuelve al mismo contexto', async role => {
  rol = role; const router = mount(`/recepcion/historial-citas/${cita.id_cita}`);
  await userEvent.click(await screen.findByRole('link', { name: 'Gestionar cita' }));
  await screen.findByRole('region', { name: `Programación actual: ${cita.codigo_cita}` }); expect(router.state.location.pathname).toBe(path);
  await userEvent.click(screen.getByRole('link', { name: 'Volver al detalle de la cita' }));
  await screen.findByRole('region', { name: 'Paciente' }); expect(router.state.location.pathname).toBe(`/recepcion/historial-citas/${cita.id_cita}`);
  expect(calls.filter(c => c.url.endsWith('/gestion'))).toHaveLength(1); expect(calls.filter(c => !c.url.endsWith('/gestion'))).toHaveLength(3);
});
it('entrada directa o state manipulado solo retorna a un detalle interno construido con ID válido', async () => {
  mount({ pathname: path, state: { from: 'https://ajeno.example.test', origen: '/mis-citas', motivo: 'NO-SALIR' } });
  await screen.findByRole('region', { name: 'Condiciones de gestión' });
  expect(screen.getByRole('link', { name: 'Volver al detalle de la cita' })).toHaveAttribute('href', `/citas/${cita.id_cita}`);
  expect(screen.getByRole('main')).not.toHaveTextContent('NO-SALIR');
});
it.each(['0', '01', 'invalido', '9223372036854775808'])('ID %s inválido no consulta HTTP', async id => {
  mount(`/citas/${id}/gestion`); await screen.findByRole('heading', { name: 'Cita no disponible' }); expect(calls).toHaveLength(0);
});
it.each(['anonymous', 'restricted'])('%s no consulta datos de gestión y conserva solo destino seguro cuando corresponde', async kind => {
  if (kind === 'anonymous') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  const router = mount(); await screen.findByRole('heading', { name: kind === 'anonymous' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' });
  expect(calls).toHaveLength(0); if (kind === 'anonymous') { expect(router.state.location.state.from).toBe(path); expect(destinoSeguro(path)).toBe(path); }
});
it.each(['anonymous', 'restricted'])('%s no abre selección de gestión', async kind => {
  if (kind === 'anonymous') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  const router = mount('/recepcion/citas/gestion');
  await screen.findByRole('heading', { name: kind === 'anonymous' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' });
  expect(calls).toHaveLength(0);
  if (kind === 'anonymous') expect(destinoSeguro(router.state.location.state.from)).toBe('/recepcion/citas/gestion');
});
it.each([403, 404, 422])('%s oculta datos privados y no habilita reintento improcedente', async status => {
  respond = config => Promise.reject(fail(config, status)); mount();
  expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL-PRIVADO');
  expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Volver a consultar' })).not.toBeInTheDocument();
});
it.each([419, 500, undefined])('%s ofrece solo reconsulta explícita, nunca escrituras', async status => {
  respond = config => Promise.reject(fail(config, status)); mount(); expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL-PRIVADO');
  respond = config => response(config); await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' }));
  await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('401 invalida sesión y retira gestión', async () => {
  respond = config => Promise.reject(fail(config, 401)); mount(); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
});
it.each(['CITA_GESTION_NO_PERMITIDA', 'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_CON_ATENCION_DOCUMENTADA'])('muestra impedimento seguro %s y no deduce un nuevo estado', async code => {
  respond = config => response(config, config.url.endsWith('/gestion') ? { ...gestion(), acciones: { reprogramar: false, cancelar: false }, impedimentos: [code] } : detalle(rol));
  mount(); const region = await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(region).toHaveTextContent('no permitida');
  expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Programada');
});
it('último cambio diferencia ejecutor y Médico y nunca publica motivo ni operación desconocida en bruto', async () => {
  respond = config => response(config, config.url.endsWith('/gestion') ? { ...gestion(), motivo: 'NO-SALIR', ultimo_cambio: {
    id_cambio_cita: '2', id_actor: '99', operacion: 'SQL-PRIVADO', realizado_en: gestion().actualizada_en, motivo: 'NO-SALIR' } } : detalle(rol));
  mount(); const region = await screen.findByRole('region', { name: 'Último cambio registrado' });
  expect(region).toHaveTextContent('Cambio administrativo'); expect(region).toHaveTextContent('99'); expect(region).toHaveTextContent('No equivale al Médico');
  expect(screen.getByRole('main')).not.toHaveTextContent(/NO-SALIR|SQL-PRIVADO/);
});
it.each(['gestion', 'identidad', 'detalle'])('respuesta 200 no verificable por %s no publica contexto parcial', async caso => {
  respond = config => response(config, config.url.endsWith('/gestion') ? (caso === 'gestion' ? { ...gestion(), acciones: null } : gestion())
    : caso === 'identidad' ? { ...detalle(rol), paciente: { ...detalle(rol).paciente, id_paciente: '88' } }
      : caso === 'detalle' ? {} : detalle(rol));
  mount(); expect(await screen.findByRole('alert')).toHaveTextContent('No pudimos verificar');
  expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
});
it('actualizar oculta contexto previo y publica programación vigente sin adoptar campos del detalle', async () => {
  mount(); await screen.findByRole('region', { name: 'Condiciones de gestión' });
  respond = config => response(config, config.url.endsWith('/gestion') ? { ...gestion(), estado: 'Cancelada', acciones: { reprogramar: false, cancelar: false },
    impedimentos: ['CITA_GESTION_NO_PERMITIDA'], version_programacion: 2 } : detalle(rol));
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar gestión' }));
  await waitFor(() => expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('Cancelada'));
  expect(calls).toHaveLength(4);
});
it('cambio de ID descarta ambas respuestas tardías y limpia contexto durante carga', async () => {
  const pending = []; respond = config => new Promise(resolve => pending.push({ config, complete: data => resolve(response(config, data)) }));
  const router = mount(); await waitFor(() => expect(pending).toHaveLength(2));
  await act(async () => router.navigate('/citas/22/gestion')); await waitFor(() => expect(pending).toHaveLength(4));
  expect(pending.slice(0, 2).every(p => p.config.signal.aborted)).toBe(true);
  await act(async () => { for (const p of pending.slice(2)) p.complete(p.config.url.endsWith('/gestion') ? { ...gestion(), id_cita: '22', codigo_cita: 'NUEVA' }
    : { ...detalle(rol), id_cita: '22', codigo_cita: 'NUEVA' }); });
  await screen.findByRole('region', { name: 'Programación actual: NUEVA' });
  await act(async () => { for (const p of pending.slice(0, 2)) p.complete(p.config.url.endsWith('/gestion') ? gestion() : detalle(rol)); });
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
});
it('pagehide limpia datos y pageshow reconsulta sin restaurar copias persistidas', async () => {
  mount(); await screen.findByRole('region', { name: 'Condiciones de gestión' });
  await act(async () => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
  await act(async () => window.dispatchEvent(new Event('pageshow'))); await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(calls).toHaveLength(4);
});
it('cambio de identidad/rol elimina contexto anterior y reconsulta', async () => {
  mount(); await screen.findByRole('region', { name: 'Condiciones de gestión' });
  const pending = []; respond = config => new Promise(resolve => pending.push(() => resolve(response(config))));
  rol = 'Recepcionista'; consultarSesion.mockResolvedValue(sesion(rol, '2'));
  await act(async () => window.dispatchEvent(new Event('focus'))); await waitFor(() => expect(pending).toHaveLength(2));
  expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
  await act(async () => pending.forEach(done => done())); await screen.findByRole('region', { name: 'Condiciones de gestión' });
});
it('logout aborta lectura y no permite que respuestas tardías restauren gestión', async () => {
  const pending = []; respond = config => new Promise(resolve => pending.push({ config, done: () => resolve(response(config)) }));
  mount(); await waitFor(() => expect(pending).toHaveLength(2)); cerrarSesion.mockResolvedValue(undefined);
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(pending.every(p => p.config.signal.aborted)).toBe(true); await act(async () => pending.forEach(p => p.done()));
  expect(screen.queryByRole('region', { name: 'Condiciones de gestión' })).not.toBeInTheDocument();
});
it('429 respeta espera y solo consulta nuevamente por decisión del usuario', async () => {
  respond = config => Promise.reject(fail(config, 429)); mount(); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Volver a consultar' })).toBeEnabled(), { timeout: 2500 }); expect(calls).toHaveLength(2);
  respond = config => response(config); await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' }));
  await screen.findByRole('region', { name: 'Condiciones de gestión' }); expect(calls).toHaveLength(4);
});
it.each(['/citas/01/gestion', '/citas/1/gestion?token=privado', '/citas/1/gestion#privado', '//ajeno.example.test'])('destino %s no se restaura como válido', value => {
  expect(destinoSeguro(value)).toBe('/inicio');
});
