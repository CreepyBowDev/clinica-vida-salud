import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import HomePage from '../../pages/HomePage';
import { navigationForRole } from '../../../../constants/navigation';
import { ROLES } from '../../../../constants/roles';

const auth = vi.hoisted(() => ({ usuario: null }));
vi.mock('../../../../hooks/useAuth', () => ({ useAuth: () => auth }));

describe('inicio actualizado por rol', () => {
  it.each(Object.values(ROLES).flatMap(role => [false, true].map(area => [role, area])))('%s, área=%s distingue disponibles de pendientes', (role, area) => {
    auth.usuario = { nombre_visible: 'Persona Ficticia', rol: role, correo_acceso: 'persona@example.test' };
    render(<MemoryRouter><HomePage area={area} /></MemoryRouter>);
    const available = screen.getByRole('region', { name: 'Funciones disponibles' });
    const expected = navigationForRole(role).flatMap(module => module.items).filter(item => item.kind === 'page');
    expect(within(available).getAllByRole('link').map(link => link.getAttribute('href'))).toEqual(expected.map(item => item.to));
    expect(available).not.toHaveTextContent(/pendiente|próximamente|CU-\d/i);
    const pending = screen.getByRole('region', { name: 'Próximos módulos' });
    expect(within(pending).queryByRole('link')).not.toBeInTheDocument();
    expect(pending).toHaveTextContent('Pendiente');
  });
});
