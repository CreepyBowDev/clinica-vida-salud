import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { altaConfirmada, contextoAlta, detalle, sesion } from '../utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, respond;
const response = (config, data, status = 200) => ({ config, status, headers: {}, data });
const fail = (config, status, details = {}) => new AxiosError('NO-MOSTRAR-SQL', undefined, config, null, status ? { status, headers: {}, data: { error: { code: 'CLINICA_EDICION_OBSOLETA', message: 'NO-MOSTRAR-SQL', details } } } : undefined);
function mount(path = '/citas/41/consulta/editar') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; rol = 'Médico'; consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => config.url === csrfURL ? response(config, null, 204) : config.method === 'post'
    ? response(config, altaConfirmada(JSON.parse(config.data)), 201) : response(config, contextoAlta());
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });
async function preparar() {
  await screen.findByRole('form', { name: 'Registrar consulta' });
  await userEvent.selectOptions(screen.getByLabelText('Tipo de consulta'), 'Control');
  await userEvent.type(screen.getByLabelText('Motivo de consulta'), 'Motivo ficticio');
  await userEvent.click(screen.getByRole('checkbox'));
  await userEvent.click(screen.getByRole('button', { name: 'Revisar registro' }));
  await screen.findByRole('heading', { name: 'Revisar registro de consulta' });
}
it.each(['Administrador', 'Médico'])('%s registra desde contexto autorizado, revisión y declaración sin modificar Cita', async role => {
  rol = role; mount(); const checkbox = await screen.findByRole('checkbox'); expect(checkbox).not.toBeChecked();
  // La elegibilidad viene del servidor incluso cuando el inicio está por delante del reloj cliente.
  await preparar(); expect(screen.getByRole('heading', { name: 'Revisar registro de consulta' })).toHaveFocus();
  expect(screen.getByRole('main')).toHaveTextContent('Usuario ejecutor: Persona ficticia. Médico responsable: Médica De prueba.');
  await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' })); await screen.findByText(/Consulta registrada. El estado de la cita no se modificó/);
  const post = calls.filter(c => c.method === 'post'); expect(post).toHaveLength(1); expect(post[0].url).toBe('/citas/41/consulta');
  expect(JSON.parse(post[0].data).atencion_real_verificada).toBe(true); expect(calls.some(c => c.method === 'patch')).toBe(false);
  expect(screen.queryByRole('form')).not.toBeInTheDocument(); expect(localStorage.length + sessionStorage.length).toBe(0);
});
it('revisión sin campos ni declaración no guarda y enfoca errores', async () => {
  mount(); await screen.findByRole('checkbox'); await userEvent.click(screen.getByRole('button', { name: 'Revisar registro' }));
  expect(screen.getByRole('alert')).toHaveTextContent('Revisa los campos'); expect(screen.queryByRole('button', { name: 'Guardar consulta' })).not.toBeInTheDocument();
  expect(calls).toHaveLength(1); expect(screen.getByLabelText('Motivo de consulta')).toHaveAttribute('aria-invalid', 'true');
  expect(screen.getByRole('checkbox')).toHaveAttribute('aria-describedby', 'clinical-declaration-help clinical-declaration-error');
});
it('volver al formulario retira revisión y desmarca declaración', async () => {
  mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Volver al formulario' }));
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(screen.getByLabelText('Motivo de consulta')).toHaveValue('Motivo ficticio');
  expect(screen.queryByRole('button', { name: 'Guardar consulta' })).not.toBeInTheDocument();
});
it('antecedentes conservan por defecto y requieren acciones explícitas para cambiar o quitar', async () => {
  mount(); await screen.findByRole('checkbox');
  expect(screen.getByLabelText('Acción para medicamentos')).toHaveValue('conservar');
  await userEvent.selectOptions(screen.getByLabelText('Acción para medicamentos'), 'quitar');
  await userEvent.selectOptions(screen.getByLabelText('Acción para alergias'), 'establecer');
  await userEvent.type(screen.getByLabelText('Nuevo dato: alergias'), 'Dato ficticio de prueba');
  await preparar(); expect(screen.getByRole('main')).toHaveTextContent('Quitar dato; quedará sin registrar');
  await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' })); await screen.findByText(/Consulta registrada/);
  expect(JSON.parse(calls.find(c => c.method === 'post').data).antecedentes).toEqual({ alergias: 'Dato ficticio de prueba', medicamentos: null });
});
it.each(['Recepcionista', 'Paciente'])('%s no accede al editor ni a su selector', async role => {
  rol = role; const router = mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
  await act(() => router.navigate('/registrar-consulta')); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '9223372036854775808', 'invalido'])('ID %s inválido no genera HTTP', async id => {
  mount(`/citas/${id}/consulta/editar`); await screen.findByRole('heading', { name: 'Consulta no disponible' }); expect(calls).toHaveLength(0);
});
it('consulta existente abre corrección con vigentes y sin escritura automática', async () => {
  respond = config => response(config, detalle()); mount(); await screen.findByRole('form', { name: 'Corregir consulta' });
  expect(screen.getByLabelText('Motivo de consulta')).toHaveValue('Seguimiento ficticio'); expect(calls).toHaveLength(1);
});
it('Médico solo relacionado no recibe formulario si falta contexto editable', async () => {
  const d = contextoAlta(); delete d.data.edicion; respond = config => response(config, d); mount(); await screen.findByText(/No tienes contexto autorizado/);
  expect(screen.queryByRole('form')).not.toBeInTheDocument();
});
it('inicio anticipado se informa según servidor y no habilita por reloj local', async () => {
  const d = contextoAlta(); d.data.edicion.acciones.registrar = false; d.data.edicion.impedimentos.registrar = ['CLINICA_ATENCION_ANTICIPADA'];
  respond = config => response(config, d); mount(); await screen.findByText(/La cita aún no alcanzó su inicio según el servidor/);
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each([409, 419])('rechazo %s bloquea reenvío hasta consultar contexto nuevo', async status => {
  const normal = respond; respond = config => config.method === 'post' ? Promise.reject(fail(config, status)) : normal(config);
  mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' }));
  await screen.findByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' }); expect(screen.queryByRole('form')).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveTextContent('NO-MOSTRAR-SQL');
  await userEvent.click(screen.getByRole('button', { name: 'Consultar contexto y comenzar nueva revisión' }));
  await screen.findByRole('checkbox'); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
});
it('422 conserva borrador corregible pero exige revisar y declarar otra vez', async () => {
  const normal = respond; respond = config => config.method === 'post' ? Promise.reject(fail(config, 422, { 'consulta.motivo_consulta': ['Corrige el motivo.'] })) : normal(config);
  mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' }));
  await screen.findByLabelText('Motivo de consulta'); expect(screen.getByLabelText('Motivo de consulta')).toHaveValue('Motivo ficticio'); expect(screen.getByRole('checkbox')).not.toBeChecked();
  expect(screen.getByLabelText('Motivo de consulta')).toHaveAttribute('aria-invalid', 'true'); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
});
it.each([undefined, 500])('fallo %s posterior al POST bloquea replay y GET no afirma autoría ni fracaso', async status => {
  const normal = respond; respond = config => config.method === 'post' ? Promise.reject(fail(config, status)) : normal(config);
  mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' })); await screen.findByRole('button', { name: 'Consultar estado actual' });
  expect(screen.queryByRole('form')).not.toBeInTheDocument(); await userEvent.click(screen.getByRole('button', { name: 'Consultar estado actual' }));
  await screen.findByText(/Estado actual consultado. No demuestra/); expect(screen.getByRole('main')).toHaveTextContent('esto no demuestra que el envío anterior haya fallado');
  expect(calls.filter(c => c.method === 'post')).toHaveLength(1); expect(calls.at(-1).method).toBe('get');
});
it('doble clic de guardado produce un solo POST', async () => {
  let resolvePost; const normal = respond; respond = config => config.method === 'post' ? new Promise(resolve => { resolvePost = () => resolve(normal(config)); }) : normal(config);
  mount(); await preparar(); await userEvent.dblClick(screen.getByRole('button', { name: 'Guardar consulta' })); await waitFor(() => expect(resolvePost).toBeTypeOf('function'));
  expect(calls.filter(c => c.method === 'post')).toHaveLength(1); await act(async () => resolvePost()); await screen.findByText(/Consulta registrada/);
});
it('pagehide retira borrador y pageshow obtiene contexto nuevo sin restaurarlo', async () => {
  mount(); await preparar(); act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText('Motivo ficticio')).not.toBeInTheDocument();
  act(() => window.dispatchEvent(new Event('pageshow'))); await screen.findByRole('checkbox'); expect(screen.getByLabelText('Motivo de consulta')).toHaveValue(''); expect(screen.getByRole('checkbox')).not.toBeChecked();
});
it('salida con borrador pide confirmación sin persistir contenido en navegación', async () => {
  mount(); await screen.findByRole('checkbox'); await userEvent.type(screen.getByLabelText('Motivo de consulta'), 'Borrador ficticio');
  await userEvent.click(screen.getByRole('link', { name: 'Volver a consulta' })); await screen.findByText('Si sales, se perderán los datos del formulario que no guardaste.');
  await userEvent.click(screen.getByRole('button', { name: 'Permanecer' })); expect(screen.getByLabelText('Motivo de consulta')).toHaveValue('Borrador ficticio');
});
it('selector médico guía a agenda sin consultas generales de pacientes', async () => {
  mount('/registrar-consulta'); await screen.findByRole('heading', { name: 'Seleccionar cita para registrar consulta' });
  expect(within(screen.getByRole('main')).getByRole('link', { name: 'Consultar agenda médica' })).toHaveAttribute('href', '/agenda'); expect(calls).toHaveLength(0);
});
it('401 durante POST retira toda información clínica y exige iniciar sesión', async () => {
  const normal = respond; respond = config => config.method === 'post' ? Promise.reject(fail(config, 401)) : normal(config);
  mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('Motivo ficticio')).not.toBeInTheDocument();
  expect(screen.queryByRole('form', { name: 'Registrar consulta' })).not.toBeInTheDocument();
});
it('respuesta POST tardía no muestra éxito tras abandonar una operación incierta', async () => {
  let resolvePost; const normal = respond; respond = config => config.method === 'post' ? new Promise(resolve => { resolvePost = () => resolve(normal(config)); }) : normal(config);
  const router = mount(); await preparar(); await userEvent.click(screen.getByRole('button', { name: 'Guardar consulta' }));
  await waitFor(() => expect(resolvePost).toBeTypeOf('function'));
  await act(() => router.navigate('/registrar-consulta')); await screen.findByText('La consulta podría haberse guardado. Salir no revierte la operación.');
  await userEvent.click(screen.getByRole('button', { name: 'Salir sin conservar el formulario' })); await screen.findByRole('heading', { name: 'Seleccionar cita para registrar consulta' });
  await act(async () => resolvePost()); expect(screen.queryByText(/Consulta registrada. El estado/)).not.toBeInTheDocument();
});
