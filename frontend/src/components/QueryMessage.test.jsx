import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import QueryMessage from './QueryMessage';

it('la carga anuncia texto sin encabezado de vacío o contenido previo', () => {
  render(<QueryMessage loading title="Consultando registros…" />);
  expect(screen.getByRole('status')).toHaveTextContent('Consultando registros…'); expect(screen.queryByRole('heading')).not.toBeInTheDocument();
});
it('el vacío presenta título y orientación sin fingir error ni acciones', () => {
  render(<QueryMessage title="Sin registros" description="Prueba otro filtro." />);
  expect(screen.getByRole('heading', { level: 2, name: 'Sin registros' })).toBeInTheDocument();
  expect(screen.getByText('Prueba otro filtro.')).toBeInTheDocument(); expect(screen.queryByRole('alert')).not.toBeInTheDocument(); expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
it('conserva texto largo y clases del consumidor sin descripción obligatoria', () => {
  const { container } = render(<QueryMessage title={'Título '.repeat(30)} className="catalog-empty" />);
  expect(container.firstChild).toHaveClass('query-message', 'catalog-empty'); expect(screen.getByRole('heading').textContent.length).toBeGreaterThan(100);
});
