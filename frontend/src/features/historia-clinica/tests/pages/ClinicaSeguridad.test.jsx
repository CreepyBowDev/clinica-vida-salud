import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion, cerrarSesion } from '../../../auth/services/auth.service';
import { altaConfirmada, contextoAlta, correccionConfirmada, detalle, historia, sesion } from '../utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let calls, raw, responder;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
function mount(path = '/citas/41/consulta/editar') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; raw = contextoAlta(); consultarSesion.mockResolvedValue(sesion('Médico'));
  responder = config => config.url === csrfURL ? response(config, null, 204) : config.method === 'post' ? response(config, altaConfirmada(JSON.parse(config.data)), 201)
    : config.method === 'patch' ? response(config, correccionConfirmada(JSON.parse(config.data))) : response(config, raw);
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = original; });
async function revisar(kind) {
  await screen.findByRole('checkbox');
  if (kind === 'post') { await userEvent.selectOptions(screen.getByLabelText('Tipo de consulta'), 'Control'); await userEvent.type(screen.getByLabelText('Motivo de consulta'), 'DATO-FICTICIO-PRIVADO'); }
  else { await userEvent.selectOptions(screen.getByLabelText('Acción para diagnóstico'), 'establecer'); await userEvent.type(screen.getByLabelText('Nuevo dato: diagnóstico'), 'DATO-FICTICIO-PRIVADO'); await userEvent.type(screen.getByLabelText('Motivo de corrección'), 'MOTIVO-FICTICIO-PRIVADO'); }
  await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: kind === 'post' ? 'Revisar registro' : 'Revisar cambios' }));
}
const save = kind => userEvent.click(screen.getByRole('button', { name: kind === 'post' ? 'Guardar consulta' : 'Guardar corrección' }));
it.each(['post', 'patch'].flatMap(kind => [401, 403, 404, 409, 419, 422, 429, 500].map(status => [kind, status])))('%s HTTP %s conserva permisos, resultados seguros y no hace replay', async (kind, status) => {
  if (kind === 'patch') raw = detalle();
  const normal = responder; responder = config => config.method === kind ? Promise.reject(new AxiosError('SQL-PRIVADO', undefined, config, null,
    { status, headers: { 'retry-after': '1' }, data: { error: { message: 'SQL-PRIVADO', code: 'CLINICA_EDICION_OBSOLETA', details: { [kind === 'post' ? 'consulta.motivo_consulta' : 'motivo_cambio']: ['Revisa este campo.'], desconocido: ['SECRETO-NO-MOSTRAR'] } } } })) : normal(config);
  mount(); await revisar(kind); await save(kind);
  if (status === 401) await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  else await waitFor(() => expect(screen.getAllByRole('alert').length).toBeGreaterThan(0));
  expect(screen.queryByText('SQL-PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByText('SECRETO-NO-MOSTRAR')).not.toBeInTheDocument();
  expect(calls.filter(c => c.method === kind)).toHaveLength(1); expect(screen.queryByText(/Consulta registrada\.|Corrección guardada\.|No hubo cambios\./)).not.toBeInTheDocument();
  if (status === 429) expect(screen.getByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' })).toBeDisabled();
  if ([403,404].includes(status)) expect(screen.queryByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' })).not.toBeInTheDocument();
  if (status === 500) { expect(screen.getByRole('button', { name: 'Consultar estado actual' })).toBeInTheDocument(); expect(screen.queryByRole('form')).not.toBeInTheDocument(); }
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
it.each(['post', 'patch'])('avance de época retira borrador y revisión %s sin petición nueva', async kind => {
  if (kind === 'patch') raw = detalle(); mount(); await revisar(kind); const n = calls.length;
  act(() => avanzarSesion()); await screen.findByText('La sesión cambió. Consulta nuevamente antes de continuar.');
  expect(screen.queryByText('DATO-FICTICIO-PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByText('MOTIVO-FICTICIO-PRIVADO')).not.toBeInTheDocument(); expect(calls).toHaveLength(n);
  await userEvent.click(screen.getByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' })); await screen.findByRole('checkbox');
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls).toHaveLength(n + 1);
});
it.each(['post', 'patch'])('cambio de rol durante %s retira clínica y descarta respuesta anterior', async kind => {
  if (kind === 'patch') raw = detalle(); let finish; const normal = responder; responder = config => config.method === kind ? new Promise(resolve => { finish = () => resolve(normal(config)); }) : normal(config);
  mount(); await revisar(kind); await save(kind); await waitFor(() => expect(finish).toBeTypeOf('function'));
  consultarSesion.mockResolvedValue(sesion('Paciente')); act(() => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); await act(async () => finish());
  expect(screen.queryByText('DATO-FICTICIO-PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByText(/Consulta registrada\.|Corrección guardada\./)).not.toBeInTheDocument();
});
it.each(['post', 'patch'])('pagehide durante %s conserva solo riesgo y restaura vía GET sin escritura', async kind => {
  if (kind === 'patch') raw = detalle(); let finish; const normal = responder; responder = config => config.method === kind ? new Promise(resolve => { finish = () => resolve(normal(config)); }) : normal(config);
  mount(); await revisar(kind); await save(kind); await waitFor(() => expect(finish).toBeTypeOf('function'));
  act(() => window.dispatchEvent(new Event('pagehide'))); act(() => window.dispatchEvent(new Event('pageshow')));
  expect(screen.queryByText('DATO-FICTICIO-PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByRole('form')).not.toBeInTheDocument();
  await act(async () => finish()); await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' }));
  await screen.findByText(/Estado actual consultado. No demuestra/); expect(calls.filter(c => c.method === kind)).toHaveLength(1); expect(calls.at(-1).method).toBe('get');
});
it.each(['/citas/41/consulta', '/historia-clinica/pacientes/9007199254740993'])('logout fallido en %s retira datos sin asumir sesión cerrada', async path => {
  raw = path.includes('historia-clinica') ? historia() : detalle(); cerrarSesion.mockRejectedValue(new Error('fallo ficticio'));
  mount(path); await screen.findByText('Medicación ficticia'); await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
  await screen.findByText('La sesión cambió. Vuelve a consultar antes de continuar.'); expect(screen.queryByText('Medicación ficticia')).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  expect(screen.queryByRole('heading', { name: 'Bienvenido de nuevo' })).not.toBeInTheDocument();
});
it('selección administrativa retira fichas ante cambio de sesión sin reconsultar automáticamente', async () => {
  consultarSesion.mockResolvedValue(sesion('Administrador')); raw = { data: [{ ...detalle().data.paciente, documento_identidad: null, activo: false }], meta: { total: 1, current_page: 1, last_page: 1 } };
  mount('/historia-clinica'); await screen.findByRole('link', { name: 'Ver historia clínica de Paciente Clínico ficticio' });
  act(() => avanzarSesion()); await screen.findByRole('heading', { name: 'La sesión cambió' });
  expect(screen.queryByRole('link', { name: 'Ver historia clínica de Paciente Clínico ficticio' })).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
});
it('cambiar de cita durante revisión no conserva clínica en el destino pendiente', async () => {
  const normal = responder; responder = config => config.url.includes('/42/') ? new Promise(() => {}) : normal(config);
  const router = mount(); await revisar('post'); await act(() => router.navigate('/citas/42/consulta/editar'));
  await screen.findByText('Si sales, se perderán los datos del formulario que no guardaste.'); await userEvent.click(screen.getByRole('button', { name: 'Salir sin conservar el formulario' }));
  expect(screen.queryByText('DATO-FICTICIO-PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Guardar consulta' })).not.toBeInTheDocument();
});
