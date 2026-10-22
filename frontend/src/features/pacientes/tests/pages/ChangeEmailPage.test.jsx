import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api } from '../../../../api/api';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { crearRutas } from '../../../../app/router';
import { consultarSesion, iniciarSesion } from '../../../auth/services/auth.service';
import { solicitarCambioCorreo, reenviarCambioCorreo, verificarCambioCorreo } from '../../services/cambioCorreo.service';
import { consultarMiPerfil } from '../../services/perfil.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/cambioCorreo.service', () => ({ solicitarCambioCorreo: vi.fn(), reenviarCambioCorreo: vi.fn(), verificarCambioCorreo: vi.fn() }));
vi.mock('../../services/perfil.service', () => ({ consultarMiPerfil: vi.fn(), actualizarMiPerfil: vi.fn() }));
const person = { id_usuario: '1', nombre_visible: 'Paciente Ficticio', correo_acceso: 'paciente@example.test', rol: 'Paciente' };
const receipt = { envio: 'aceptado', expira_en: '2026-10-20T12:00:00.123456Z' };
const profile = { id_paciente: '1', codigo_paciente: 'PAC-001', nombres: 'Persona', apellidos: 'Ficticia', fecha_nacimiento: '1990-01-02', activo: true, telefono: '70000000', correo_acceso: person.correo_acceso, actualizado_en: '2026-10-20T11:00:00.123456Z' };
const error = (status, code, details) => ({ response: { status, headers: {}, data: { error: { code, details, message: 'SQL secreto' } } } });
const original = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });
beforeEach(() => {
  vi.resetAllMocks(); consultarSesion.mockResolvedValue(person); consultarMiPerfil.mockResolvedValue(profile);
  solicitarCambioCorreo.mockResolvedValue(receipt); reenviarCambioCorreo.mockResolvedValue(receipt); verificarCambioCorreo.mockResolvedValue();
});
function mount(path = '/mi-perfil/correo') {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] }); render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const field = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
const request = () => fireEvent.submit(screen.getByRole('form', { name: 'Solicitar cambio de correo' }));
const verify = () => fireEvent.submit(screen.getByRole('form', { name: 'Verificar cambio de correo' }));
async function ready() { await screen.findByLabelText('Nuevo correo de acceso'); }
async function prepareRequest() { await ready(); field('Nuevo correo de acceso', 'nuevo@example.test'); field('Contraseña actual', 'Clave ficticia 123'); request(); }

describe('cambio verificado de correo CU-08', () => {
  it('presenta consecuencias de sustitución sin enviar ni habilitar verificación durante revisión', async () => {
    mount(); await prepareRequest();
    expect(screen.getByRole('region', { name: 'Revisa el nuevo correo' })).toHaveTextContent('El correo vigente no cambia');
    expect(screen.getByRole('group', { name: 'Nuevo correo y comprobación de identidad' })).toContainElement(screen.getByLabelText('Contraseña actual'));
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeDisabled();
    expect(solicitarCambioCorreo).not.toHaveBeenCalled(); expect(verificarCambioCorreo).not.toHaveBeenCalled();
  });
  it.each(['Administrador', 'Recepcionista', 'Médico'])('deniega %s sin llamadas al cambio', async rol => {
    consultarSesion.mockResolvedValue({ ...person, rol }); mount(); await screen.findByRole('heading', { name: 'Acceso no permitido' });
    expect(solicitarCambioCorreo).not.toHaveBeenCalled(); expect(reenviarCambioCorreo).not.toHaveBeenCalled(); expect(verificarCambioCorreo).not.toHaveBeenCalled();
  });
  it('enlaza desde Mi perfil y mantiene vigente visible sin editar contacto', async () => {
    const user = userEvent.setup(); mount('/mi-perfil'); await user.click(await screen.findByRole('link', { name: 'Cambiar correo de acceso' })); await ready();
    expect(document.title).toBe('Cambiar correo de acceso | Clínica Vida Salud'); expect(screen.getByText(person.correo_acceso)).toBeInTheDocument();
    expect(screen.queryByLabelText('Correo de contacto (opcional)')).not.toBeInTheDocument();
  });
  it('valida correo distinto/contraseña y exige confirmar sustitución antes de enviar', async () => {
    const user = userEvent.setup(); mount(); await ready(); field('Nuevo correo de acceso', ' PACIENTE@example.test '); request();
    expect(solicitarCambioCorreo).not.toHaveBeenCalled(); expect(screen.getByLabelText('Nuevo correo de acceso')).toHaveAttribute('aria-invalid', 'true');
    field('Nuevo correo de acceso', 'nuevo@example.test'); field('Contraseña actual', 'Clave ficticia 123'); request();
    expect(screen.getByRole('status')).toHaveTextContent('Se sustituirá cualquier solicitud'); expect(solicitarCambioCorreo).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Volver a revisar solicitud' })); expect(screen.getByLabelText('Contraseña actual')).toHaveValue('');
  });
  it.each(['aceptado', 'simulado', 'fallido', 'pendiente'])('muestra envío %s sin inferir entrega, conserva correo y limpia secretos', async envio => {
    solicitarCambioCorreo.mockResolvedValue({ ...receipt, envio }); mount(); await prepareRequest(); request();
    const text = { aceptado: /El proveedor aceptó/, simulado: /Envío simulado/, fallido: /No se confirmó el envío/, pendiente: /El envío está pendiente/ }[envio];
    await screen.findByText(text); expect(screen.getByLabelText('Contraseña actual')).toHaveValue(''); expect(screen.getByText(person.correo_acceso)).toBeInTheDocument();
    expect(solicitarCambioCorreo).toHaveBeenCalledWith({ correo_acceso: 'nuevo@example.test', current_password: 'Clave ficticia 123' });
    expect(screen.getByRole('button', { name: 'Reenvío en 60 s' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toHaveProperty('disabled', envio === 'fallido');
    expect(localStorage.length + sessionStorage.length).toBe(0);
  });
  it('código exige seis dígitos y tras 204 cierra contexto sin /me ni login automático', async () => {
    mount(); await ready(); field('Código de cambio de correo', '123'); verify(); expect(verificarCambioCorreo).not.toHaveBeenCalled();
    field('Código de cambio de correo', '001234'); verify(); await screen.findByRole('form', { name: 'Iniciar sesión' });
    expect(screen.getByText(/Correo de acceso actualizado/)).toBeInTheDocument(); expect(verificarCambioCorreo).toHaveBeenCalledWith({ codigo: '001234' });
    expect(iniciarSesion).not.toHaveBeenCalled(); expect(consultarSesion).toHaveBeenCalledTimes(1); expect(screen.queryByText(person.correo_acceso)).not.toBeInTheDocument();
  });
  it('una comprobación /me anterior no restaura sesión tras el cambio confirmado', async () => {
    mount(); await ready(); let resolve;
    consultarSesion.mockImplementationOnce(() => new Promise(done => { resolve = done; })); fireEvent(window, new Event('focus')); await waitFor(() => expect(consultarSesion).toHaveBeenCalledTimes(2));
    field('Código de cambio de correo', '001234'); verify(); await screen.findByRole('form', { name: 'Iniciar sesión' });
    await act(async () => resolve(person)); expect(screen.getByRole('form', { name: 'Iniciar sesión' })).toBeInTheDocument(); expect(screen.queryByLabelText('Nuevo correo de acceso')).not.toBeInTheDocument();
  });
  it('permite retomar verificación/reenvío sin tener destino almacenado al remontar', async () => {
    const user = userEvent.setup(); const router = mount(); await ready();
    await user.click(screen.getByRole('button', { name: 'Solicitar otro código de cambio' })); await screen.findByText(/El proveedor aceptó/); expect(reenviarCambioCorreo).toHaveBeenCalledWith();
    await act(() => router.navigate('/inicio')); await act(() => router.navigate('/mi-perfil/correo')); await ready();
    expect(screen.getByLabelText('Nuevo correo de acceso')).toHaveValue(''); expect(screen.queryByText(/El proveedor aceptó/)).not.toBeInTheDocument();
    field('Código de cambio de correo', '001234'); verify(); await screen.findByRole('form', { name: 'Iniciar sesión' });
  });
  it.each([409, 419, 422, 403])('solicitud rechazada %s mantiene correo/destino, limpia contraseña y no reenvía', async status => {
    solicitarCambioCorreo.mockRejectedValue(error(status, status === 409 ? 'CAMBIO_CORREO_NO_DISPONIBLE' : 'AUTH_CLAVE_ACTUAL_INVALIDA'));
    mount(); await prepareRequest(); request(); await screen.findByRole('alert');
    expect(screen.getByLabelText('Contraseña actual')).toHaveValue(''); expect(screen.getByLabelText('Nuevo correo de acceso')).toHaveValue('nuevo@example.test');
    expect(screen.getByText(person.correo_acceso)).toBeInTheDocument(); expect(solicitarCambioCorreo).toHaveBeenCalledTimes(1); expect(document.body.textContent).not.toContain('SQL secreto');
  });
  it('código rechazado no distingue vencido/agotado/inválido, limpia código y permite nuevo intento manual', async () => {
    verificarCambioCorreo.mockRejectedValue(error(422, 'CAMBIO_CORREO_INVALIDO')); mount(); await ready(); field('Código de cambio de correo', '001234'); verify();
    await screen.findByRole('alert'); expect(screen.getByLabelText('Código de cambio de correo')).toHaveValue(''); expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeEnabled();
    expect(verificarCambioCorreo).toHaveBeenCalledTimes(1); expect(screen.queryByText(/Correo de acceso actualizado/)).not.toBeInTheDocument();
  });
  it('429 respeta Retry-After y no permite reenvío/confirmación durante la espera', async () => {
    const failure = error(429); failure.response.headers['retry-after'] = '90'; reenviarCambioCorreo.mockRejectedValue(failure); const user = userEvent.setup(); mount(); await ready();
    await user.click(screen.getByRole('button', { name: 'Solicitar otro código de cambio' })); await screen.findByRole('button', { name: 'Reenvío en 90 s' });
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeDisabled(); field('Código de cambio de correo', '001234'); verify(); expect(verificarCambioCorreo).not.toHaveBeenCalled();
  });
  it('bloquea operaciones simultáneas y no publica respuestas tardías al abandonar la ruta', async () => {
    let resolve; solicitarCambioCorreo.mockImplementation(() => new Promise(done => { resolve = done; })); const router = mount(); await prepareRequest(); request(); request();
    expect(solicitarCambioCorreo).toHaveBeenCalledTimes(1); expect(screen.getByRole('button', { name: 'Solicitar otro código de cambio' })).toBeDisabled();
    await act(() => router.navigate('/inicio')); await act(async () => resolve(receipt)); expect(screen.queryByText(/El proveedor aceptó/)).not.toBeInTheDocument();
  });
  it.each(['request', 'verify', 'resend'])('resultado incierto de %s bloquea escrituras hasta consulta explícita', async action => {
    const user = userEvent.setup(); const target = { request: solicitarCambioCorreo, verify: verificarCambioCorreo, resend: reenviarCambioCorreo }[action]; target.mockRejectedValue(new Error('timeout'));
    mount(); await ready(); if (action === 'request') { await prepareRequest(); request(); } else if (action === 'verify') { field('Código de cambio de correo', '001234'); verify(); } else await user.click(screen.getByRole('button', { name: 'Solicitar otro código de cambio' }));
    await screen.findByRole('button', { name: 'Comprobar mi sesión y perfil' });
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeDisabled(); expect(screen.getByRole('button', { name: 'Solicitar otro código de cambio' })).toBeDisabled(); expect(consultarMiPerfil).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Comprobar mi sesión y perfil' })); await screen.findByText(/Se pudo consultar tu perfil/);
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeEnabled(); expect(target).toHaveBeenCalledTimes(1);
  });
  it('fallo al comprobar resultado incierto conserva bloqueo y no declara cambio', async () => {
    const user = userEvent.setup(); verificarCambioCorreo.mockRejectedValue(new Error('timeout')); consultarMiPerfil.mockRejectedValue(error(500)); mount(); await ready(); field('Código de cambio de correo', '001234'); verify();
    await user.click(await screen.findByRole('button', { name: 'Comprobar mi sesión y perfil' })); await screen.findByRole('alert');
    expect(screen.getByRole('button', { name: 'Verificar y cambiar mi correo' })).toBeDisabled(); expect(verificarCambioCorreo).toHaveBeenCalledTimes(1);
  });
  it('pagehide limpia contraseña, código y confirmación', async () => {
    mount(); await prepareRequest(); field('Código de cambio de correo', '001234'); fireEvent(window, new Event('pagehide'));
    expect(screen.getByLabelText('Contraseña actual')).toHaveValue(''); expect(screen.getByLabelText('Código de cambio de correo')).toHaveValue(''); expect(screen.queryByRole('button', { name: 'Confirmar solicitud de cambio' })).not.toBeInTheDocument();
  });
  it('integra solicitud 202 → código 204 con Axios real, cookies, secreto limpio y sesión retirada', async () => {
    const actual = await vi.importActual('../../services/cambioCorreo.service'); solicitarCambioCorreo.mockImplementation(actual.solicitarCambioCorreo); verificarCambioCorreo.mockImplementation(actual.verificarCambioCorreo);
    const calls = []; api.defaults.adapter = async config => { calls.push(config); return { config, headers: {}, status: config.url.endsWith('/verificar') ? 204 : config.method === 'post' ? 202 : 204, data: { data: receipt } }; };
    mount(); await prepareRequest(); request(); await screen.findByText(/El proveedor aceptó/); field('Código de cambio de correo', '001234'); verify();
    await screen.findByRole('form', { name: 'Iniciar sesión' }); expect(screen.getByText(/Correo de acceso actualizado/)).toBeInTheDocument();
    expect(calls.filter(c => c.method === 'post').map(c => [c.url, JSON.parse(c.data)])).toEqual([['/mi-perfil/correo', { correo_acceso: 'nuevo@example.test', current_password: 'Clave ficticia 123' }], ['/mi-perfil/correo/verificar', { codigo: '001234' }]]);
    expect(iniciarSesion).not.toHaveBeenCalled(); expect(localStorage.length + sessionStorage.length).toBe(0);
  });
  it('401 real al verificar retira sesión sin éxito falso', async () => {
    const actual = await vi.importActual('../../services/cambioCorreo.service'); verificarCambioCorreo.mockImplementation(actual.verificarCambioCorreo);
    api.defaults.adapter = async config => { if (config.method === 'post') throw new AxiosError('rechazo', undefined, config, null, { status: 401, headers: {}, data: {} }); return { config, status: 204, headers: {}, data: null }; };
    mount(); await ready(); field('Código de cambio de correo', '001234'); verify(); await screen.findByRole('form', { name: 'Iniciar sesión' });
    expect(screen.queryByText(/Correo de acceso actualizado/)).not.toBeInTheDocument(); expect(screen.queryByText(person.correo_acceso)).not.toBeInTheDocument();
  });
});
