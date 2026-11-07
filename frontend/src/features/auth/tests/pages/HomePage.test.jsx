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
    expect(within(available).getAllByRole('heading', { level: 3 }).map(heading => heading.textContent)).toEqual(navigationForRole(role).map(module => module.label));
    expect(within(available).getAllByRole('link').map(link => link.textContent)).toEqual(expected.map(item => item.label));
    expect(available).not.toHaveTextContent(/pendiente|próximamente|CU-\d/i);
    const pending = screen.queryByRole('region', { name: 'Próximos módulos' });
    const expectedPending = navigationForRole(role).filter(module => module.items.some(item => item.kind === 'pending'));
    if (expectedPending.length) {
      expect(pending).toBeVisible();
      expect(within(pending).queryByRole('link')).not.toBeInTheDocument();
      expect(pending).toHaveTextContent('Pendiente');
      expect(within(pending).getAllByRole('listitem')).toHaveLength(expectedPending.length);
      for (const module of expectedPending) expect(pending).toHaveTextContent(module.label);
    } else {
      expect(pending).not.toBeInTheDocument();
      expect(screen.queryByText('Pendiente')).not.toBeInTheDocument();
    }
    if ([ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].includes(role)) {
      expect(available).toHaveTextContent('Consultar el historial y seguimiento de citas');
      if (pending) expect(pending).not.toHaveTextContent('Consultar el historial y seguimiento de citas');
      for (const label of ['Gestionar el registro administrativo de pacientes', 'Activación web', 'Buscar médicos, consultar disponibilidad y programar una cita presencial']) expect(within(available).getByRole('link', { name: label })).toBeVisible();
    }
  });
});
