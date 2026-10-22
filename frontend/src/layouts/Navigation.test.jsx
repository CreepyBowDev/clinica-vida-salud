import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createMemoryRouter, Link, RouterProvider } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AuthLayout from './AuthLayout';
import MainLayout from './MainLayout';

vi.mock('../hooks/useAuth', () => ({ useAuth: () => ({ usuario: { nombre_visible: 'Persona Ficticia', rol: 'Paciente' }, logout: vi.fn() }) }));
afterEach(() => { document.documentElement.scrollTop = 0; });

describe('navegación accesible entre pantallas', () => {
  it('el salto autenticado enfoca main sin introducir navegación nativa fuera del router', async () => {
    const router = createMemoryRouter([{ element: <MainLayout />, children: [{ path: '/reservar-cita', element: <h1>Reserva</h1> }] }], { initialEntries: ['/reservar-cita'] });
    render(<RouterProvider router={router} />); await screen.findByRole('heading', { name: 'Reserva' });
    const link = screen.getByRole('link', { name: 'Saltar al contenido' }); link.focus();
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    link.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true); expect(screen.getByRole('main')).toHaveFocus();
    expect(router.state.location.pathname).toBe('/reservar-cita'); expect(router.state.location.hash).toBe('');
  });
  it.each([['pública', AuthLayout], ['autenticada', MainLayout]])('%s: al cambiar ruta vuelve arriba y enfoca el contenido', async (_, Layout) => {
    const router = createMemoryRouter([{ element: <Layout />, children: [
      { path: '/origen', element: <><h1>Pantalla de origen</h1><Link to="/destino">Abrir destino</Link></> },
      { path: '/destino', element: <h1>Pantalla de destino</h1> },
    ] }], { initialEntries: ['/origen'] });
    render(<RouterProvider router={router} />);
    await screen.findByRole('heading', { name: 'Pantalla de origen' });
    document.documentElement.scrollTop = 900;
    fireEvent.click(screen.getByRole('link', { name: 'Abrir destino' }));
    await screen.findByRole('heading', { name: 'Pantalla de destino' });
    await waitFor(() => expect(screen.getByRole('main')).toHaveFocus());
    expect(document.documentElement.scrollTop).toBe(0);
    expect(screen.getByRole('link', { name: 'Saltar al contenido' })).toHaveAttribute('href', '#contenido');
    expect(screen.getByRole('main')).toHaveAttribute('tabindex', '-1');
  });
});
