import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { alternativas, cita, detalle, gestion, reprogramada, sesion } from '../utils/gestionFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const adapter = api.defaults.adapter;
let rol, calls, original, respond, total, saved, failRefresh;
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
const error = (config, status, code) => new AxiosError('SQL-PRIVADO', undefined, config, null, status ? {
  status, headers: { 'retry-after': '1' }, data: { error: { code, message: 'SQL-PRIVADO', details: { fecha: ['Revisa la fecha.'] } } } } : undefined);
function server(config) {
  if (config.url === csrfURL) return ok(config, {}, 204);
  if (config.method === 'patch') {
    const vista = alternativas(original, { fecha: JSON.parse(config.data).fecha }).data;
    saved = reprogramada(vista); return ok(config, { data: saved });
  }
  if (config.url.endsWith('/disponibilidad-reprogramacion')) return ok(config, alternativas(original, { ...config.params, total }));
  if (config.url.endsWith('/gestion')) {
    if (saved && failRefresh) throw error(config, 500);
    return ok(config, { data: saved ? { ...original, ...saved, consultado_en: original.consultado_en } : original });
  }
  return ok(config, { data: detalle(rol) });
}
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/citas/${cita.id_cita}/gestion`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
async function buscar() {
  await screen.findByRole('heading', { name: 'Reprogramar cita' });
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' }));
  return screen.findByRole('radio', { name: '08:00:00' });
}
async function revisar() {
  await userEvent.click(await buscar()); await userEvent.click(screen.getByRole('button', { name: 'Revisar reprogramación' }));
  return screen.getByRole('button', { name: 'Confirmar reprogramación' });
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Administrador'; calls = []; original = gestion(); saved = null; total = 1; failRefresh = false;
  consultarSesion.mockImplementation(async () => sesion(rol)); respond = server;
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista'])('%s revisa y confirma la misma cita con duración/sala vigentes, luego reconsulta', async role => {
  rol = role; original.estado = 'Confirmada'; mount(); const confirmar = await revisar();
  const region = screen.getByRole('region', { name: 'Revisar reprogramación' }); expect(region).toHaveTextContent('15 minutos'); expect(region).toHaveTextContent('20 minutos');
  expect(region).toHaveTextContent('Sala conservada ficticia'); expect(region).toHaveTextContent('NUEVO — Sala nueva');
  expect(region).toHaveTextContent('Confirmada volverá a Programada'); expect(screen.getByRole('heading', { name: 'Revisar reprogramación' })).toHaveFocus();
  expect(calls.every(c => c.method === 'get')).toBe(true); await userEvent.click(confirmar);
  await screen.findByText('Reprogramación guardada. La misma cita quedó Programada.');
  await waitFor(() => expect(calls.filter(c => c.url.endsWith('/gestion'))).toHaveLength(2));
  expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toHaveTextContent('20 minutos');
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
  expect(JSON.parse(calls.find(c => c.method === 'patch').data)).toEqual({ fecha: '2026-11-02', hora_inicio: '08:00:00', revision_turno: 'a'.repeat(64),
    version_programacion_original: 1, actualizada_en_original: original.actualizada_en });
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(localStorage.length + sessionStorage.length).toBe(0);
});
it('fecha inválida y ausencia de turno nunca habilitan confirmación', async () => {
  mount(); await screen.findByRole('heading', { name: 'Reprogramar cita' });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByText('Selecciona una fecha válida.');
  expect(calls).toHaveLength(2); expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
});
it('vacío de alternativas no borra la cita y no habilita guardado', async () => {
  total = 0; mount(); await screen.findByRole('heading', { name: 'Reprogramar cita' });
  fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' }));
  await screen.findByRole('heading', { name: 'No hay alternativas para esta fecha y página' });
  expect(screen.getByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).toBeInTheDocument();
});
it.each(['fecha', 'pagina', 'actualizar', 'volver'])('%s invalida revisión/selección sin enviar PATCH', async tipo => {
  total = 13; mount(); await revisar();
  if (tipo === 'fecha') fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-03' } });
  if (tipo === 'pagina') await userEvent.click(within(screen.getByRole('navigation', { name: 'Paginación de alternativas' })).getByRole('button', { name: 'Siguiente' }));
  if (tipo === 'actualizar') await userEvent.click(screen.getByRole('button', { name: 'Actualizar alternativas' }));
  if (tipo === 'volver') await userEvent.click(screen.getByRole('button', { name: 'Volver sin guardar' }));
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('originales cambiados en disponibilidad no se adoptan, exige actualizar gestión', async () => {
  respond = config => config.url.endsWith('/disponibilidad-reprogramacion') ? ok(config, alternativas({ ...original, version_programacion: 2 })) : server(config);
  mount(); await screen.findByRole('heading', { name: 'Reprogramar cita' }); fireEvent.change(screen.getByLabelText('Nueva fecha de atención'), { target: { value: '2026-11-02' } });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar alternativas' })); await screen.findByRole('alert');
  expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('ficha inactiva no bloquea alternativas y no se invocan endpoints de reserva', async () => {
  respond = config => config.url.endsWith('/disponibilidad-reprogramacion') ? ok(config, { ...alternativas(original), data: { ...alternativas(original).data, paciente_reservable: false } }) : server(config);
  mount(); const confirmar = await revisar(); await userEvent.click(confirmar); await screen.findByText('Reprogramación guardada. La misma cita quedó Programada.');
  expect(calls.some(c => c.url.includes('/reservas'))).toBe(false);
});
it('doble clic no duplica escritura ni permite actualizar mientras está guardando', async () => {
  let complete; respond = config => config.method === 'patch' ? new Promise(resolve => { complete = () => resolve(server(config)); }) : server(config);
  mount(); const confirmar = await revisar(); await userEvent.dblClick(confirmar);
  await screen.findByText('Guardando reprogramación…'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  expect(screen.queryByRole('button', { name: 'Actualizar gestión' })).not.toBeInTheDocument();
  await act(async () => complete()); await screen.findByText('Reprogramación guardada. La misma cita quedó Programada.');
});
it.each(['CITA_EDICION_OBSOLETA', 'CITA_REPROGRAMACION_OBSOLETA', 'CITA_TURNO_NO_DISPONIBLE', 'CITA_PACIENTE_SOLAPADO', 'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_REPROGRAMACION_SIN_CAMBIO'])('409 %s retira revisión y requiere consultar/revisar explícitamente', async code => {
  respond = config => { if (config.method === 'patch') throw error(config, 409, code); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('alert');
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(screen.getByRole('alert')).not.toHaveTextContent('SQL-PRIVADO'); expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar gestión y comenzar una nueva revisión' }));
  await screen.findByRole('heading', { name: 'Reprogramar cita' }); expect(screen.getByLabelText('Nueva fecha de atención')).toHaveValue('');
});
it.each([419, 422, 429, 403, 404])('rechazo conocido %s no reenvía y no afirma resultado exitoso', async status => {
  respond = config => { if (config.method === 'patch') throw error(config, status); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('alert');
  expect(screen.queryByText('Reprogramación guardada. La misma cita quedó Programada.')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Consultar estado actual' })).not.toBeInTheDocument(); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(['red', '500', 'malformada'])('%s tras enviar obliga a GET de estado y nunca repite PATCH', async tipo => {
  respond = config => {
    if (config.method === 'patch') { const success = server(config); if (tipo === 'malformada') return ok(config, { data: {} }); throw error(config, tipo === '500' ? 500 : undefined); }
    return server(config);
  };
  mount(); await userEvent.click(await revisar()); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.queryByRole('region', { name: `Programación actual: ${cita.codigo_cita}` })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Confirmar reprogramación' })).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' }));
  await screen.findByText(/Estado actual consultado. No demuestra/); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  expect(screen.getByRole('region', { name: 'Resultado de reprogramación' })).not.toHaveTextContent('Reprogramación guardada.');
});
it('CSRF fallido previo al envío no hace PATCH ni lo confunde con escritura incierta', async () => {
  respond = config => { if (config.url === csrfURL) throw error(config); return server(config); };
  mount(); await userEvent.click(await revisar()); await screen.findByText(/No se envió la reprogramación/);
  expect(calls.some(c => c.method === 'patch')).toBe(false); expect(screen.queryByRole('button', { name: 'Consultar estado actual' })).not.toBeInTheDocument();
});
it('200 válido seguido de fallo de refresco conserva éxito confirmado', async () => {
  failRefresh = true; mount(); await userEvent.click(await revisar()); await screen.findByText('Reprogramación guardada. La misma cita quedó Programada.');
  await screen.findByText('El guardado está confirmado; falta actualizar la consulta de gestión.'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('salida durante incertidumbre advierte, permanecer no ejecuta PATCH', async () => {
  respond = config => { if (config.method === 'patch') throw error(config); return server(config); };
  const router = mount(); await userEvent.click(await revisar()); await screen.findByRole('button', { name: 'Consultar estado actual' });
  await act(async () => router.navigate('/inicio')); await screen.findByText('La reprogramación podría haberse guardado. Salir no revierte la operación.');
  await userEvent.click(screen.getByRole('button', { name: 'Permanecer y consultar' })); expect(router.state.location.pathname).toContain('/gestion');
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
