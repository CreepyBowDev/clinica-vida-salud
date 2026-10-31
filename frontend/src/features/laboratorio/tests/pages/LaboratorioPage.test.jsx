import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { ROLES, destinoSeguro } from '../../../../constants/roles';
import { avanzarSesion } from '../../../../api/api';
import LaboratorioPage from '../../pages/LaboratorioPage';
import SolicitudLaboratorioPage from '../../pages/SolicitudLaboratorioPage';
import { consultarSolicitud, listarSolicitudes } from '../../services/laboratorio.service';
import { usePatientQuery } from '../../../pacientes/hooks/usePatientQuery';
import { disponible, solicitud } from '../utils/laboratorioFixtures';

const auth = vi.hoisted(() => ({ value: {} }));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => auth.value }));
vi.mock('../../services/laboratorio.service', async original => ({ ...await original(), consultarSolicitud: vi.fn(), listarSolicitudes: vi.fn(),
  consultarVersionesResultado: vi.fn().mockResolvedValue({ versiones: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) }));
vi.mock('../../../pacientes/hooks/usePatientQuery', () => ({ usePatientQuery: vi.fn() }));
function mount(path = '/laboratorio') {
  const router = createMemoryRouter([
    { path: '/laboratorio', element: <LaboratorioPage /> },
    { path: '/laboratorio/pacientes/:id', element: <LaboratorioPage contexto="paciente" /> },
    { path: '/laboratorio/consultas/:id', element: <LaboratorioPage contexto="consulta" /> },
    { path: '/laboratorio/solicitudes/:id', element: <SolicitudLaboratorioPage /> },
  ], { initialEntries: [path] });
  return { ...render(<RouterProvider router={router} />), router };
}
const meta = { current_page: 1, per_page: 20, last_page: 1, total: 1 };
beforeEach(() => {
  vi.clearAllMocks(); auth.value = { status: 'authenticated', usuario: { id_usuario: '1', rol: ROLES.PACIENTE } };
  listarSolicitudes.mockResolvedValue({ solicitudes: [solicitud()], meta });
  consultarSolicitud.mockResolvedValue(solicitud());
  usePatientQuery.mockReturnValue({ data: { data: [{ ...solicitud().paciente, activo: false, documento_identidad: null }], meta }, loading: false, reload: vi.fn() });
});
it('Paciente lista propios con filtros públicos y enlace a detalle, sin gestiones', async () => {
  mount(); await screen.findByText('Hemograma de prueba');
  expect(listarSolicitudes.mock.calls[0][0]).toMatchObject({ rol: ROLES.PACIENTE, page: 1 });
  expect(screen.queryByRole('option', { name: 'En revisión' })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Ver solicitud/ })).toHaveAttribute('href', '/laboratorio/solicitudes/71');
  expect(screen.queryByText(/cargar documento|autorizar publicación|solicitar estudio/i)).not.toBeInTheDocument();
});
it('Admin selecciona paciente sin consultar bandeja global ni registrar ficha', async () => {
  auth.value.usuario.rol = ROLES.ADMINISTRADOR; mount();
  expect(await screen.findByRole('heading', { name: /Seleccionar paciente/ })).toBeVisible();
  expect(listarSolicitudes).not.toHaveBeenCalled();
  expect(screen.getByRole('link', { name: 'Ver estudios de Persona Ficticia' })).toHaveAttribute('href', '/laboratorio/pacientes/21');
  expect(screen.queryByRole('link', { name: 'Registrar paciente' })).not.toBeInTheDocument();
  act(() => avanzarSesion()); expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
});
it('Médico consulta solicitudes propias sin buscador administrativo', async () => {
  auth.value.usuario.rol = ROLES.MEDICO; mount(); await screen.findByText('Hemograma de prueba');
  expect(usePatientQuery).not.toHaveBeenCalled(); expect(screen.queryByLabelText('CI')).not.toBeInTheDocument(); expect(screen.getByRole('option', { name: 'En revisión' })).toBeInTheDocument();
});
it('contexto de paciente y consulta son diferentes', async () => {
  auth.value.usuario.rol = ROLES.ADMINISTRADOR;
  const view = mount('/laboratorio/pacientes/21'); await screen.findByText('Hemograma de prueba');
  expect(listarSolicitudes.mock.calls[0][0]).toMatchObject({ paciente: '21' });
  await act(async () => view.router.navigate('/laboratorio/consultas/44'));
  await waitFor(() => expect(listarSolicitudes.mock.calls.at(-1)[0]).toMatchObject({ consulta: '44' }));
});
it('fechas inválidas muestran ayuda y no disparan HTTP; limpiar reinicia filtros', async () => {
  mount(); await screen.findByText('Hemograma de prueba'); const user = userEvent.setup();
  await user.type(screen.getByLabelText('Desde'), '2026-10-30'); await user.type(screen.getByLabelText('Hasta'), '2026-10-01');
  await user.click(screen.getByRole('button', { name: 'Consultar' })); expect(screen.getByRole('alert')).toHaveTextContent(/fecha final/); expect(listarSolicitudes).toHaveBeenCalledTimes(1);
  await user.click(screen.getByRole('button', { name: 'Limpiar filtros' })); expect(screen.getByLabelText('Desde')).toHaveValue('');
});
it('vacío es legítimo y mantiene paginación', async () => {
  listarSolicitudes.mockResolvedValue({ solicitudes: [], meta: { ...meta, total: 0 } }); mount();
  expect(await screen.findByText('No hay estudios para estos filtros')).toBeVisible(); expect(screen.getByRole('navigation', { name: 'Paginación de estudios' })).toBeVisible();
});
it.each([401, 403, 404, 409, 419, 422, 429, 500, undefined])('error %s seguro y sin datos anteriores', async status => {
  listarSolicitudes.mockRejectedValue({ response: status ? { status, data: { error: { message: 'SQL PRIVADO' } } } : undefined }); mount();
  await waitFor(() => expect(screen.getByRole('alert')).toBeVisible()); expect(screen.queryByText('SQL PRIVADO')).not.toBeInTheDocument(); expect(screen.queryByText('Hemograma de prueba')).not.toBeInTheDocument();
});
it('Paciente pendiente no ve descarga ni internos', async () => {
  consultarSolicitud.mockResolvedValue(solicitud({ ultima_revision: { motivo: 'PRIVADO' }, creada_por: '9' })); mount('/laboratorio/solicitudes/71');
  await screen.findByRole('heading', { name: 'Hemograma de prueba' });
  expect(screen.queryByRole('button', { name: 'Descargar resultado' })).not.toBeInTheDocument(); expect(screen.queryByText(/PRIVADO|Usuario 9/)).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Ver estudios de esta consulta' })).toHaveAttribute('href', '/laboratorio/consultas/44');
});
it('Paciente disponible muestra versión, fecha y descarga, sin revisión privada', async () => {
  consultarSolicitud.mockResolvedValue(disponible()); mount('/laboratorio/solicitudes/71');
  await screen.findByRole('button', { name: 'Descargar resultado' }); expect(screen.getByText('Versión 1')).toBeVisible(); expect(screen.queryByRole('heading', { name: /Última revisión/ })).not.toBeInTheDocument();
});
it('interno muestra vigente en revisión y autoría sin adelantar formularios', async () => {
  auth.value.usuario.rol = ROLES.MEDICO;
  consultarSolicitud.mockResolvedValue({ ...solicitud(), estado: 'En revisión', documento: { id_version_resultado: '81', numero_version: 2 }, ultima_revision: { decision: 'Devolver', revisada_en: '2026-10-30T14:00:00.000000Z', revisada_por: null, motivo: 'Documento ficticio ilegible' } });
  mount('/laboratorio/solicitudes/71'); await screen.findByRole('button', { name: 'Descargar resultado' });
  expect(screen.getByText('Versión 2')).toBeVisible(); expect(screen.getByText('Documento ficticio ilegible')).toBeVisible();
  expect(screen.queryByRole('button', { name: /^(autorizar|devolver|cargar)/i })).not.toBeInTheDocument();
});
it('IDs inválidos no consultan servicios y destinos de login son acotados por rol', () => {
  mount('/laboratorio/solicitudes/01'); expect(screen.getByRole('heading', { name: 'Solicitud no disponible' })).toBeVisible(); expect(consultarSolicitud).not.toHaveBeenCalled();
  for (const rol of [ROLES.ADMINISTRADOR, ROLES.MEDICO, ROLES.PACIENTE]) {
    expect(destinoSeguro('/laboratorio/solicitudes/9223372036854775807', rol)).toBe('/laboratorio/solicitudes/9223372036854775807');
    expect(destinoSeguro('/laboratorio/solicitar', rol)).toBe(rol === ROLES.PACIENTE ? '/inicio' : '/laboratorio/solicitar');
    for (const path of ['/laboratorio/solicitudes/01', '/laboratorio/solicitudes/9223372036854775808', '/laboratorio/solicitudes/71?secreto=1']) expect(destinoSeguro(path, rol)).toBe('/inicio');
  }
  expect(destinoSeguro('/laboratorio/pacientes/21', ROLES.PACIENTE)).toBe('/inicio'); expect(destinoSeguro('/laboratorio', ROLES.RECEPCIONISTA)).toBe('/inicio');
});
