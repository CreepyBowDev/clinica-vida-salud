import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import ReservaHorarioMedico from '../../components/ReservaHorarioMedico';

it('no inventa horarios ante un servidor anterior', () => {
  render(<ReservaHorarioMedico />);
  expect(screen.getByText(/El horario semanal no está disponible/)).toBeInTheDocument();
  expect(screen.getByText(/Elige una fecha/)).toBeInTheDocument();
});

it('distingue ausencia de horarios activos de falta de datos', () => {
  render(<ReservaHorarioMedico horarios={[]} />);
  expect(screen.getByText(/no tiene horarios activos/)).toBeInTheDocument();
  expect(screen.queryByRole('time')).not.toBeInTheDocument();
});

it('ordena los días sin convertir horas locales ni perder segundos', () => {
  const { container } = render(<ReservaHorarioMedico horarios={[
    { dia_semana: 7, hora_inicio: '08:00:01', hora_fin: '09:00:01' },
    { dia_semana: 1, hora_inicio: '14:00:00', hora_fin: '18:00:00' },
  ]} />);
  expect([...container.querySelectorAll('dt')].map(el => el.textContent)).toEqual(['Lunes', 'Domingo']);
  expect([...container.querySelectorAll('time')].map(el => el.textContent)).toEqual(['14:00', '18:00', '08:00:01', '09:00:01']);
  expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
