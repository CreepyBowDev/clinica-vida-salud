import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { correccionConfirmada, detalle, meta, sesion } from '../utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, raw, calls, respond;
const response = (config, data, status = 200) => ({ config, status, headers: {}, data });
const fail = (config, status, details = {}) => new AxiosError('NO-MOSTRAR-SQL', undefined, config, null, status ? { status, headers: {}, data: { error: { code: 'CLINICA_EDICION_OBSOLETA', details } } } : undefined);
function mount(path = '/citas/41/consulta/editar') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Médico'; raw = detalle(); calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => config.url === csrfURL ? response(config, null, 204) : config.method === 'patch'
    ? response(config, correccionConfirmada(JSON.parse(config.data), raw)) : response(config, raw);
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });
async function modificar() {
  await screen.findByRole('form', { name: 'Corregir consulta' });
  await userEvent.selectOptions(screen.getByLabelText('Acción para diagnóstico'), 'establecer');
  await userEvent.type(screen.getByLabelText('Nuevo dato: diagnóstico'), 'Diagnóstico ficticio corregido');
  await userEvent.type(screen.getByLabelText('Motivo de corrección'), 'Corrección ficticia comprobada');
}
async function revisar() {
  await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: 'Revisar cambios' }));
  await screen.findByRole('heading', { name: 'Revisar corrección de consulta' });
}
it.each(['Administrador', 'Médico'])('%s corrige desde vigentes con motivo/revisión y un PATCH, sin segunda consulta', async role => {
  rol = role; mount(); await modificar(); await revisar();
  expect(screen.getByRole('heading', { name: 'Revisar corrección de consulta' })).toHaveFocus(); expect(screen.getByRole('main')).toHaveTextContent('Vigente: No registrado');
  expect(screen.getByRole('main')).toHaveTextContent('Después de guardar: Diagnóstico ficticio corregido');
  await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' })); await screen.findByText(/Corrección guardada. Se conservaron las versiones anteriores/);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(calls.some(c => c.method === 'post')).toBe(false);
  const p = JSON.parse(calls.find(c => c.method === 'patch').data); expect(p.consulta).toEqual({ diagnostico: 'Diagnóstico ficticio corregido' });
  expect(p.originales.id_consulta).toBe('31'); expect(p.originales.id_version_consulta).toBe('9007199254740995');
});
it('motivo obligatorio ante cambio efectivo, declaración desmarcada inicialmente', async () => {
  mount(); await screen.findByRole('checkbox'); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await userEvent.selectOptions(screen.getByLabelText('Acción para medicamentos'), 'quitar');
  await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: 'Revisar cambios' }));
  expect(screen.getByLabelText('Motivo de corrección')).toHaveAttribute('aria-invalid', 'true'); expect(calls).toHaveLength(1);
});
it('no-op no exige motivo y muestra resultado sin nuevas versiones', async () => {
  mount(); await screen.findByRole('checkbox'); await revisar(); expect(screen.getByRole('main')).toHaveTextContent('No hay cambios efectivos. El servidor debe validar originales');
  await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' })); await screen.findByText(/No hubo cambios. El servidor validó/);
  expect(JSON.parse(calls.find(c => c.method === 'patch').data)).not.toHaveProperty('motivo_cambio');
});
it('solo antecedentes usa la consulta real existente y conserva campos no modificados', async () => {
  mount(); await screen.findByRole('checkbox'); await userEvent.selectOptions(screen.getByLabelText('Acción para medicamentos'), 'quitar');
  await userEvent.type(screen.getByLabelText('Motivo de corrección'), 'Motivo ficticio'); await revisar();
  await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' })); await screen.findByText(/Corrección guardada/);
  const p = JSON.parse(calls.find(c => c.method === 'patch').data); expect(p).not.toHaveProperty('consulta'); expect(p.antecedentes).toEqual({ medicamentos: null });
});
it('limpiar un texto en modificar no equivale a quitar: exige acción explícita', async () => {
  mount(); await screen.findByRole('checkbox'); await userEvent.selectOptions(screen.getByLabelText('Acción para observaciones'), 'establecer');
  await userEvent.clear(screen.getByLabelText('Nuevo dato: observaciones')); await userEvent.type(screen.getByLabelText('Motivo de corrección'), 'Motivo ficticio');
  await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: 'Revisar cambios' }));
  expect(screen.getByLabelText('Nuevo dato: observaciones')).toHaveAttribute('aria-invalid', 'true'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(0);
});
it('volver desmarca declaración y conserva borrador y motivo', async () => {
  mount(); await modificar(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Volver al formulario' }));
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(screen.getByLabelText('Motivo de corrección')).toHaveValue('Corrección ficticia comprobada');
});
it.each(['Recepcionista', 'Paciente'])('%s nunca obtiene editor de corrección', async role => {
  rol = role; mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it('Médico relacionado sin edicion solo puede leer', async () => {
  delete raw.data.edicion; mount(); await screen.findByText(/No tienes contexto autorizado/); expect(screen.queryByRole('form')).not.toBeInTheDocument();
});
it.each(['CLINICA_CITA_NO_PERMITIDA', 'CLINICA_ATENCION_ANTICIPADA', 'CLINICA_EDICION_OBSOLETA'])('impedimento %s no habilita corregir', async code => {
  raw.data.edicion.acciones.actualizar = false; raw.data.edicion.impedimentos.actualizar = [code]; mount(); await screen.findByRole('button', { name: 'Volver a consultar contexto' });
  expect(screen.queryByRole('form')).not.toBeInTheDocument();
});
it('409 por antecedentes compartidos retira borrador y obliga contexto nuevo sin rebase automático', async () => {
  const normal = respond; respond = config => config.method === 'patch' ? Promise.reject(fail(config, 409)) : normal(config);
  mount(); await modificar(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
  await screen.findByText('La cita o los antecedentes cambiaron. Consulta el contexto y realiza una nueva revisión.');
  expect(screen.queryByRole('form')).not.toBeInTheDocument();
  raw.data.antecedentes = { ...raw.data.antecedentes, id_version_antecedente: '9007199254740998', numero_version: 2, alergias: 'Antecedente actualizado desde otra cita ficticia' };
  raw.data.edicion.originales.id_version_antecedente = '9007199254740998';
  await userEvent.click(screen.getByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' })); await screen.findByRole('form', { name: 'Corregir consulta' });
  expect(screen.getByRole('main')).toHaveTextContent('Antecedente actualizado desde otra cita ficticia'); expect(screen.getByLabelText('Motivo de corrección')).toHaveValue('');
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.getByRole('checkbox')).not.toBeChecked();
});
it('422 de motivo conserva borrador, descarta revisión y exige declarar otra vez', async () => {
  const normal = respond; respond = config => config.method === 'patch' ? Promise.reject(fail(config, 422, { motivo_cambio: ['Corrige el motivo.'] })) : normal(config);
  mount(); await modificar(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' })); await screen.findByRole('form', { name: 'Corregir consulta' });
  expect(screen.getByLabelText('Motivo de corrección')).toHaveAttribute('aria-invalid', 'true'); expect(screen.getByRole('checkbox')).not.toBeChecked();
  expect(screen.getByLabelText('Nuevo dato: diagnóstico')).toHaveValue('Diagnóstico ficticio corregido');
});
it.each([undefined, 500])('fallo %s de PATCH permite solo GET de recuperación sin atribuir corrección', async status => {
  const normal = respond; respond = config => config.method === 'patch' ? Promise.reject(fail(config, status)) : normal(config);
  mount(); await modificar(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar corrección' })); await screen.findByRole('button', { name: 'Consultar estado actual' });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' })); await screen.findByText(/Estado actual consultado. No demuestra/);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(screen.queryByText(/Corrección guardada/)).not.toBeInTheDocument();
});
it('doble clic no duplica PATCH', async () => {
  let finish; const normal = respond; respond = config => config.method === 'patch' ? new Promise(resolve => { finish = () => resolve(normal(config)); }) : normal(config);
  mount(); await modificar(); await revisar(); await userEvent.dblClick(screen.getByRole('button', { name: 'Guardar corrección' }));
  await waitFor(() => expect(finish).toBeTypeOf('function')); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
  await act(async () => finish()); await screen.findByText(/Corrección guardada/);
});
it('pagehide retira motivo/borrador y pageshow recarga originales vigentes', async () => {
  mount(); await modificar(); await revisar(); act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText('Corrección ficticia comprobada')).not.toBeInTheDocument();
  act(() => window.dispatchEvent(new Event('pageshow'))); await screen.findByRole('form', { name: 'Corregir consulta' }); expect(screen.getByLabelText('Motivo de corrección')).toHaveValue('');
});
it('histórico leído tras corregir conserva snapshots anteriores y motivo solo interno', async () => {
  const normal = respond;
  respond = config => { if (!config.url.endsWith('/versiones')) return normal(config); const viejo = detalle().data.consulta.version_vigente;
    return response(config, { data: [{ ...viejo, id_version_consulta: '9007199254740997', numero_version: 2, motivo_cambio: 'Motivo interno ficticio' }, viejo], meta: meta(2) }); };
  mount('/citas/41/consulta/versiones'); await screen.findByRole('heading', { name: 'Versión 2' }); expect(screen.getByRole('heading', { name: 'Versión 1' })).toBeInTheDocument();
  expect(within(screen.getByRole('main')).getByText('Motivo de corrección: Motivo interno ficticio')).toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
