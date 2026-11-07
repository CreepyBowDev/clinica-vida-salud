import { render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';
import PrecioPago from '../../components/PrecioPago';
import { precio } from '../utils/pagoFixtures';

it('agrupa BOB, USD y tasa en el mismo orden de lectura, sin controles financieros', () => {
  const { container } = render(<PrecioPago precio={precio} />);
  const list = screen.getByLabelText('Importes históricos');
  expect(list.tagName).toBe('DL');
  expect(within(list).getAllByRole('term').map(t => t.textContent)).toEqual([
    'Monto original', 'Importe al proveedor', 'Tasa académica histórica',
  ]);
  expect(within(list).getAllByRole('definition').map(d => d.textContent)).toEqual([
    'Bs 70,00', 'USD 10,00', '7,00 BOB por USD',
  ]);
  expect(container.querySelectorAll('button, input, a')).toHaveLength(0);
});

it.each([
  ['7.00', '1.00', 'Bs 7,00', 'USD 1,00'],
  ['7000.00', '1000.00', 'Bs 7.000,00', 'USD 1.000,00'],
  ['70.01', '10.00', 'Bs 70,01', 'USD 10,00'],
])('presenta el precio histórico %s sin recalcular USD', (bob, usd, visibleBob, visibleUsd) => {
  render(<PrecioPago precio={{ ...precio, monto_bob: bob, monto_usd: usd }} />);
  expect(screen.getByText(visibleBob)).toBeInTheDocument();
  expect(screen.getByText(visibleUsd)).toBeInTheDocument();
});

it('no representa un importe cero ni un precio cuando no hay asignación', () => {
  const { container } = render(<PrecioPago precio={null} />);
  expect(container).toBeEmptyDOMElement();
});
