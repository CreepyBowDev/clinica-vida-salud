import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { destinoSeguro } from '../../../../constants/roles';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { detalle, historia, meta, paciente, sesion, version } from '../utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, respond;
const response = (config, data) => ({ config, status: 200, headers: {}, data });
const fail = (config, status) => new AxiosError('NO-MOSTRAR-SQL', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { message: 'NO-MOSTRAR-SQL' } } } : undefined);
function mount(path = `/historia-clinica/pacientes/${paciente.id_paciente}`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Médico'; calls = [];
  consultarSesion.mockImplementation(async () => sesion(rol));
  respond = config => response(config, config.url === '/mi-perfil' ? { data: paciente } : config.url.endsWith('/versiones') ? { data: [version(config.url.includes('antecedentes') ? 'antecedentes' : 'consulta')], meta: meta() } : config.url.endsWith('/consulta') ? detalle() : historia());
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });

it.each(['Administrador', 'Médico'])('%s consulta historia y llega al detalle sin escribir', async role => {
  rol = role; mount(); await screen.findByRole('heading', { name: 'Paciente Clínico ficticio' });
  expect(screen.getByRole('region', { name: 'Antecedentes vigentes' })).toHaveTextContent('No registrado');
  expect(screen.getByRole('main')).not.toHaveTextContent('Sin alergias');
  await userEvent.click(screen.getByRole('link', { name: /Ver consulta CON-FICTICIA/ }));
  await screen.findByText('Seguimiento ficticio');
  expect(screen.getByRole('main')).toHaveTextContent('Hora real de inicio: No registrada');
  expect(screen.getByRole('main')).toHaveTextContent('29/10/2026'); expect(screen.getByRole('main')).toHaveTextContent('22:30');
  expect(screen.queryByRole('button', { name: /Guardar|Registrar|Corregir/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Descargar/ })).not.toBeInTheDocument();
  expect(calls.every(c => c.method === 'get' && c.sessionBound)).toBe(true);
});
it('Paciente entra por perfil y solo ve vigentes, sin versiones, autoría, motivos ni edición', async () => {
  rol = 'Paciente'; mount('/historia-clinica'); await screen.findByText('Medicación ficticia');
  expect(calls.map(c => c.url)).toEqual(['/mi-perfil', `/pacientes/${paciente.id_paciente}/historia-clinica`]);
  await userEvent.click(screen.getByRole('link', { name: /Ver consulta CON-FICTICIA/ })); await screen.findByText('Seguimiento ficticio');
  expect(screen.getByRole('main')).not.toHaveTextContent('MOTIVO-INTERNO');
  expect(screen.queryByRole('link', { name: /Ver versiones/ })).not.toBeInTheDocument();
  expect(calls.some(c => c.url.includes('/versiones'))).toBe(false);
  expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0);
});
it('Médico entra desde su agenda sin hacer búsqueda general de pacientes', async () => {
  mount('/historia-clinica'); await screen.findByRole('heading', { name: 'Historia clínica' });
  expect(within(screen.getByRole('main')).getByRole('link', { name: 'Consultar agenda médica' })).toHaveAttribute('href', '/agenda');
  expect(calls).toHaveLength(0); expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});
it('la hoja de lectura conserva todo el texto y los saltos de línea sin controles que plieguen contenido clínico', async () => {
  const d = detalle(); d.data.consulta.version_vigente.observaciones = 'Primera línea ficticia\nSegunda línea ficticia';
  respond = config => response(config, d); mount('/citas/41/consulta');
  const consultation = await screen.findByRole('region', { name: 'Consulta registrada' });
  expect(consultation.closest('.clinical-sheet')).toBeInTheDocument();
  expect(consultation.querySelector('[data-content="consulta"]')).toHaveTextContent('Primera línea ficticia');
  expect(within(consultation).getByText(/Primera línea ficticia/).textContent).toBe('Primera línea ficticia\nSegunda línea ficticia');
  expect(within(consultation).getByText('Diagnóstico')).toBeInTheDocument();
  expect(screen.getByRole('region', { name: 'Antecedentes vigentes' })).toBeInTheDocument();
  expect(consultation.querySelector('details')).toBeNull();
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('Administrador reutiliza selección administrativa con enlace clínico y sin crear fichas', async () => {
  rol = 'Administrador'; respond = config => response(config, { data: [{ ...paciente, documento_identidad: null, activo: false }], meta: meta() });
  mount('/historia-clinica'); await screen.findByRole('link', { name: 'Ver historia clínica de Paciente Clínico ficticio' });
  expect(calls[0].url).toBe('/pacientes'); expect(screen.queryByRole('link', { name: 'Registrar paciente' })).not.toBeInTheDocument();
});
it.each(['consulta', 'antecedentes'])('histórico de %s muestra versiones protegidas sin restauración', async tipo => {
  const path = tipo === 'consulta' ? '/citas/41/consulta/versiones' : `/historia-clinica/pacientes/${paciente.id_paciente}/antecedentes/versiones`;
  mount(path); await screen.findByRole('heading', { name: 'Versión 1' });
  expect(screen.getByRole('main')).toHaveTextContent('MOTIVO-INTERNO'); expect(screen.getByRole('main')).toHaveTextContent('Identificador de usuario ejecutor: 50');
  expect(screen.queryByRole('button', { name: /Restaurar|Guardar/ })).not.toBeInTheDocument();
  const region = screen.getByRole('region', { name: 'Versiones clínicas' });
  expect(within(region).getByRole('heading', { name: 'Versión 1' })).toBeInTheDocument();
  expect(region.querySelector('details')).toBeNull();
  expect(within(region).queryByRole('textbox')).not.toBeInTheDocument();
});
it.each([
  ['Recepcionista', '/historia-clinica'], ['Recepcionista', '/citas/41/consulta'],
  ['Paciente', '/citas/41/consulta/versiones'], ['Paciente', `/historia-clinica/pacientes/${paciente.id_paciente}`],
  ['Paciente', `/historia-clinica/pacientes/${paciente.id_paciente}/antecedentes/versiones`],
])('%s no carga clínica en ruta prohibida %s', async (role, path) => {
  rol = role; mount(path); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '9223372036854775808', 'no-valido'])('ID inválido %s no genera HTTP', async id => {
  mount(`/citas/${id}/consulta`); await screen.findByRole('heading', { name: 'Consulta no disponible' }); expect(calls).toHaveLength(0);
});
it('vacío legítimo distingue antecedentes desconocidos de consulta inexistente', async () => {
  const d = detalle(); d.data.antecedentes = null; d.data.consulta = null; d.data.estudios = []; d.meta = meta(0); delete d.data.edicion;
  respond = config => response(config, d); mount('/citas/41/consulta');
  await screen.findByText('No hay una consulta registrada para esta cita.');
  expect(screen.getByText('No hay antecedentes registrados.')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Ver versiones de consulta' })).not.toBeInTheDocument();
});
it.each([403, 404, 422])('%s muestra fallo seguro sin datos ni reintento improcedente', async status => {
  respond = config => Promise.reject(fail(config, status)); mount(); const alert = await screen.findByRole('alert');
  expect(alert).not.toHaveTextContent('NO-MOSTRAR-SQL'); expect(screen.queryByText('Medicación ficticia')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Volver a consultar' })).not.toBeInTheDocument();
});
it.each([419, 500, undefined])('%s permite reconsulta GET manual segura', async status => {
  respond = config => Promise.reject(fail(config, status)); mount(); await screen.findByRole('alert');
  respond = config => response(config, historia()); await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar' }));
  await screen.findByText('Medicación ficticia'); expect(calls).toHaveLength(2); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('401 retira datos y vuelve al login', async () => {
  respond = config => Promise.reject(fail(config, 401)); mount(); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  expect(screen.queryByText('Medicación ficticia')).not.toBeInTheDocument();
});
it('200 malformado es error y nunca vacío clínico', async () => {
  respond = config => response(config, { data: { paciente, consultas: [] } }); mount(); await screen.findByRole('alert');
  expect(screen.queryByText('No hay consultas registradas')).not.toBeInTheDocument();
});
it('oculta lectura al navegar y descarta una respuesta anterior tardía', async () => {
  let resolveOld; respond = config => new Promise(resolve => { resolveOld = () => resolve(response(config, historia())); });
  const router = mount(); await vi.waitFor(() => expect(calls).toHaveLength(1));
  respond = config => response(config, detalle()); await act(() => router.navigate('/citas/41/consulta')); await screen.findByText('Seguimiento ficticio');
  await act(async () => resolveOld()); expect(screen.getByRole('heading', { name: 'Consulta médica' })).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Consultas' })).not.toBeInTheDocument();
});
it('pagehide retira contenido y pageshow obliga reconsulta', async () => {
  mount(); await screen.findByText('Medicación ficticia');
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText('Medicación ficticia')).not.toBeInTheDocument();
  act(() => window.dispatchEvent(new Event('pageshow'))); await screen.findByText('Medicación ficticia'); expect(calls).toHaveLength(2);
});
it('página de estudios se pagina independientemente del detalle de consulta', async () => {
  respond = config => { const d = detalle(); const page = config.params.page; d.data.estudios = page === 1 ? Array.from({ length: 20 }, (_, i) => ({ ...d.data.estudios[0], id_solicitud: String(100 + i) })) : [{ ...d.data.estudios[0], estudio_solicitado: 'Estudio final' }]; d.meta = meta(21, page); return response(config, d); };
  mount('/citas/41/consulta'); await screen.findByText('Seguimiento ficticio');
  await userEvent.click(within(screen.getByRole('navigation', { name: 'Paginación de estudios' })).getByRole('button', { name: 'Siguiente' }));
  await screen.findByText('Estudio final'); expect(calls.at(-1).params.page).toBe(2); expect(screen.getByText('Seguimiento ficticio')).toBeInTheDocument();
});
it('retornos clínicos seguros validan rol, ID y ruta completa', () => {
  expect(destinoSeguro('/citas/41/consulta', 'Paciente')).toBe('/citas/41/consulta');
  expect(destinoSeguro('/citas/41/consulta/versiones', 'Paciente')).toBe('/inicio');
  expect(destinoSeguro('/citas/41/consulta/versiones', 'Médico')).toBe('/citas/41/consulta/versiones');
  for (const p of ['/historia-clinica/pacientes/9223372036854775808', '/citas/41/consulta?texto=privado', '//evil.test']) expect(destinoSeguro(p, 'Administrador')).toBe('/inicio');
  expect(destinoSeguro('/citas/41/consulta/editar', 'Administrador')).toBe('/citas/41/consulta/editar');
  expect(destinoSeguro('/citas/41/consulta/editar', 'Paciente')).toBe('/inicio');
  expect(destinoSeguro('/historia-clinica', 'Recepcionista')).toBe('/inicio');
});
it.each(['anonymous', 'restricted'])('sesión %s no solicita clínica antes de restaurar acceso', async kind => {
  if (kind === 'anonymous') consultarSesion.mockRejectedValue({ response: { status: 401 } });
  else consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
  mount('/citas/41/consulta'); await screen.findByRole('heading', { name: kind === 'anonymous' ? 'Bienvenido de nuevo' : 'Cambia tu contraseña' });
  expect(calls).toHaveLength(0);
});
it('historia pagina consultas y retira texto de la página anterior durante carga', async () => {
  respond = config => {
    const d = historia(), page = config.params.page;
    d.data.consultas = page === 1 ? Array.from({ length: 20 }, (_, i) => ({ ...d.data.consultas[0], id_consulta: String(100 + i) })) : [{ ...d.data.consultas[0], codigo_consulta: 'ULTIMA-FICTICIA' }];
    d.meta = meta(21, page); return response(config, d);
  };
  mount(); await screen.findAllByRole('link', { name: 'Ver consulta CON-FICTICIA' });
  await userEvent.click(within(screen.getByRole('navigation', { name: 'Paginación de consultas' })).getByRole('button', { name: 'Siguiente' }));
  await screen.findByRole('link', { name: 'Ver consulta ULTIMA-FICTICIA' });
  expect(calls.at(-1).params.page).toBe(2); expect(screen.queryByRole('link', { name: 'Ver consulta CON-FICTICIA' })).not.toBeInTheDocument();
});
it('contenido clínico se muestra como texto y no como HTML ejecutable', async () => {
  const d = detalle(); d.data.consulta.version_vigente.observaciones = '<img src=x onerror="alert(1)">';
  respond = config => response(config, d); mount('/citas/41/consulta'); await screen.findByText('<img src=x onerror="alert(1)">');
  expect(screen.getByRole('main').querySelector('img')).toBeNull(); expect(screen.getByRole('main')).toHaveFocus();
  expect(document.title).toBe('Consulta médica | Clínica Vida Salud');
});
