import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api } from '../../../api/api';
import { AuthProvider } from '../../auth/context/AuthContext';
import { crearRutas } from '../../../app/router';
import { consultarSesion } from '../../auth/services/auth.service';
import { actualizarPaciente, cambiarEstadoPaciente, consultarPaciente } from '../services/paciente.service';
import { emitirCodigoActivacion } from '../services/activation.service';

vi.mock('../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../services/paciente.service', () => ({ consultarPaciente: vi.fn(), listarPacientes: vi.fn(), crearPaciente: vi.fn(), actualizarPaciente: vi.fn(), cambiarEstadoPaciente: vi.fn() }));
vi.mock('../services/activation.service', () => ({ emitirCodigoActivacion: vi.fn() }));
const id = '9223372036854775807';
const version = '2026-10-20T10:00:00.123456Z';
const patient = { id_paciente: id, codigo_paciente: 'PAC-001', documento_identidad: '1234', nombres: 'Persona', apellidos: 'Ficticia', activo: true, fecha_nacimiento: '1990-01-02', sexo: null, telefono: '70000000', correo_contacto: null, direccion: null, cuenta_vinculada: false, contacto_emergencia: null, actualizado_en: version };
const error = (status, code, details) => ({ response: { status, headers: {}, data: { error: { code, details, message: 'SQL secreto' } } } });
function mount(suffix = '/editar') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [`/recepcion/pacientes/${id}${suffix}`] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const field = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
const submitEdit = () => fireEvent.submit(screen.getByRole('form', { name: 'Editar ficha administrativa' }));
const originalAdapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = originalAdapter; });
beforeEach(() => {
  vi.resetAllMocks(); consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Recepción Ficticia', correo_acceso: 'recepcion@example.test', rol: 'Recepcionista' });
  consultarPaciente.mockResolvedValue(patient); actualizarPaciente.mockResolvedValue(patient); cambiarEstadoPaciente.mockResolvedValue(patient);
  emitirCodigoActivacion.mockResolvedValue({ id_paciente: id, codigo_activacion: 'Ab1234567890', expira_en: '2026-10-21T20:00:00Z' });
});

describe('edición administrativa CU-07', () => {
  it.each(['Administrador', 'Médico', 'Paciente'])('deniega edición y activación a %s sin consultar ni escribir', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol }); const router = mount();
    await screen.findByRole('heading', { name: 'Acceso no permitido' }); await act(() => router.navigate(`/recepcion/pacientes/${id}/activacion`));
    expect(consultarPaciente).not.toHaveBeenCalled(); expect(actualizarPaciente).not.toHaveBeenCalled(); expect(emitirCodigoActivacion).not.toHaveBeenCalled();
  });
  it('cambia solo contacto, conserva versión y reconsulta detalle al guardar', async () => {
    const router = mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit();
    await screen.findByRole('heading', { name: 'Ficha administrativa' });
    expect(actualizarPaciente).toHaveBeenCalledWith(id, { telefono: '71111111', actualizado_en_original: version });
    await waitFor(() => expect(consultarPaciente).toHaveBeenCalledTimes(2)); expect(router.state.location.pathname).toBe(`/recepcion/pacientes/${id}`);
    expect(await screen.findByText('Cambios administrativos guardados. Consulta los datos actuales de la ficha.')).toBeInTheDocument();
  });
  it('incorporar CI exige identidad/motivo y confirmación, conserva ID y muestra invalidaciones', async () => {
    const user = userEvent.setup(); consultarPaciente.mockResolvedValue({ ...patient, documento_identidad: null, cuenta_vinculada: true }); mount();
    await screen.findByLabelText('CI (opcional)'); field('CI (opcional)', '5678'); submitEdit();
    expect(actualizarPaciente).not.toHaveBeenCalled(); expect(screen.getByText(/Cambiar el CI invalida/)).toBeInTheDocument();
    field('Motivo de la corrección', 'Documento comprobado presencialmente'); await user.click(screen.getByRole('checkbox', { name: /He comprobado presencialmente/ })); submitEdit();
    expect(await screen.findByRole('button', { name: 'Confirmar cambios administrativos' })).toBeInTheDocument(); expect(actualizarPaciente).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirmar cambios administrativos' }));
    await waitFor(() => expect(actualizarPaciente).toHaveBeenCalledTimes(1));
    expect(actualizarPaciente.mock.calls[0]).toEqual([id, { documento_identidad: '5678', motivo: 'Documento comprobado presencialmente', identidad_verificada: true, actualizado_en_original: version }]);
  });
  it('no retira CI informado ni envía no-op por CI histórico normalizado', async () => {
    consultarPaciente.mockResolvedValue({ ...patient, documento_identidad: ' 1234 ' }); mount(); await screen.findByLabelText('CI'); field('CI', ''); submitEdit();
    expect(screen.getByLabelText('CI')).toHaveAttribute('aria-invalid', 'true'); expect(actualizarPaciente).not.toHaveBeenCalled();
    field('CI', '1234'); submitEdit(); expect(await screen.findByRole('alert')).toHaveTextContent('No hay cambios');
    expect(screen.queryByLabelText('Motivo de la corrección')).not.toBeInTheDocument(); expect(actualizarPaciente).not.toHaveBeenCalled();
  });
  it('retirar emergencia requiere confirmación y envía NULL sin identidad', async () => {
    const user = userEvent.setup(); consultarPaciente.mockResolvedValue({ ...patient, contacto_emergencia: { nombre: 'Contacto', parentesco: 'Hermana', telefono: '71111111' } }); mount();
    await screen.findByLabelText('Teléfono'); await user.click(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' })); submitEdit();
    expect(screen.getByRole('status')).toHaveTextContent('Se retirará el contacto'); expect(actualizarPaciente).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirmar cambios administrativos' }));
    await waitFor(() => expect(actualizarPaciente).toHaveBeenCalledWith(id, { contacto_emergencia: null, actualizado_en_original: version }));
  });
  it('409 obsoleto conserva borrador y versión, consulta actual y solo descarta con decisión explícita', async () => {
    const user = userEvent.setup(); const latest = { ...patient, telefono: '72222222', actualizado_en: '2026-10-20T10:00:00.123457Z' };
    consultarPaciente.mockResolvedValueOnce(patient).mockResolvedValue(latest); actualizarPaciente.mockRejectedValueOnce(error(409, 'PACIENTE_EDICION_OBSOLETA')).mockResolvedValue(latest);
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit();
    await screen.findByRole('heading', { name: 'Versión actual de la ficha' }); await screen.findByText('72222222');
    expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeDisabled();
    submitEdit(); expect(actualizarPaciente).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' })); expect(screen.getByLabelText('Teléfono')).toHaveValue('72222222');
    field('Teléfono', '73333333'); submitEdit(); await waitFor(() => expect(actualizarPaciente).toHaveBeenCalledTimes(2));
    expect(actualizarPaciente.mock.calls[1][1].actualizado_en_original).toBe(latest.actualizado_en);
  });
  it('red incierta conserva borrador y bloquea guardar si falla la reconsulta', async () => {
    consultarPaciente.mockResolvedValueOnce(patient).mockRejectedValue(new Error('sin conexión')); actualizarPaciente.mockRejectedValue(new Error('sin conexión'));
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit(); await screen.findByRole('button', { name: 'Reintentar consulta actual' });
    expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeDisabled();
    expect(actualizarPaciente).toHaveBeenCalledTimes(1);
  });
  it.each([409, 419, 422, 403])('error %s seguro conserva datos y no reenvía', async status => {
    actualizarPaciente.mockRejectedValue(error(status, 'PACIENTE_DOCUMENTO_NO_DISPONIBLE', { telefono: ['Revisa el teléfono.'] })); mount();
    await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit(); expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL secreto');
    expect(screen.getByLabelText('Teléfono')).toHaveValue('71111111'); expect(actualizarPaciente).toHaveBeenCalledTimes(1);
  });
  it('bloquea edición y envíos simultáneos durante PATCH', async () => {
    let resolve; actualizarPaciente.mockImplementation(() => new Promise(done => { resolve = done; })); mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit(); submitEdit();
    expect(actualizarPaciente).toHaveBeenCalledTimes(1); expect(screen.getByLabelText('Teléfono')).toBeDisabled(); await act(async () => resolve(patient));
  });
  it('429 de edición conserva borrador y respeta la espera del servidor', async () => {
    const failure = error(429); failure.response.headers['retry-after'] = '60'; actualizarPaciente.mockRejectedValue(failure); mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit();
    await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled(); submitEdit(); expect(actualizarPaciente).toHaveBeenCalledTimes(1);
  });
  it.each(['/editar', '/activacion'])('ID inválido en %s no consulta API', async suffix => {
    const router = mount(); await act(() => router.navigate(`/recepcion/pacientes/9223372036854775808${suffix}`));
    await screen.findByRole('heading', { name: 'Ficha no disponible' });
    expect(consultarPaciente.mock.calls.every(call => call[0] !== '9223372036854775808')).toBe(true);
  });
  it('integra PATCH obsoleto → revisión explícita → PATCH con nueva versión → detalle con Axios real', async () => {
    const actual = await vi.importActual('../services/paciente.service'); consultarPaciente.mockImplementation(actual.consultarPaciente); actualizarPaciente.mockImplementation(actual.actualizarPaciente);
    const user = userEvent.setup(); let current = { ...patient }, conflict = true; const writes = [];
    api.defaults.adapter = async config => {
      if (config.method === 'patch') {
        const data = JSON.parse(config.data); writes.push(data);
        if (conflict) { conflict = false; current = { ...current, telefono: '72222222', actualizado_en: '2026-10-20T10:00:00.123457Z' }; throw new AxiosError('conflicto', undefined, config, null, { status: 409, headers: {}, data: { error: { code: 'PACIENTE_EDICION_OBSOLETA' } } }); }
        expect(data.actualizado_en_original).toBe(current.actualizado_en); current = { ...current, telefono: data.telefono, actualizado_en: '2026-10-20T10:00:00.123458Z' };
      }
      return { config, status: 200, headers: {}, data: { data: current } };
    };
    mount(); await screen.findByLabelText('Teléfono'); field('Teléfono', '71111111'); submitEdit();
    await user.click(await screen.findByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' }));
    field('Teléfono', '73333333'); submitEdit(); await screen.findByRole('heading', { name: 'Persona Ficticia' });
    expect(screen.getByText('73333333')).toBeInTheDocument();
    expect(writes).toEqual([{ telefono: '71111111', actualizado_en_original: version }, { telefono: '73333333', actualizado_en_original: '2026-10-20T10:00:00.123457Z' }]);
  });
});

describe('estado de ficha independiente de cuenta y citas', () => {
  it.each([true, false])('cambia estado inicial %s con motivo/confirmación y refresca', async activo => {
    const user = userEvent.setup(); consultarPaciente.mockResolvedValueOnce({ ...patient, activo }).mockResolvedValue({ ...patient, activo: !activo });
    mount(''); await user.click(await screen.findByRole('button', { name: activo ? 'Desactivar ficha' : 'Reactivar ficha' }));
    const form = screen.getByRole('form', { name: 'Cambiar estado de ficha' }); fireEvent.submit(form); expect(cambiarEstadoPaciente).not.toHaveBeenCalled();
    field('Motivo del cambio de estado', 'Revisión administrativa'); fireEvent.submit(form); expect(cambiarEstadoPaciente).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: `Confirmar: ${activo ? 'desactivar' : 'reactivar'} ficha` }));
    await screen.findByRole('button', { name: activo ? 'Reactivar ficha' : 'Desactivar ficha' });
    expect(cambiarEstadoPaciente).toHaveBeenCalledWith(id, { activo: !activo, motivo: 'Revisión administrativa', actualizado_en_original: version });
    expect(actualizarPaciente).not.toHaveBeenCalled(); expect(screen.getByRole('status')).toHaveTextContent('cuenta web y las citas existentes se conservan');
  });
  it('conflicto de estado conserva motivo hasta aceptar la versión actual y reinicia confirmación', async () => {
    const user = userEvent.setup(); consultarPaciente.mockResolvedValueOnce(patient).mockResolvedValue({ ...patient, activo: false, actualizado_en: '2026-10-20T10:00:00.123457Z' });
    cambiarEstadoPaciente.mockRejectedValue(error(409, 'PACIENTE_EDICION_OBSOLETA')); mount('');
    await user.click(await screen.findByRole('button', { name: 'Desactivar ficha' })); field('Motivo del cambio de estado', 'Motivo previo');
    fireEvent.submit(screen.getByRole('form', { name: 'Cambiar estado de ficha' })); await user.click(screen.getByRole('button', { name: 'Confirmar: desactivar ficha' }));
    await user.click(await screen.findByRole('button', { name: 'Usar estado actual y reiniciar confirmación' }));
    expect(screen.getByLabelText('Motivo del cambio de estado')).toHaveValue(''); expect(screen.getByRole('button', { name: 'Reactivar ficha' })).toBeInTheDocument();
    expect(cambiarEstadoPaciente).toHaveBeenCalledTimes(1);
  });
  it('fallo incierto de estado conserva motivo, bloquea y consulta sin reenviar', async () => {
    const user = userEvent.setup(); cambiarEstadoPaciente.mockRejectedValue(new Error('sin conexión')); mount('');
    await user.click(await screen.findByRole('button', { name: 'Desactivar ficha' })); field('Motivo del cambio de estado', 'Motivo escrito');
    fireEvent.submit(screen.getByRole('form', { name: 'Cambiar estado de ficha' })); await user.click(screen.getByRole('button', { name: 'Confirmar: desactivar ficha' }));
    await screen.findByRole('button', { name: 'Usar estado actual y reiniciar confirmación' });
    expect(screen.getByLabelText('Motivo del cambio de estado')).toHaveValue('Motivo escrito'); expect(screen.getByRole('button', { name: 'Desactivar ficha' })).toBeDisabled();
    expect(cambiarEstadoPaciente).toHaveBeenCalledTimes(1); expect(consultarPaciente).toHaveBeenCalledTimes(2);
  });
});

describe('activación desde ficha seleccionada', () => {
  it('enlaza desde detalle, fija ID y reutiliza emisión presencial incluso con ficha inactiva', async () => {
    const user = userEvent.setup(); consultarPaciente.mockResolvedValue({ ...patient, activo: false }); const router = mount('');
    await user.click(await screen.findByRole('link', { name: 'Emitir código de activación' })); await screen.findByRole('form', { name: 'Emitir código de activación' });
    expect(screen.getByLabelText('Identificador de la ficha')).toHaveValue(id); expect(screen.getByLabelText('Identificador de la ficha')).toHaveAttribute('readonly');
    expect(screen.getByText(/CI 1234/)).toBeInTheDocument();
    await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar emisión' }));
    expect(emitirCodigoActivacion).not.toHaveBeenCalled(); await user.click(screen.getByRole('button', { name: 'Confirmar emisión presencial' }));
    await screen.findByRole('heading', { name: 'Código de activación emitido' }); expect(emitirCodigoActivacion).toHaveBeenCalledWith(id, true);
    await act(() => router.navigate(`/recepcion/pacientes/${id}`)); expect(screen.queryByText('Ab1234567890')).not.toBeInTheDocument();
  });
  it.each([{ documento_identidad: null }, { cuenta_vinculada: true }])('rechaza URL directa de ficha no elegible %j', async override => {
    consultarPaciente.mockResolvedValue({ ...patient, ...override }); mount('/activacion');
    await screen.findByRole('heading', { name: 'La ficha no admite esta emisión' }); expect(emitirCodigoActivacion).not.toHaveBeenCalled();
  });
  it('rechazo del servidor tras cambio concurrente no muestra código ni éxito', async () => {
    const user = userEvent.setup(); emitirCodigoActivacion.mockRejectedValue(error(409, 'ACTIVACION_NO_PERMITIDA')); mount('/activacion');
    await user.click(await screen.findByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar emisión' })); await user.click(screen.getByRole('button', { name: 'Confirmar emisión presencial' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('no admite esta emisión'); expect(screen.queryByRole('heading', { name: 'Código de activación emitido' })).not.toBeInTheDocument();
  });
});
