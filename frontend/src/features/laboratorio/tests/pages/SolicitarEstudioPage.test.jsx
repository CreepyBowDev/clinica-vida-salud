import { StrictMode } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL, avanzarSesion } from '../../../../api/api';
import { destinoSeguro } from '../../../../constants/roles';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { detalle, sesion } from '../../../historia-clinica/tests/utils/clinicaFixtures';
import { solicitud, listado } from '../utils/laboratorioFixtures';
vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
let rol, calls, respond, version, permitido, rows;
const original = api.defaults.adapter;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
const fail = (config, status, details = {}) => new AxiosError('PRIVADO', undefined, config, null, status ? { config, status, headers: {}, data: { error: { code: status === 409 ? 'LAB_EDICION_OBSOLETA' : 'VALIDACION', message: 'PRIVADO', details } } } : undefined);
const recurso = estudio => { const c = detalle().data; return solicitud({ id_consulta: c.consulta.id_consulta, paciente: c.paciente, medico: c.cita.medico, creada_por: '7', estudio_solicitado: estudio }); };
function read(config) {
  if (config.url === csrfURL) return response(config, null, 204);
  if (config.url === '/citas/41/consulta') { const d = detalle(); d.data.consulta.version_vigente.id_version_consulta = version; return response(config, d); }
  if (config.url === '/consultas/31/solicitudes-laboratorio') return response(config, { ...listado(rows), data: { id_consulta: '31', solicitudes: rows,
    edicion: { originales: { id_version_consulta: version }, acciones: { solicitar: permitido }, impedimentos: permitido ? [] : ['LAB_ESTADO_NO_PERMITIDO'] } } });
  return response(config, { data: recurso('Estudio confirmado') });
}
function mount(path = '/citas/41/consulta/solicitar-estudio', strict = false) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  const tree = <AuthProvider><RouterProvider router={router} /></AuthProvider>;
  const view = render(strict ? <StrictMode>{tree}</StrictMode> : tree); return { ...view, router };
}
beforeEach(() => {
  vi.clearAllMocks(); rol = 'Médico'; calls = []; version = '9007199254740995'; permitido = true; rows = [];
  consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => config.method === 'post' ? response(config, { data: recurso(JSON.parse(config.data).estudio_solicitado) }, 201) : read(config);
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });
const postCalls = () => calls.filter(c => c.method === 'post');
async function revisar(text = 'Hemograma ficticio') {
  const antes = postCalls().length;
  await screen.findByRole('textbox', { name: 'Estudio solicitado' });
  await userEvent.type(screen.getByRole('textbox', { name: 'Estudio solicitado' }), text);
  await userEvent.click(screen.getByRole('checkbox', { name: /Declaro/ })); await userEvent.click(screen.getByRole('button', { name: 'Revisar solicitud' }));
  expect(screen.getByRole('heading', { name: 'Revisar solicitud de estudio' })).toHaveFocus(); expect(postCalls()).toHaveLength(antes);
}
it.each(['Administrador', 'Médico'])('%s tiene guía operativa y no crea consultas ni captura IDs libres', async role => {
  rol = role; mount('/laboratorio/solicitar'); await screen.findByRole('heading', { name: 'Solicitar estudios' });
  expect(screen.getByRole('link', { name: 'Consultar agenda médica' })).toHaveAttribute('href', '/agenda'); expect(calls).toHaveLength(0); expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Seleccionar paciente/ }) !== null).toBe(role === 'Administrador');
});
it.each(['Paciente', 'Recepcionista'].flatMap(role => ['/laboratorio/solicitar', '/citas/41/consulta/solicitar-estudio'].map(path => [role, path])))('%s no carga contexto de alta por %s', async (role, path) => {
  rol = role; mount(path); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0); expect(destinoSeguro(path, rol)).toBe('/inicio');
});
it.each(['Administrador', 'Médico'])('%s revisa identidad y estudio; envía una vez con true y original exactos', async role => {
  rol = role; mount(undefined, true); await revisar('  Hemograma ficticio  ');
  expect(screen.getAllByText('Paciente Clínico ficticio').length).toBeGreaterThan(0); expect(screen.getByText('Persona ficticia', { selector: 'dd' })).toBeVisible();
  await userEvent.dblClick(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await screen.findByText('Solicitud registrada en estado Pendiente. El estado de la cita no se modificó.');
  expect(postCalls()).toHaveLength(1); expect(postCalls()[0].url).toBe('/consultas/31/solicitudes-laboratorio');
  expect(JSON.parse(postCalls()[0].data)).toEqual({ estudio_solicitado: 'Hemograma ficticio', atencion_real_verificada: true, originales: { id_version_consulta: version } });
  expect(screen.getByRole('link', { name: 'Ver solicitud registrada' })).toHaveAttribute('href', '/laboratorio/solicitudes/71');
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(localStorage.length + sessionStorage.length).toBe(0);
});
it('obligatorios y longitud se validan antes de revisión/CSRF', async () => {
  mount(); await screen.findByRole('textbox', { name: 'Estudio solicitado' }); await userEvent.click(screen.getByRole('button', { name: 'Revisar solicitud' }));
  expect(screen.getAllByText('Indica el estudio solicitado (hasta 255 caracteres).').length).toBeGreaterThan(0); expect(postCalls()).toHaveLength(0);
  expect(calls.some(c => c.url === csrfURL)).toBe(false);
  await userEvent.type(screen.getByRole('textbox', { name: 'Estudio solicitado' }), 'x'.repeat(256)); await userEvent.click(screen.getByRole('button', { name: 'Revisar solicitud' })); expect(screen.queryByRole('button', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
});
it('volver a editar conserva texto pero retira declaración y revisión', async () => {
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Volver a editar' }));
  expect(screen.getByRole('textbox', { name: 'Estudio solicitado' })).toHaveValue('Hemograma ficticio'); expect(screen.getByRole('checkbox', { name: /Declaro/ })).not.toBeChecked();
  await waitFor(() => expect(screen.getByRole('checkbox', { name: /Declaro/ })).toHaveFocus());
});
it('sin consulta registrada no existe formulario ni POST implícito', async () => {
  respond = config => { const d = detalle(); d.data.consulta = null; d.data.estudios = []; d.meta.total = 0; return response(config, d); };
  mount(); await screen.findByRole('alert'); expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(calls.map(c => c.url)).toEqual(['/citas/41/consulta']); expect(postCalls()).toHaveLength(0);
});
it('impedimento del servidor retira formulario sin reglas locales de reloj/actividad', async () => {
  permitido = false; mount(); await screen.findByText(/según el servidor/); expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});
it('422 de campo conserva texto y limpia declaración/revisión sin mostrar detalles privados', async () => {
  respond = config => config.method === 'post' ? Promise.reject(fail(config, 422, { estudio_solicitado: ['PRIVADO'] })) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await screen.findByRole('textbox', { name: 'Estudio solicitado' }); expect(screen.getByRole('textbox', { name: 'Estudio solicitado' })).toHaveValue('Hemograma ficticio');
  expect(screen.getByRole('checkbox', { name: /Declaro/ })).not.toBeChecked(); expect(screen.queryByText('PRIVADO')).not.toBeInTheDocument(); expect(postCalls()).toHaveLength(1);
});
it('409 exige contexto nuevo, nueva revisión y nueva declaración', async () => {
  respond = config => config.method === 'post' ? Promise.reject(fail(config, 409)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await screen.findByText(/consulta o sus condiciones cambiaron/);
  version = '9007199254740996'; await userEvent.click(screen.getByRole('button', { name: 'Consultar contexto y preparar otra solicitud' }));
  await screen.findByRole('textbox', { name: 'Estudio solicitado' }); expect(screen.getByRole('textbox')).toHaveValue(''); expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(postCalls()).toHaveLength(1);
});
it.each([500, undefined, 'malformada'])('resultado incierto %s solo se reconcilia con GET, sin deduplicar por texto', async status => {
  respond = config => config.method === 'post' ? status === 'malformada' ? response(config, { data: {} }, 201) : Promise.reject(fail(config, status)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await screen.findByRole('button', { name: 'Consultar solicitudes actuales' });
  expect(screen.queryByRole('button', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
  rows = [recurso('Hemograma ficticio'), { ...recurso('Hemograma ficticio'), id_solicitud: '72' }];
  await userEvent.click(screen.getByRole('button', { name: 'Consultar solicitudes actuales' })); await screen.findByText(/Esta lista no demuestra/);
  expect(screen.getAllByText('Hemograma ficticio')).toHaveLength(2); expect(screen.queryByText(/Solicitud registrada en estado/)).not.toBeInTheDocument(); expect(postCalls()).toHaveLength(1);
});
it('pagehide durante envío retira borrador y conserva riesgo tras pageshow', async () => {
  let resolve; respond = config => config.method === 'post' ? new Promise(r => { resolve = () => r(response(config, { data: recurso('Hemograma ficticio') }, 201)); }) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await waitFor(() => expect(resolve).toBeDefined());
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText('Hemograma ficticio')).not.toBeInTheDocument();
  act(() => window.dispatchEvent(new Event('pageshow'))); expect(screen.getByRole('button', { name: 'Consultar solicitudes actuales' })).toBeVisible();
  await act(async () => resolve()); expect(screen.queryByText(/Solicitud registrada en estado/)).not.toBeInTheDocument(); expect(postCalls()).toHaveLength(1);
});
it('cambio de sesión retira revisión/datos y obliga reconsulta antes de preparar otro formulario', async () => {
  mount(); await revisar(); act(() => avanzarSesion()); expect(screen.queryByText('Hemograma ficticio')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
});
it('bloqueo de navegación advierte pérdida sin borrar por desplegar sidebar', async () => {
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Atención clínica' })); expect(screen.getByRole('heading', { name: 'Revisar solicitud de estudio' })).toBeVisible();
  await userEvent.click(screen.getByRole('link', { name: 'Volver a consulta' })); await screen.findByRole('button', { name: 'Permanecer' });
  await userEvent.click(screen.getByRole('button', { name: 'Permanecer' })); expect(screen.getByRole('heading', { name: 'Revisar solicitud de estudio' })).toBeVisible();
});
it('enlace desde consulta registrada abre ruta de cita, no usa id_consulta como id_cita', async () => {
  mount('/citas/41/consulta'); const link = await screen.findByRole('link', { name: 'Solicitar estudio' }); expect(link).toHaveAttribute('href', '/citas/41/consulta/solicitar-estudio');
});
it.each([403, 404, 419])('HTTP %s posterior al envío retira formulario y no informa éxito', async status => {
  respond = config => config.method === 'post' ? Promise.reject(fail(config, status)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await waitFor(() => expect(screen.getByRole('region', { name: 'Resultado de la solicitud' })).toHaveTextContent(status === 419 ? /caducado/ : status === 403 ? /permiso/ : /no está disponible/));
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(postCalls()).toHaveLength(1);
  expect(screen.queryByRole('heading', { name: 'Bienvenido de nuevo' })).not.toBeInTheDocument(); expect(screen.queryByText('PRIVADO')).not.toBeInTheDocument();
});
it('401 posterior al envío invalida sesión y retira texto privado', async () => {
  respond = config => config.method === 'post' ? Promise.reject(fail(config, 401)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('Hemograma ficticio')).not.toBeInTheDocument(); expect(postCalls()).toHaveLength(1);
});
it('429 del envío bloquea nueva preparación durante Retry-After', async () => {
  respond = config => { if (config.method !== 'post') return read(config); const e = fail(config, 429); e.response.headers['retry-after'] = '60'; return Promise.reject(e); };
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await screen.findByText(/Espera 60 segundos/); expect(screen.getByRole('button', { name: 'Consultar contexto y preparar otra solicitud' })).toBeDisabled(); expect(postCalls()).toHaveLength(1);
});
it('CSRF fallido no despacha POST ni promete que hubo solicitud', async () => {
  respond = config => config.url === csrfURL ? Promise.reject(fail(config, 500)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await screen.findByText(/No se envió la solicitud/); expect(postCalls()).toHaveLength(0); expect(screen.queryByRole('button', { name: 'Consultar solicitudes actuales' })).not.toBeInTheDocument();
});
it('consulta de recuperación vacía no se interpreta como rollback', async () => {
  respond = config => config.method === 'post' ? Promise.reject(fail(config)) : read(config);
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Consultar solicitudes actuales' }));
  await screen.findByText(/Esto no demuestra que el envío anterior haya fallado/); expect(postCalls()).toHaveLength(1);
});
it('tras éxito permite otro estudio del mismo nombre solo con contexto y declaraciones nuevos', async () => {
  mount(); await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await screen.findByRole('link', { name: 'Ver solicitud registrada' });
  await userEvent.click(screen.getByRole('button', { name: 'Consultar contexto y preparar otra solicitud' }));
  await screen.findByRole('textbox'); expect(screen.getByRole('textbox')).toHaveValue(''); expect(screen.getByRole('checkbox')).not.toBeChecked();
  await revisar(); await userEvent.click(screen.getByRole('button', { name: 'Solicitar estudio' })); await screen.findByRole('link', { name: 'Ver solicitud registrada' }); expect(postCalls()).toHaveLength(2);
});
