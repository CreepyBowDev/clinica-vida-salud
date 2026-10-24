import { fireEvent, render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../context/AuthContext';
import { consultarSesion } from '../../services/auth.service';
import { cambiarClave, restablecerClave, completarClaveTemporal } from '../../services/password.service';
import { registrarPaciente, vincularPaciente } from '../../services/registration.service';
import { aceptarInvitacion } from '../../services/invitation.service';

vi.mock('../../services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
vi.mock('../../services/password.service', () => ({ cambiarClave: vi.fn(), restablecerClave: vi.fn(), completarClaveTemporal: vi.fn(), solicitarRecuperacion: vi.fn() }));
vi.mock('../../services/registration.service', () => ({ registrarPaciente: vi.fn(), vincularPaciente: vi.fn() }));
vi.mock('../../services/invitation.service', () => ({ aceptarInvitacion: vi.fn() }));

const flows = [
  ['/registro', 'Contraseña'], ['/vincular-ficha', 'Contraseña'],
  ['/activar-personal', 'Nueva contraseña'], ['/recuperar-clave', 'Nueva contraseña'],
  ['/mi-cuenta/contrasena', 'Nueva contraseña'], ['/clave-obligatoria', 'Nueva contraseña'],
];
const invalid = ['corta', 'frase ficticia 123!', 'FRASE FICTICIA 123!', 'Frase ficticia larga!', ' Frase ficticia 123 '];

beforeEach(() => vi.resetAllMocks());

describe.each(flows)('política en %s', (path, label) => {
  it.each(invalid)('no envía la contraseña incompleta aunque se fuerce submit: %s', async password => {
    if (path === '/mi-cuenta/contrasena') consultarSesion.mockResolvedValue({ id_usuario: '25', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol: 'Paciente' });
    else if (path === '/clave-obligatoria') consultarSesion.mockResolvedValue({ acceso: 'cambio_clave', cambio_clave_obligatorio: true });
    else consultarSesion.mockRejectedValue({ response: { status: 401 } });
    const router = createMemoryRouter(crearRutas({ token: 'a'.repeat(64) }, { token: 'b'.repeat(64) }), { initialEntries: [path] });
    render(<AuthProvider><RouterProvider router={router} /></AuthProvider>);
    const input = await screen.findByLabelText(label);
    expect(screen.getByRole('list', { name: 'Requisitos de la contraseña' })).toBeInTheDocument();
    fireEvent.change(input, { target: { value: password } });
    fireEvent.change(screen.getByLabelText(label === 'Contraseña' ? 'Confirmar contraseña' : 'Confirmar nueva contraseña'), { target: { value: password } });
    fireEvent.submit(screen.getByRole('form'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Revisa los campos');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    for (const service of [cambiarClave, restablecerClave, completarClaveTemporal, registrarPaciente, vincularPaciente, aceptarInvitacion]) expect(service).not.toHaveBeenCalled();
  });
});
