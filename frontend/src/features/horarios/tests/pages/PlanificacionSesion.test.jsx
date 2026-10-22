import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { api, avanzarSesion } from '../../../../api/api';

// AuthProvider, servicios de autenticación y Axios reales; solo se sustituye el transporte HTTP.
const original = api.defaults.adapter;
const version = '2026-10-22T12:00:00.123456Z';
const admin = { id_usuario: '1', nombre_visible: 'Administración ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' };
const room = { id_consultorio: '2', codigo_consultorio: 'SALA-FICTICIA', nombre: 'Sala ficticia', activo: true, actualizado_en: version };
const configuration = { duracion_turno_minutos: 15, zona_horaria: 'America/La_Paz', actualizado_en: version };
const meta = { current_page: 1, last_page: 1, total: 1 };
const scenarios = [
  ['catálogo', '/administracion/consultorios', '/consultorios'],
  ['asignación', '/administracion/medicos/3/consultorio', '/medicos/3/consultorio'],
  ['horarios', '/administracion/horarios/medicos/3', '/medicos/3/horarios'],
  ['duración', '/administracion/horarios/configuracion', '/configuracion-citas'],
];
let calls, session, delayedPath, resolveRead, delayWrite, resolveWrite;
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
beforeEach(() => {
  avanzarSesion(); calls = []; session = admin; delayedPath = ''; resolveRead = null; delayWrite = false; resolveWrite = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    const ok = data => ({ config, status: 200, headers: {}, data });
    if (config.url.includes('csrf-cookie')) return ok('');
    if (config.url === '/auth/me') {
      if (!session) throw new AxiosError('Sesión ausente', undefined, config, null, { status: 401, headers: {}, data: {} });
      return ok({ data: session });
    }
    if (config.url === '/auth/logout') { session = null; return ok(''); }
    if (config.method === 'patch' && delayWrite) return new Promise(resolve => { resolveWrite = () => resolve(ok({ data: { ...configuration, duracion_turno_minutos: 20 } })); });
    let data;
    if (config.url === '/medicos/3') data = { data: { id_medico: '3', codigo_medico: 'MED-FICTICIO', nombres: 'Médico', apellidos: 'Ficticio', activo: true } };
    else if (config.url === '/medicos/3/consultorio') data = { data: { id_medico: '3', consultorio: room, actualizado_en: version } };
    else if (config.url === '/medicos/3/horarios') data = { data: { actualizado_en: version, duracion_turno_minutos: 15, elementos: [] }, meta: { ...meta, total: 0 } };
    else if (config.url.endsWith('/impacto')) data = { data: { puede_confirmar: true, totales: { conflictos: 0 }, elementos: [] }, meta: { ...meta, total: 0 } };
    else if (config.url === '/configuracion-citas') data = { data: configuration };
    else if (config.url === '/consultorios') data = { data: [room], meta };
    else throw new Error(`Petición inesperada: ${config.url}`);
    if (config.url === delayedPath) return new Promise(resolve => { resolveRead = () => resolve(ok(data)); });
    return ok(data);
  };
});
afterEach(() => { api.defaults.adapter = original; avanzarSesion(); });

it.each(scenarios)('%s no reaparece por una lectura tardía después de logout', async (_name, route, endpoint) => {
  delayedPath = endpoint;
  const user = userEvent.setup(); mount(route);
  await waitFor(() => expect(resolveRead).toBeTypeOf('function'));
  await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await act(async () => resolveRead());
  expect(screen.queryByText('Sala ficticia')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Duración propuesta en minutos')).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Bienvenido de nuevo' })).toBeInTheDocument();
  expect(calls.filter(c => c.url === '/auth/logout')).toHaveLength(1);
  expect(calls.filter(c => ['put', 'patch'].includes(c.method))).toHaveLength(0);
});

it.each(scenarios)('%s descarta lectura pendiente si /me detecta sesión revocada al recuperar foco', async (_name, route, endpoint) => {
  delayedPath = endpoint; mount(route);
  await waitFor(() => expect(resolveRead).toBeTypeOf('function'));
  session = null; fireEvent.focus(window);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await act(async () => resolveRead());
  expect(screen.getByRole('heading', { name: 'Bienvenido de nuevo' })).toBeInTheDocument();
  expect(screen.queryByRole('navigation', { name: 'Navegación principal' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.url === '/auth/me')).toHaveLength(2);
});

it('retira borrador y confirmación cuando la revalidación detecta cambio de rol', async () => {
  const user = userEvent.setup(); mount('/administracion/horarios/configuracion');
  const input = await screen.findByLabelText('Duración propuesta en minutos');
  await user.clear(input); await user.type(input, '20');
  await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' }));
  await user.click(await screen.findByRole('button', { name: 'Revisar cambio global' }));
  session = { ...admin, rol: 'Recepcionista' }; fireEvent.focus(window);
  await screen.findByRole('heading', { name: 'Acceso no permitido' });
  expect(screen.queryByLabelText('Duración propuesta en minutos')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Guardar duración global' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(0);
});

it('una escritura tardía tras logout no publica éxito ni restaura la propuesta', async () => {
  delayWrite = true;
  const user = userEvent.setup(); mount('/administracion/horarios/configuracion');
  const input = await screen.findByLabelText('Duración propuesta en minutos');
  await user.clear(input); await user.type(input, '20');
  await user.click(screen.getByRole('button', { name: 'Consultar impacto de duración' }));
  await user.click(await screen.findByRole('button', { name: 'Revisar cambio global' }));
  await user.click(screen.getByRole('button', { name: 'Guardar duración global' }));
  await waitFor(() => expect(resolveWrite).toBeTypeOf('function'));
  await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await act(async () => resolveWrite());
  expect(screen.queryByText(/Duración global guardada/)).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Duración propuesta en minutos')).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  expect(calls.filter(c => c.url === '/configuracion-citas' && c.method === 'get')).toHaveLength(1);
});
