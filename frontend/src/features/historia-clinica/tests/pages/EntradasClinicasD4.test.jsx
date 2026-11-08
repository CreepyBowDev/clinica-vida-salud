import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { cita, pagina } from '../../../agenda/tests/utils/agendaFixtures';
import { contextoAlta, detalle, historia, paciente, sesion } from '../utils/clinicaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let rol, calls, clinical;
const ok = (config, data) => ({ config, status: 200, headers: {}, data });
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  const view = render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return { ...view, router };
}
beforeEach(() => {
  vi.clearAllMocks(); rol = 'Médico'; calls = []; clinical = detalle();
  consultarSesion.mockImplementation(async () => sesion(rol));
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url === '/agenda/medicos') return ok(config, pagina([{ ...cita.medico, activo: true }], { per_page: config.params.per_page }));
    if (config.url === '/agenda') return ok(config, pagina([{ ...cita, id_cita: '41' }], { medico: cita.medico, filtros: { id_medico: cita.medico.id_medico, fecha: '2026-11-01', estado: null } }));
    if (config.url === '/citas/41/consulta') return ok(config, clinical);
    if (config.url === '/pacientes') return ok(config, pagina([{ ...paciente, activo: true, documento_identidad: null }]));
    return ok(config, historia());
  };
});
afterEach(() => { api.defaults.adapter = original; });

it.each(['Administrador', 'Médico'].flatMap(role => [['/registrar-consulta', 'consulta'], ['/laboratorio/solicitar', 'solicitar-estudio']].map(([path, journey]) => [role, path, journey])))('D4 %s %s selecciona cita real y abre solo %s', async (role, path, journey) => {
  rol = role; const { router } = mount(path);
  if (role === 'Administrador') {
    await userEvent.click(await screen.findByRole('button', { name: 'Consultar agenda de Profesional Ficticio' }));
  }
  const link = await screen.findByRole('link', { name: 'Ver cita CIT-FICTICIA' });
  expect(link).toHaveAttribute('href', `/citas/41/consulta?recorrido=${journey}`);
  expect(calls.some(c => c.url === '/pacientes')).toBe(false);
  const agenda = calls.find(c => c.url === '/agenda');
  expect(Boolean(agenda.params.id_medico)).toBe(role === 'Administrador');
  await userEvent.click(link); await screen.findByText('Seguimiento ficticio');
  expect(screen.queryByRole('link', { name: 'Solicitar estudio' }) !== null).toBe(journey === 'solicitar-estudio');
  expect(screen.queryByRole('link', { name: 'Corregir consulta y antecedentes' }) !== null).toBe(journey === 'consulta');
  expect(router.state.location.search).toBe(`?recorrido=${journey}`);
  expect(document.title).toContain(journey === 'consulta' ? 'Registrar o actualizar' : 'Solicitar estudios');
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('D4 historia del Médico selecciona su cita y usa el Paciente de la respuesta autorizada', async () => {
  mount('/historia-clinica'); await userEvent.click(await screen.findByRole('link', { name: 'Ver cita CIT-FICTICIA' }));
  await screen.findByText('Seguimiento ficticio');
  const link = screen.getByRole('link', { name: 'Consultar historia clínica del paciente' });
  expect(link).toHaveAttribute('href', `/historia-clinica/pacientes/${paciente.id_paciente}`);
  expect(screen.queryByRole('link', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Corregir consulta y antecedentes' })).not.toBeInTheDocument();
  await userEvent.click(link); await screen.findByText('Medicación ficticia');
  expect(calls.some(c => c.url === '/pacientes')).toBe(false);
});
it('D4 CU-24 sin consulta registrada no ofrece formulario ni crea consulta', async () => {
  clinical = contextoAlta(); mount('/citas/41/consulta?recorrido=solicitar-estudio');
  await screen.findByText(/Para solicitar estudios se requiere una consulta registrada/);
  expect(screen.queryByRole('link', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('D4 consulta sin capacidad no ofrece otro editor como sustituto', async () => {
  clinical.data.edicion.acciones.actualizar = false; clinical.data.edicion.impedimentos.actualizar = ['CLINICA_CITA_NO_PERMITIDA']; mount('/citas/41/consulta?recorrido=consulta');
  await screen.findByText(/El contexto consultado no permite/);
  expect(screen.queryByRole('link', { name: 'Corregir consulta y antecedentes' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Solicitar estudio' })).not.toBeInTheDocument();
});
it.each(['?recorrido=otro', '?recorrido=', '?recorrido=consulta&recorrido=consulta', '?operacion=documentos', '?recorrido=historia&operacion=consulta', '?recorrido=toString', '?recorrido=constructor', '?recorrido=__proto__'])('D4/D7 rechaza recorrido inválido %s sin seleccionar', async search => {
  mount(`/registrar-consulta${search}`); await screen.findByRole('heading', { name: 'Operación no disponible' });
  expect(calls).toHaveLength(0); expect(screen.getByRole('link', { name: 'Volver a una entrada válida' })).toHaveAttribute('href', '/registrar-consulta');
});
it.each(['Paciente', 'Recepcionista'])('D4 %s no recibe selectores clínicos de edición', async role => {
  rol = role; mount('/registrar-consulta'); await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it('D4 entrada directa, atrás/adelante y nuevo router mantienen solo presentación sin escrituras', async () => {
  const view = mount('/citas/41/consulta?recorrido=consulta'); await screen.findByText('Seguimiento ficticio');
  await act(() => view.router.navigate('/citas/41/consulta?recorrido=solicitar-estudio'));
  expect(screen.getByRole('link', { name: 'Solicitar estudio' })).toBeInTheDocument();
  await act(() => view.router.navigate(-1)); expect(screen.getByRole('link', { name: 'Corregir consulta y antecedentes' })).toBeInTheDocument();
  await act(() => view.router.navigate(1)); expect(screen.getByRole('link', { name: 'Solicitar estudio' })).toBeInTheDocument();
  view.unmount(); mount('/citas/41/consulta?recorrido=solicitar-estudio'); await screen.findByText('Seguimiento ficticio');
  await waitFor(() => expect(calls.every(c => c.method === 'get')).toBe(true));
  expect(localStorage.length + sessionStorage.length).toBe(0);
});
