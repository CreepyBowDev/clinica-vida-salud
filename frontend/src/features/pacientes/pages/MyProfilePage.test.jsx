import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api } from '../../../api/api';
import { AuthProvider } from '../../auth/context/AuthContext';
import { crearRutas } from '../../../app/router';
import { consultarSesion } from '../../auth/services/auth.service';
import { actualizarMiPerfil, consultarMiPerfil } from '../services/perfil.service';
import { consultarPaciente } from '../services/paciente.service';

vi.mock('../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../services/perfil.service', () => ({ consultarMiPerfil: vi.fn(), actualizarMiPerfil: vi.fn() }));
vi.mock('../services/paciente.service', () => ({ consultarPaciente: vi.fn(), listarPacientes: vi.fn(), crearPaciente: vi.fn(), actualizarPaciente: vi.fn(), cambiarEstadoPaciente: vi.fn() }));
const version = '2026-10-20T10:00:00.123456Z';
const patient = { id_paciente: '9223372036854775807', codigo_paciente: 'PAC-001', documento_identidad: '01234', nombres: 'Persona', apellidos: 'Ficticia', activo: true, fecha_nacimiento: '1990-01-02', sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, cuenta_vinculada: true, contacto_emergencia: null, recordatorios_habilitados: null, correo_acceso: 'paciente@example.test', actualizado_en: version };
const error = (status, code, details) => ({ response: { status, headers: {}, data: { error: { code, details, message: 'SQL secreto' } } } });
const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; });
beforeEach(() => {
  vi.resetAllMocks(); consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Paciente Ficticio', correo_acceso: patient.correo_acceso, rol: 'Paciente' });
  consultarMiPerfil.mockResolvedValue(patient); actualizarMiPerfil.mockResolvedValue(patient);
});
function mount(path = '/mi-perfil') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const field = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
const submit = () => fireEvent.submit(screen.getByRole('form', { name: 'Actualizar mi perfil' }));

describe('Mi perfil CU-08', () => {
  it.each(['Administrador', 'Recepcionista', 'Médico'])('deniega a %s sin consultar fichas', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol }); mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(consultarMiPerfil).not.toHaveBeenCalled(); expect(consultarPaciente).not.toHaveBeenCalled();
  });
  it('espera restauración, y ofrece navegación exclusiva del titular', async () => {
    let resolve; consultarSesion.mockImplementation(() => new Promise(done => { resolve = done; })); mount('/paciente');
    expect(consultarMiPerfil).not.toHaveBeenCalled(); expect(screen.queryByRole('link', { name: 'Mi perfil' })).not.toBeInTheDocument();
    await act(async () => resolve({ id_usuario: '1', nombre_visible: 'Persona', correo_acceso: patient.correo_acceso, rol: 'Paciente' }));
    const user = userEvent.setup(); await user.click(await screen.findByRole('link', { name: 'Consultar mi perfil' })); await screen.findByLabelText('Teléfono');
    expect(document.title).toBe('Mi perfil | Clínica Vida Salud'); expect(consultarPaciente).not.toHaveBeenCalled();
  });
  it('identidad solo lectura, fecha civil y correos separados; no renderiza clínica ni secretos', async () => {
    consultarMiPerfil.mockResolvedValue({ ...patient, antecedentes: 'CLINICA OCULTA', password: 'SECRETO OCULTO' }); mount(); await screen.findByLabelText('Teléfono');
    expect(screen.getByText('01234')).toBeInTheDocument(); expect(screen.getByText('02/01/1990')).toHaveAttribute('dateTime', '1990-01-02');
    for (const label of ['CI', 'Nombres', 'Apellidos', 'Fecha de nacimiento', 'Sexo', 'Correo de acceso']) expect(screen.queryByLabelText(label)).not.toBeInTheDocument();
    expect(screen.getByText(patient.correo_acceso)).toBeInTheDocument(); expect(screen.getByLabelText('Correo de contacto (opcional)')).toHaveValue('');
    expect(screen.queryByText(/CLINICA OCULTA|SECRETO OCULTO/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /desactivar|activar|código|eliminar/i })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Preferencia de recordatorios')).toHaveValue(''); submit();
    expect(await screen.findByRole('alert')).toHaveTextContent('No hay cambios'); expect(actualizarMiPerfil).not.toHaveBeenCalled();
  });
  it('ficha inactiva permite contacto; guarda solo diferencias y reconsulta sin cambiar correo de acceso', async () => {
    consultarMiPerfil.mockResolvedValueOnce({ ...patient, activo: false }).mockResolvedValue({ ...patient, activo: false, telefono: '71111111', correo_contacto: 'contacto@example.test' });
    mount(); await screen.findByLabelText('Teléfono'); expect(screen.getByText(/no admite nuevas reservas/)).toBeInTheDocument();
    field('Teléfono', '71111111'); field('Correo de contacto (opcional)', 'contacto@example.test'); submit();
    await screen.findByText('Tu perfil se guardó correctamente.'); await waitFor(() => expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'));
    expect(actualizarMiPerfil).toHaveBeenCalledWith({ telefono: '71111111', correo_contacto: 'contacto@example.test', actualizado_en_original: version });
    expect(consultarMiPerfil).toHaveBeenCalledTimes(2); expect(screen.getByText(patient.correo_acceso)).toBeInTheDocument(); expect(consultarPaciente).not.toHaveBeenCalled();
  });
  it('registra emergencia completa y preferencia explícita sin enviar identidad', async () => {
    const user = userEvent.setup(); mount(); await screen.findByLabelText('Teléfono');
    await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' })); submit(); expect(actualizarMiPerfil).not.toHaveBeenCalled();
    field('Nombre del contacto', 'Contacto Ficticio'); field('Parentesco', 'Hermana'); field('Teléfono de emergencia', '72222222');
    await user.selectOptions(screen.getByLabelText('Preferencia de recordatorios'), 'true'); submit();
    await waitFor(() => expect(actualizarMiPerfil).toHaveBeenCalledWith({ contacto_emergencia: { nombre: 'Contacto Ficticio', parentesco: 'Hermana', telefono: '72222222' }, recordatorios_habilitados: true, actualizado_en_original: version }));
  });
  it('retirar emergencia y deshabilitar avisos exige confirmación con opción de revisar', async () => {
    const user = userEvent.setup(); consultarMiPerfil.mockResolvedValue({ ...patient, contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '72222222' }, recordatorios_habilitados: true }); mount();
    await screen.findByLabelText('Teléfono'); await user.click(screen.getByRole('checkbox')); await user.selectOptions(screen.getByLabelText('Preferencia de recordatorios'), 'false'); submit();
    expect(screen.getByRole('status')).toHaveTextContent('Se retirará tu contacto'); expect(screen.getByRole('status')).toHaveTextContent('Se deshabilitarán');
    expect(actualizarMiPerfil).not.toHaveBeenCalled(); await user.click(screen.getByRole('button', { name: 'Volver a revisar cambios' }));
    expect(screen.getByLabelText('Preferencia de recordatorios')).toBeEnabled(); submit(); await user.click(screen.getByRole('button', { name: 'Confirmar cambios de mi perfil' }));
    await waitFor(() => expect(actualizarMiPerfil).toHaveBeenCalledWith({ contacto_emergencia: null, recordatorios_habilitados: false, actualizado_en_original: version }));
  });
  it('409 conserva borrador y preferencia; adopción explícita incluye identidad actual y nueva versión', async () => {
    const user = userEvent.setup(); const current = { ...patient, nombres: 'Nombre Corregido', telefono: '72222222', recordatorios_habilitados: false, actualizado_en: '2026-10-20T10:00:00.123457Z' };
    consultarMiPerfil.mockResolvedValueOnce(patient).mockResolvedValue(current); actualizarMiPerfil.mockRejectedValueOnce(error(409, 'PACIENTE_EDICION_OBSOLETA')).mockResolvedValue(current);
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); await user.selectOptions(screen.getByLabelText('Preferencia de recordatorios'), 'true'); submit();
    await screen.findByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' });
    expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(screen.getByLabelText('Preferencia de recordatorios')).toHaveValue('true'); expect(screen.getByRole('button', { name: 'Guardar mi perfil' })).toBeDisabled();
    submit(); expect(actualizarMiPerfil).toHaveBeenCalledTimes(1); expect(consultarPaciente).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' }));
    expect(screen.getByText('Nombre Corregido Ficticia')).toBeInTheDocument(); expect(screen.getByLabelText('Teléfono')).toHaveValue('72222222'); expect(screen.getByLabelText('Preferencia de recordatorios')).toHaveValue('false');
    field('Teléfono', '73333333'); submit(); await waitFor(() => expect(actualizarMiPerfil).toHaveBeenCalledTimes(2));
    expect(actualizarMiPerfil.mock.calls[1][0]).toEqual({ telefono: '73333333', actualizado_en_original: current.actualizado_en });
  });
  it.each([undefined, 500])('resultado incierto %s bloquea nuevo envío incluso si falla consulta', async status => {
    consultarMiPerfil.mockResolvedValueOnce(patient).mockRejectedValue(new Error('sin conexión')); actualizarMiPerfil.mockRejectedValue(status ? error(status) : new Error('timeout'));
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submit(); await screen.findByRole('button', { name: 'Reintentar consulta actual' });
    expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(screen.getByRole('button', { name: 'Guardar mi perfil' })).toBeDisabled(); submit(); expect(actualizarMiPerfil).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Tu perfil se guardó correctamente.')).not.toBeInTheDocument();
  });
  it('422 anidado conserva borrador, muestra campo y foco sin filtrar cuerpos técnicos', async () => {
    const user = userEvent.setup(); actualizarMiPerfil.mockRejectedValue(error(422, 'VALIDACION_FALLIDA', { 'contacto_emergencia.telefono': ['Revisa el teléfono.'], password: ['SECRETO'] })); mount();
    await screen.findByLabelText('Teléfono'); await user.click(screen.getByRole('checkbox')); field('Nombre del contacto', 'Contacto'); field('Parentesco', 'Hermana'); field('Teléfono de emergencia', '72222222'); submit();
    await screen.findByRole('alert'); await waitFor(() => expect(screen.getByLabelText('Teléfono de emergencia')).toHaveFocus());
    expect(screen.getByLabelText('Teléfono de emergencia')).toHaveValue('72222222'); expect(screen.getByLabelText('Teléfono de emergencia')).toHaveAttribute('aria-invalid', 'true');
    expect(document.body.textContent).not.toMatch(/SQL secreto|SECRETO/);
  });
  it.each([403, 419])('error %s conserva perfil y sesión sin reenvío automático', async status => {
    actualizarMiPerfil.mockRejectedValue(error(status)); mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submit();
    await screen.findByRole('alert'); expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(actualizarMiPerfil).toHaveBeenCalledTimes(1);
  });
  it('429 respeta espera y doble envío no repite PATCH', async () => {
    const failure = error(429); failure.response.headers['retry-after'] = '60'; actualizarMiPerfil.mockRejectedValue(failure); mount();
    await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submit(); submit(); await screen.findByRole('button', { name: 'Espera 60 s' }); submit();
    expect(actualizarMiPerfil).toHaveBeenCalledTimes(1); expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
  });
  it('descarta consulta tardía al salir de Mi perfil y cancela la señal', async () => {
    let resolve; consultarMiPerfil.mockImplementation(() => new Promise(done => { resolve = done; })); const router = mount(); await waitFor(() => expect(consultarMiPerfil).toHaveBeenCalled());
    const signal = consultarMiPerfil.mock.calls[0][0]; await act(() => router.navigate('/inicio')); await act(async () => resolve(patient));
    expect(signal.aborted).toBe(true); expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument();
  });
  it.each([404, 403, 419, 429, 500])('consulta inicial %s ofrece reconsulta sin formulario', async status => {
    consultarMiPerfil.mockRejectedValueOnce(error(status)).mockResolvedValue(patient); const user = userEvent.setup(); mount();
    await screen.findByRole('alert'); expect(screen.queryByRole('form')).not.toBeInTheDocument(); await user.click(screen.getByRole('button', { name: 'Volver a consultar mi perfil' })); await screen.findByLabelText('Teléfono');
  });
  it('integra consulta → CSRF/PATCH → reconsulta con Axios real y payload propio sin ID', async () => {
    const actual = await vi.importActual('../services/perfil.service'); consultarMiPerfil.mockImplementation(actual.consultarMiPerfil); actualizarMiPerfil.mockImplementation(actual.actualizarMiPerfil);
    let current = { ...patient }; const calls = [];
    api.defaults.adapter = async config => { calls.push(config); if (config.method === 'patch') { const data = JSON.parse(config.data); current = { ...current, ...data, actualizado_en: '2026-10-20T10:00:00.123457Z' }; } return { config, status: 200, headers: {}, data: { data: current } }; };
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submit(); await screen.findByText('Tu perfil se guardó correctamente.');
    await waitFor(() => expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'));
    expect(calls.map(c => [c.method, c.url])).toEqual([['get', '/mi-perfil'], ['get', 'http://localhost:8000/sanctum/csrf-cookie'], ['patch', '/mi-perfil'], ['get', '/mi-perfil']]);
    expect(JSON.parse(calls[2].data)).toEqual({ telefono: '71111111', actualizado_en_original: version });
    expect(localStorage.length + sessionStorage.length).toBe(0);
  });
  it('401 durante PATCH real retira formulario y datos personales', async () => {
    const actual = await vi.importActual('../services/perfil.service'); actualizarMiPerfil.mockImplementation(actual.actualizarMiPerfil);
    api.defaults.adapter = async config => { if (config.method === 'patch') throw new AxiosError('sesión finalizada', undefined, config, null, { status: 401, headers: {}, data: {} }); return { config, status: 204, headers: {}, data: null }; };
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submit();
    await screen.findByRole('form', { name: 'Iniciar sesión' }); expect(screen.queryByText('Persona Ficticia')).not.toBeInTheDocument(); expect(screen.queryByRole('form', { name: 'Actualizar mi perfil' })).not.toBeInTheDocument();
  });
});
