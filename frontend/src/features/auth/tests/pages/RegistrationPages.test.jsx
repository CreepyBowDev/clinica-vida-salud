import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../../context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion, cerrarSesion, iniciarSesion } from '../../services/auth.service';
import { registrarPaciente, vincularPaciente, reenviarCodigoRegistro, verificarRegistro } from '../../services/registration.service';
import { emitirCodigoActivacion } from '../../../pacientes/services/activation.service';

vi.mock('../../services/auth.service', () => ({ consultarSesion: vi.fn(), cerrarSesion: vi.fn(), iniciarSesion: vi.fn() }));
vi.mock('../../services/registration.service', () => ({ registrarPaciente: vi.fn(), vincularPaciente: vi.fn(), reenviarCodigoRegistro: vi.fn(), verificarRegistro: vi.fn() }));
vi.mock('../../../pacientes/services/activation.service', () => ({ emitirCodigoActivacion: vi.fn() }));
const password = 'Frase ficticia larga 123!';
const error = (status, code = '', details = {}, headers = {}) => ({ response: { status, headers, data: { error: { code, details, message: 'SQL interno secreto' } } } });
const userData = rol => ({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol });
const receipt = { id_paciente: '17', codigo_activacion: 'Ab1234567890', expira_en: '2026-10-21T20:00:00Z' };
function mount(path) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
  return router;
}
function field(label, value) { fireEvent.change(screen.getByLabelText(label), { target: { value } }); }
function fill(linking = false) {
  field('CI', '000-A lp'); field('Correo de acceso', 'paciente@example.test'); field('Contraseña', password); field('Confirmar contraseña', password);
  if (linking) field('Código de Recepción', 'Ab1234567890');
  else { field('Nombres', 'Paciente'); field('Apellidos', 'Ficticio'); field('Fecha de nacimiento', '1990-01-01'); field('Teléfono', '70000000'); }
}
beforeEach(() => { vi.resetAllMocks(); consultarSesion.mockRejectedValue(error(401)); });
afterEach(() => vi.useRealTimers());

describe('registro y vinculación', () => {
  it.each([false, true])('agrupa el registro %s sin volver interactivos los pasos ni mezclar códigos', async linking => {
    mount(linking ? '/vincular-ficha' : '/registro'); await screen.findByLabelText('CI');
    expect(screen.getByRole('group', { name: linking ? 'Tu ficha en la clínica' : 'Datos personales' })).toContainElement(screen.getByLabelText('CI'));
    expect(screen.getByRole('group', { name: 'Acceso al portal' })).toContainElement(screen.getByLabelText('Contraseña'));
    expect(screen.queryByRole('group', { name: 'Datos de contacto' }) !== null).toBe(!linking);
    const progress = screen.getByRole('list', { name: 'Pasos del registro web' });
    expect(progress.querySelectorAll('[aria-current="step"]')).toHaveLength(1);
    expect(progress.querySelector('[aria-current="step"]')).toHaveTextContent('Datos y acceso');
    expect(progress.querySelector('a,button')).toBeNull(); expect(registrarPaciente).not.toHaveBeenCalled(); expect(vincularPaciente).not.toHaveBeenCalled();
  });
  it.each([false, true])('solicitud %s continúa con mensaje neutro y sin persistencia', async linking => {
    const service = linking ? vincularPaciente : registrarPaciente;
    service.mockResolvedValue(undefined);
    mount(linking ? '/vincular-ficha' : '/registro');
    await screen.findByLabelText('CI'); fill(linking);
    expect(screen.queryByLabelText(linking ? 'Nombres' : 'Código de Recepción')).not.toBeInTheDocument();
    fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Verifica tu correo' });
    expect(screen.getByRole('status')).toHaveTextContent('Esta respuesta no confirma el envío');
    expect(service).toHaveBeenCalledTimes(1);
    expect(localStorage.length + sessionStorage.length).toBe(0);
    expect(iniciarSesion).not.toHaveBeenCalled();
  });
  it('CI obligatorio, sin patrón civil inventado ni omisión', async () => {
    mount('/registro'); await screen.findByLabelText('CI'); fill(); field('CI', '   ');
    fireEvent.submit(screen.getByRole('form'));
    await waitFor(() => expect(screen.getByLabelText('CI')).toHaveFocus());
    expect(registrarPaciente).not.toHaveBeenCalled();
    expect(screen.getByText(/El CI es obligatorio para el registro web y/)).toBeInTheDocument();
  });
  it('422 conserva datos corregibles y limpia los secretos de vinculación', async () => {
    vincularPaciente.mockRejectedValue(error(422, 'VALIDACION_ERROR', { correo_acceso: ['Introduce un correo válido.'] }));
    mount('/vincular-ficha'); await screen.findByLabelText('CI'); fill(true); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('alert');
    expect(screen.getByLabelText('CI')).toHaveValue('000-A lp');
    expect(screen.getByLabelText('Código de Recepción')).toHaveValue('');
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
    expect(screen.getByLabelText('Confirmar contraseña')).toHaveValue('');
    expect(screen.getByRole('alert')).not.toHaveTextContent('SQL interno');
  });
  it('no repite registro mientras responde', async () => {
    let resolve; registrarPaciente.mockImplementation(() => new Promise(done => { resolve = done; }));
    mount('/registro'); await screen.findByLabelText('CI'); fill();
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    expect(registrarPaciente).toHaveBeenCalledTimes(1);
    await act(async () => resolve());
  });
  it('respuesta tardía de inicio no navega desde otra pantalla ni restaura datos', async () => {
    let resolve; registrarPaciente.mockImplementation(() => new Promise(done => { resolve = done; }));
    const router = mount('/registro'); await screen.findByLabelText('CI'); fill(); fireEvent.submit(screen.getByRole('form'));
    await act(async () => router.navigate('/login'));
    await act(async () => resolve());
    expect(router.state.location.pathname).toBe('/login');
    expect(screen.queryByLabelText('CI')).not.toBeInTheDocument();
  });
  it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente', 'restricted'])('sesión %s debe cerrarse antes del registro', async rol => {
    consultarSesion.mockResolvedValue(rol === 'restricted' ? { acceso: 'cambio_clave', cambio_clave_obligatorio: true } : userData(rol));
    cerrarSesion.mockResolvedValue(undefined);
    const user = userEvent.setup(); mount('/registro');
    await screen.findByRole('heading', { name: 'Ya hay una sesión abierta' });
    expect(screen.queryByLabelText('CI')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await screen.findByLabelText('CI');
    expect(registrarPaciente).not.toHaveBeenCalled();
  });
});

describe('verificación compartida', () => {
  it('204 activa, limpia código y exige login sin autenticación automática', async () => {
    verificarRegistro.mockResolvedValue(undefined);
    mount('/verificar-registro'); await screen.findByLabelText('Código del correo');
    expect(screen.getByRole('list', { name: 'Pasos del registro web' }).querySelector('[aria-current="step"]')).toHaveTextContent('Verificar correo');
    field('Código del correo', '001234'); fireEvent.submit(screen.getByRole('form'));
    await screen.findByRole('heading', { name: 'Tu cuenta está activada' });
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Tu cuenta está activada' })).toHaveFocus());
    expect(verificarRegistro).toHaveBeenCalledWith({ codigo: '001234' });
    expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toHaveAttribute('href', '/login');
    expect(iniciarSesion).not.toHaveBeenCalled();
    expect(screen.queryByLabelText('Código del correo')).not.toBeInTheDocument();
    expect(screen.queryByRole('list', { name: 'Pasos del registro web' })).not.toBeInTheDocument();
  });
  it('código inválido/vencido/agotado se presenta igual sin deducir existencia', async () => {
    verificarRegistro.mockRejectedValue(error(422, 'REGISTRO_VERIFICACION_INVALIDA'));
    mount('/verificar-registro'); await screen.findByLabelText('Código del correo');
    field('Código del correo', '001234'); fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('No se pudo verificar el código o el proceso');
    expect(screen.getByLabelText('Código del correo')).toHaveValue('');
    expect(screen.queryByText('Tu cuenta está activada')).not.toBeInTheDocument();
  });
  it('reenvío 202 mantiene mensaje genérico sin selector de proceso', async () => {
    const user = userEvent.setup(); mount('/verificar-registro');
    await user.click(await screen.findByRole('button', { name: 'Solicitar otro código de correo' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Esta respuesta no confirma el envío');
    expect(reenviarCodigoRegistro).toHaveBeenCalledWith();
    expect(screen.queryByLabelText('Correo de acceso')).not.toBeInTheDocument();
  });
  it.each([419, 500, undefined])('fallo %s no anuncia activación ni reintenta', async status => {
    verificarRegistro.mockRejectedValue(status ? error(status) : new Error('timeout'));
    mount('/verificar-registro'); await screen.findByLabelText('Código del correo'); field('Código del correo', '001234'); fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).not.toHaveTextContent('SQL interno');
    expect(screen.getByLabelText('Código del correo')).toHaveValue('');
    expect(verificarRegistro).toHaveBeenCalledTimes(1);
  });
  it('429 bloquea verificación y reenvío durante Retry-After', async () => {
    verificarRegistro.mockRejectedValue(error(429, '', {}, { 'retry-after': '60' }));
    mount('/verificar-registro'); await screen.findByLabelText('Código del correo');
    vi.useFakeTimers(); field('Código del correo', '001234');
    await act(async () => fireEvent.submit(screen.getByRole('form')));
    expect(screen.getByRole('button', { name: 'Espera 60 s' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Solicitar otro código de correo' })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(60000));
    expect(screen.getByRole('button', { name: 'Verificar y activar mi cuenta' })).toBeEnabled();
    expect(verificarRegistro).toHaveBeenCalledTimes(1);
  });
});

describe('emisión presencial de Recepción', () => {
  beforeEach(() => consultarSesion.mockResolvedValue(userData('Recepcionista')));
  it('confirma reemplazo y entrega una vez sin persistir el código', async () => {
    emitirCodigoActivacion.mockResolvedValue(receipt);
    const user = userEvent.setup(); mount('/recepcion/activacion-web');
    await screen.findByLabelText('Identificador de la ficha'); field('Identificador de la ficha', '17');
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Revisar emisión' }));
    expect(screen.getByRole('status')).toHaveTextContent('Se invalidarán el código anterior');
    expect(emitirCodigoActivacion).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirmar emisión presencial' }));
    expect(await screen.findByRole('heading', { name: 'Código de activación emitido' })).toHaveFocus();
    expect(screen.getByLabelText('Código de activación para entrega presencial')).toHaveTextContent(receipt.codigo_activacion);
    expect(emitirCodigoActivacion).toHaveBeenCalledWith('17', true);
    expect(localStorage.length + sessionStorage.length).toBe(0);
    await user.click(screen.getByRole('button', { name: 'Ya comuniqué el código; ocultar' }));
    expect(screen.queryByText(receipt.codigo_activacion)).not.toBeInTheDocument();
  });
  it.each(['Administrador', 'Médico', 'Paciente'])('rol %s no recibe emisión', async rol => {
    consultarSesion.mockResolvedValue(userData(rol)); mount('/recepcion/activacion-web');
    await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(emitirCodigoActivacion).not.toHaveBeenCalled();
  });
  it('rechazo de ficha sin CI/vinculada conserva estado sin entrega ni detalles internos', async () => {
    emitirCodigoActivacion.mockRejectedValue(error(409, 'ACTIVACION_NO_PERMITIDA'));
    mount('/recepcion/activacion-web'); await screen.findByLabelText('Identificador de la ficha'); field('Identificador de la ficha', '17'); fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('La ficha no admite esta emisión');
    expect(screen.queryByLabelText('Código de activación para entrega presencial')).not.toBeInTheDocument();
  });
  it('abandonar la ruta y volver no recupera el secreto de entrega', async () => {
    emitirCodigoActivacion.mockResolvedValue(receipt);
    const router = mount('/recepcion/activacion-web'); await screen.findByLabelText('Identificador de la ficha'); field('Identificador de la ficha', '17'); fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.submit(screen.getByRole('form')); fireEvent.submit(screen.getByRole('form'));
    await screen.findByLabelText('Código de activación para entrega presencial');
    await act(async () => router.navigate('/inicio'));
    await act(async () => router.navigate(-1));
    await screen.findByLabelText('Identificador de la ficha');
    expect(screen.queryByText(receipt.codigo_activacion)).not.toBeInTheDocument();
    expect(emitirCodigoActivacion).toHaveBeenCalledTimes(1);
  });
});
